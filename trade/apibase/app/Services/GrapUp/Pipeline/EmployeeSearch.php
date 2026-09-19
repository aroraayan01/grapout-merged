<?php

namespace App\Services\GrapUp\Pipeline;

use App\Services\GrapUp\Contact;
use App\Services\GrapUp\Domain\CompanyMatch;
use App\Services\GrapUp\Domain\Parse;
use App\Services\GrapUp\Domain\Seniority;
use App\Services\GrapUp\Domain\Text;
use App\Services\GrapUp\PersonName;
use App\Services\GrapUp\Roles;
use App\Services\GrapUp\Serper;
use Illuminate\Support\Facades\Log;

/**
 * Sourcing employees for a company, optionally filtered to a department.
 *
 * Contacts come from parsed search-result titles. Whatever comes out of here
 * is what verification credits get spent on, so the parsing is strict: a title
 * that is not a name yields nothing rather than a contact whose address will
 * be paid for and cannot exist.
 *
 * This step, not verification, is what actually caps the product. Measured
 * over twenty companies, eight returned three people or fewer and one returned
 * none — and in most cases the people were on Google the whole time, behind a
 * query nobody was asking. So the search runs from two independent angles and
 * keeps widening while the yield is thin:
 *
 *  1. **The company's domain.** People put their employer's website in their
 *     profile, and a domain is unambiguous where a name is not. The only angle
 *     that works for a company named after a person: every Dutchman called
 *     Andries de Jong matches the name, and none match andriesdejong.nl.
 *  2. **The company's name**, widened in steps.
 *
 * A search engine is cheap and a verification credit is not, so it is worth
 * several queries to avoid spending twenty credits on the only person a single
 * query happened to return.
 *
 * Ported from GrapUp's `pipeline/employees.ts`.
 */
class EmployeeSearch
{
    public function __construct(private readonly Serper $serper) {}

    /** @return array{contacts: list<Contact>, matchedAs: string, searches: int} */
    public function find(string $companyName, string $country, string $domain, string $targetRole, ?string $gl): array
    {
        $roleModifier = Roles::query($targetRole);
        $variants = CompanyMatch::nameVariants($companyName);

        /*
         * De-duplicated on profile URL. Matching on first name would drop a
         * genuinely different person who happens to share another contact's
         * first name, which is common enough to lose real leads.
         */
        $seen = [];
        $contacts = [];
        $searches = 0;
        $matchedAs = null;

        $ask = function (string $query) use ($gl, &$searches): array {
            $searches++;

            return $this->serper->search(trim(preg_replace('/\s+/u', ' ', $query) ?? ''), $gl);
        };

        $collect = function (array $results, string $judgeAgainst) use (&$seen, &$contacts, $domain, $companyName): int {
            $added = 0;

            foreach ($results as $result) {
                if ($result['link'] === '' || isset($seen[$result['link']])) {
                    continue;
                }

                $name = PersonName::fromTitle($result['title']);
                if ($name === null || ! $name->isUsable()) {
                    continue;
                }

                $seen[$result['link']] = true;
                $added++;

                $contacts[] = new Contact(
                    firstName: $name->first,
                    lastName: $name->last,
                    // A placeholder until verification assigns the real one.
                    // The walk clears it before spending anything.
                    email: Parse::makeEmail(PersonName::normalizePart($name->first), $domain),
                    status: 'locked',
                    headline: Text::role($result['title'], $result['snippet'], $companyName),
                    linkedinUrl: $result['link'],
                    /*
                     * Two things have to hold, and widening made the second
                     * necessary.
                     *
                     * First, the current role has to name the company. Only
                     * the current role, and only from the title: the snippet
                     * carries employment history, and Melanie Walton's named a
                     * former employer under "Experience" while she worked
                     * somewhere else. Not the whole title either — dropping
                     * the first segment drops the person's own name with it,
                     * which is what stopped every man called Andries de Jong
                     * from corroborating Andries de Jong B.V.
                     *
                     * Second, the name that found them has to belong to the
                     * domain we are about to spend credits at. Widening to a
                     * bracketed parent found five real people at Execula — and
                     * then offered them addresses at a different company's
                     * domain. Being a confirmed employee of the parent is no
                     * reason to buy permutations at the subsidiary.
                     */
                    corroborated: CompanyMatch::mentionsCompany(Text::currentRoleText($result['title']), $judgeAgainst)
                        && CompanyMatch::domainMatches($domain, $judgeAgainst),
                );
            }

            return $added;
        };

        // ─── Angle one: the domain ──────────────────────────────────────────
        /*
         * Run first, because it is the most precise question available and the
         * only one immune to how the company writes its name. Measured on
         * Coastal Specialist Ironmongery, whose name search returned one
         * person: searching for coastal-group.com returned four, including the
         * Managing Director.
         */
        $added = $collect($ask("site:linkedin.com/in \"{$domain}\" {$roleModifier}"), $variants[0] ?? $companyName);
        if ($added > 0) {
            Log::debug('grapup: profiles naming the company domain', ['domain' => $domain, 'added' => $added]);
        }

        // ─── Angle two: the name, widening while the yield is thin ──────────
        /*
         * The old loop stopped at the first variant that returned *anything*,
         * which is how Coastal came back with a single contact: the registered
         * name matched one profile, so the shorter form that matches five was
         * never tried. One result is not a successful search, it is a thin one.
         */
        $enough = (int) config('grapup.sourcing.enough_contacts', 8);
        $maxSearches = (int) config('grapup.sourcing.max_searches', 5);

        foreach ($variants as $variant) {
            if (count($contacts) >= $enough || $searches >= $maxSearches) {
                break;
            }

            $found = $collect(
                $ask("site:linkedin.com/in \"{$variant}\" {$country} {$roleModifier}"),
                $variant,
            );
            if ($found > 0 && $matchedAs === null) {
                $matchedAs = $variant;
            }
        }

        if ($matchedAs !== null && $matchedAs !== $companyName) {
            // Worth surfacing: a broader name can pull in a different company
            // that happens to share the leading words.
            Log::info('grapup: matched on a shortened name', ['full' => $companyName, 'as' => $matchedAs]);
        }

        /*
         * Confirmed employees first, then the most senior of them.
         *
         * Order here is order of spend downstream, and the ranking a search
         * engine returns is relevance, not employment — nor likelihood of
         * holding a personal mailbox. Pardaen returned a buyer and a Managing
         * Director, both confirmed; the engine ranked the buyer first, the
         * walk spent its whole rare-pattern budget proving he has no mailbox,
         * and never reached jd@pardaen.be.
         *
         * Corroboration outranks seniority: a stranger claiming to be a CEO is
         * still a stranger. usort is not stable across all PHP builds for
         * equal elements, so the original index is the final tie-break and the
         * engine's own ranking survives.
         */
        $indexed = array_values($contacts);
        $order = array_flip(array_map('spl_object_id', $indexed));

        usort($indexed, fn (Contact $a, Contact $b) => ((int) $b->corroborated <=> (int) $a->corroborated)
            ?: (Seniority::rank($a->headline) <=> Seniority::rank($b->headline))
            ?: ($order[spl_object_id($a)] <=> $order[spl_object_id($b)]));

        $confirmed = count(array_filter($indexed, fn (Contact $c) => $c->corroborated));

        Log::info('grapup: employees sourced', [
            'company' => $companyName,
            'role' => $targetRole === '' ? 'all' : $targetRole,
            'found' => count($indexed),
            'naming_the_company' => $confirmed,
            'searches' => $searches,
        ]);

        if ($indexed !== [] && $confirmed === 0) {
            // Not fatal, but it is the shape of the run that wasted forty
            // credits on two strangers, so it gets a line of its own.
            Log::warning('grapup: no profile named the company — nobody here is a confirmed employee', [
                'company' => $companyName, 'found' => count($indexed),
            ]);
        }

        return ['contacts' => $indexed, 'matchedAs' => $matchedAs ?? $companyName, 'searches' => $searches];
    }
}
