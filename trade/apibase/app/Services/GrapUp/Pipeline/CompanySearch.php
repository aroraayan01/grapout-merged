<?php

namespace App\Services\GrapUp\Pipeline;

use App\Models\User;
use App\Services\GrapUp\Contact;
use App\Services\GrapUp\Domain\CompanyMatch;
use App\Services\GrapUp\Domain\Countries;
use App\Services\GrapUp\Verifier\Verifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One company in, its people out.
 *
 * The whole pipeline, in the order that keeps each step from paying for the
 * next: cache, domain, generic contacts, team, verification.
 *
 * Ported from GrapUp's `pipeline/search.ts`.
 */
class CompanySearch
{
    public function __construct(
        private readonly DomainDiscovery $discovery,
        private readonly ContactWaterfall $waterfall,
        private readonly EmployeeSearch $employees,
        private readonly VerificationWalk $walk,
    ) {}

    public function run(
        string $companyName,
        string $country = '',
        string $targetRole = '',
        ?User $user = null,
        bool $refresh = false,
        ?int $maxContacts = null,
    ): array {
        $companyName = trim($companyName);
        $country = trim($country);
        $targetRole = trim($targetRole);

        // The searcher's cap on how many people to return. 0 means no cap —
        // the whole team. Kept as a plain int so it can key the cache, where a
        // top-2 search and a top-8 search are different questions.
        $cap = $maxContacts !== null && $maxContacts > 0 ? $maxContacts : 0;

        // 0. The same question, asked before. Free, and the commonest case in
        //    a book of companies where several people search the same firm.
        if (! $refresh) {
            $cached = $this->fromCache($companyName, $country, $targetRole, $cap);
            if ($cached !== null) {
                return $cached + ['cached' => true];
            }
        }

        $gl = Countries::code($country);

        // 1. The company's own website among the search results.
        $discovery = $this->discovery->find($companyName, $country, $gl);

        // 2. Generic contact details, which may also correct or supply the domain.
        $details = $this->waterfall->run($companyName, $discovery['domain'], $gl);

        // A guess only when nothing corroborated one. The MX check downstream
        // discards it for free when it does not resolve, which is most of the
        // time.
        $websiteDomain = $details['domain'] ?? DomainDiscovery::guess($companyName);

        if ($websiteDomain === null) {
            Log::warning('grapup: no usable domain at all, nothing to verify', ['company' => $companyName]);

            return $this->finish($companyName, $country, $targetRole, $cap, [
                'company' => ['name' => $companyName, 'domain' => null, 'email' => $details['email'], 'phone' => $details['phone']],
                'contacts' => [], 'corporate_format' => null,
                'verification_unavailable' => false, 'credits_spent' => 0, 'credits_used' => [],
            ]);
        }

        $domain = $this->chooseMailDomain($companyName, $websiteDomain, $details);

        // 3 & 4. Source the team, verify, propagate the pattern.
        //
        // Capped here, before verification, so the cap saves credits and not
        // only screen space: the team is already ranked confirmed-then-senior,
        // so the top $cap are the ones worth paying for.
        $sourced = $this->employees->find($companyName, $country, $domain, $targetRole, $gl);
        if ($cap > 0) {
            $sourced['contacts'] = array_slice($sourced['contacts'], 0, $cap);
        }

        $pass = $this->walk->verifyAndPropagate(
            $sourced['contacts'], $domain, Verifier::CONTEXT_SEARCH, $user, $companyName,
        );
        $creditsSpent = $pass->creditsSpent;
        $creditsUsed = $pass->calls;
        $contacts = $pass->contacts;

        /*
         * A vendor that stopped answering is not evidence the domain was
         * wrong, and the retry would spend a second budget on the same failing
         * checks.
         */
        if (! $pass->vendorUnavailable && $pass->noneVerified() && $domain !== $websiteDomain) {
            Log::info('grapup: nothing verified, retrying against the website domain', ['domain' => $websiteDomain]);

            $retry = $this->employees->find($companyName, $country, $websiteDomain, $targetRole, $gl);
            if ($cap > 0) {
                $retry['contacts'] = array_slice($retry['contacts'], 0, $cap);
            }
            $pass = $this->walk->verifyAndPropagate(
                $retry['contacts'], $websiteDomain, Verifier::CONTEXT_SEARCH, $user, $companyName,
            );

            $creditsSpent += $pass->creditsSpent;
            // The retry is spend on the same search, so it adds up.
            foreach ($pass->calls as $provider => $made) {
                $creditsUsed[$provider] = ($creditsUsed[$provider] ?? 0) + $made;
            }
            $contacts = $pass->contacts;
            $domain = $websiteDomain;
        }

        return $this->finish($companyName, $country, $targetRole, $cap, [
            'company' => ['name' => $companyName, 'domain' => $domain, 'email' => $details['email'], 'phone' => $details['phone']],
            'contacts' => array_map(fn (Contact $c) => $c->toArray(), $contacts),
            'corporate_format' => $pass->corporateFormat,
            'verification_unavailable' => $pass->vendorUnavailable,
            'credits_spent' => $creditsSpent,
            /*
             * Calls made to each provider, e.g. ['inboxx' => 7, 'clearout' => 2].
             * Kept in the cached payload as what the search originally cost;
             * a cached answer itself costs nothing, and the UI says so.
             */
            'credits_used' => $creditsUsed,
        ]);
    }

    /**
     * Firms often send mail from a different domain than they serve the site
     * from — info@acme-group.com against acme.com. Prefer the mail domain,
     * but only when it plausibly belongs to this company.
     *
     * The last tier of the waterfall searches the company *name*, so it can
     * pick up a completely unrelated firm's address — and adopting that hands
     * the whole verification budget to somebody else's company. Seen live:
     * "THILLAI SUPER MARKET PTE. LTD" resolved correctly to
     * thillaisupermarket.com, then adopted cybex.in from a stray result.
     *
     * The name test only counts when the name can actually be tested.
     * `domainMatches` is permissive on purpose — it will not block on a name
     * it cannot read — and that default is right when discovering a domain and
     * wrong here, because "cannot test" is not evidence in favour of a
     * stranger. Seen live: "B&Q Limited" reduces to no testable word at all,
     * the permissive answer waved a paint manufacturer through, and twenty
     * credits went to it.
     */
    private function chooseMailDomain(string $companyName, string $websiteDomain, array &$details): string
    {
        $scraped = ContactWaterfall::corporateDomainOf($details['email']);

        $nameCanVouch = CompanyMatch::hasTestableIdentity($companyName)
            && $scraped !== null
            && CompanyMatch::domainMatches($scraped, $companyName);

        $mailDomain = $scraped !== null
            && ($scraped === $websiteDomain || $nameCanVouch || CompanyMatch::sharesIdentity($scraped, $websiteDomain))
            ? $scraped
            : null;

        if ($scraped !== null && $mailDomain === null) {
            Log::warning('grapup: discarding a scraped address that belongs to a different company', [
                'company' => $companyName, 'found' => $details['email'], 'expected' => $websiteDomain,
            ]);
            // Not just unused — removed. Presenting a stranger's address as
            // this company's contact detail is worse than presenting nothing.
            $details['email'] = null;
        }

        if ($mailDomain !== null && $mailDomain !== $websiteDomain) {
            Log::info('grapup: preferring the mail domain', ['mail' => $mailDomain, 'website' => $websiteDomain]);
        }

        return $mailDomain ?? $websiteDomain;
    }

    private function fromCache(string $companyName, string $country, string $targetRole, int $maxContacts): ?array
    {
        $row = DB::table('grap_search_cache')
            ->where('company_name', $companyName)
            ->where('country', $country)
            ->where('target_role', $targetRole)
            ->where('max_contacts', $maxContacts)
            ->where('updated_at', '>=', now()->subDays((int) config('grapup.cache_ttl_days')))
            ->first();

        return $row === null ? null : json_decode($row->payload, true);
    }

    /**
     * Cache the payload, unless nothing was actually checked.
     *
     * A run that found no domain, or that died on a vendor outage, has not
     * learned anything about the company — caching it would make a bad
     * afternoon permanent.
     */
    private function finish(string $companyName, string $country, string $targetRole, int $maxContacts, array $payload): array
    {
        $worthKeeping = $payload['company']['domain'] !== null && ! $payload['verification_unavailable'];

        if ($worthKeeping) {
            try {
                DB::table('grap_search_cache')->updateOrInsert(
                    ['company_name' => $companyName, 'country' => $country, 'target_role' => $targetRole, 'max_contacts' => $maxContacts],
                    ['payload' => json_encode($payload), 'updated_at' => now(), 'created_at' => now()],
                );
            } catch (\Throwable $e) {
                Log::warning('grapup: could not cache the search', ['error' => $e->getMessage()]);
            }
        }

        return $payload + ['cached' => false];
    }
}
