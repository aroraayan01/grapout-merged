<?php

namespace App\Services\GrapUp\Pipeline;

use App\Services\GrapUp\Domain\Parse;
use App\Services\GrapUp\Domain\Text;
use App\Services\GrapUp\Serper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The contact waterfall: four tiers, stopping as soon as it has both an email
 * and a phone.
 *
 * Ordered by cost and by how much the answer can be trusted. The
 * domain-scoped tiers come first because a result from the company's own site
 * is the company's; the name-scoped tier is last because it is the one that
 * can pick up somebody else's details.
 *
 * Ported from GrapUp's `pipeline/contacts.ts`.
 */
class ContactWaterfall
{
    public function __construct(private readonly Serper $serper) {}

    /** @return array{email: ?string, phone: ?string, domain: ?string} */
    public function run(string $companyName, ?string $domain, ?string $gl): array
    {
        $email = null;
        $phone = null;
        $resolvedDomain = $domain;

        $tiers = [];

        if ($domain !== null) {
            // A: the company's own site, scoped to pages carrying contact details.
            $tiers['site-scoped search'] = fn () => $this->fromSnippets(
                $this->serper->search("site:{$domain} contact OR phone OR email", $gl),
            );

            // B: anywhere on the web that quotes an address at this domain.
            $tiers['address mentions'] = fn () => $this->fromSnippets(
                $this->serper->search("\"@{$domain}\"", $gl),
            );

            // C: the homepage itself. The only tier that costs a page fetch,
            // and the only one that can see through Cloudflare's mailto
            // obfuscation — the sole address plenty of sites expose.
            $tiers['homepage'] = fn () => $this->fromHomepage($domain);
        }

        // D: the company name rather than the domain. Last, because it is the
        // tier that can return a different company's details.
        $tiers['company name search'] = fn () => $this->fromSnippets(
            $this->serper->search("\"{$companyName}\" contact email phone", $gl),
        );

        foreach ($tiers as $name => $run) {
            if ($email !== null && $phone !== null) {
                break;
            }

            $found = $run();
            if ($email === null && $found['email'] !== null) {
                $email = $found['email'];
                Log::debug('grapup: email found', ['tier' => $name]);
            }
            if ($phone === null && $found['phone'] !== null) {
                $phone = $found['phone'];
                Log::debug('grapup: phone found', ['tier' => $name]);
            }
        }

        // An address on a usable corporate domain is better evidence of where
        // the company lives than a search result link was.
        if ($email !== null && $resolvedDomain === null) {
            $fromEmail = Parse::emailDomain($email);
            if (Parse::isUsableCorporateDomain($fromEmail)) {
                $resolvedDomain = $fromEmail;
            }
        }

        return ['email' => $email, 'phone' => $phone, 'domain' => $resolvedDomain];
    }

    /** @return array{email: ?string, phone: ?string} */
    private function fromSnippets(array $results): array
    {
        $text = implode(' ', array_map(fn ($r) => "{$r['title']} {$r['snippet']}", $results));

        return [
            'email' => Text::pickBestEmail(Text::emails($text)),
            'phone' => Text::phones($text)[0] ?? null,
        ];
    }

    /** @return array{email: ?string, phone: ?string} */
    private function fromHomepage(string $domain): array
    {
        try {
            $response = Http::timeout((int) config('grapup.scrape.timeout_seconds', 10))
                ->withHeaders(['user-agent' => 'Mozilla/5.0 (compatible; GrapOut/1.0)'])
                ->get("https://{$domain}/");

            $html = $response->successful() ? $response->body() : '';
        } catch (\Throwable) {
            // A site that will not load is a tier that found nothing, not an
            // error the search should die of.
            $html = '';
        }

        if ($html === '') {
            return ['email' => null, 'phone' => null];
        }

        $text = Text::htmlToText($html);
        $addresses = [...Text::cloudflareEmails($html), ...Text::emails($text)];

        return [
            'email' => Text::pickBestEmail($addresses),
            // Phones are read from the rendered text only: asset URLs in raw
            // markup parse as perfectly plausible ten-digit numbers.
            'phone' => Text::phones($text)[0] ?? null,
        ];
    }

    /** The corporate domain an address implies, if it is one we can build on. */
    public static function corporateDomainOf(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $domain = Parse::domain(Parse::emailDomain($email));

        return Parse::isUsableCorporateDomain($domain) ? $domain : null;
    }
}
