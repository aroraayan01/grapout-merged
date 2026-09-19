<?php

namespace App\Services\GrapUp\Pipeline;

use App\Services\GrapUp\Domain\CompanyMatch;
use App\Services\GrapUp\Domain\Parse;
use App\Services\GrapUp\Lists;
use App\Services\GrapUp\PersonName;
use App\Services\GrapUp\Serper;
use Illuminate\Support\Facades\Log;

/**
 * Finding the company's own website among the search results.
 *
 * Returns null when nothing corroborates, which makes the contact waterfall
 * skip its domain-scoped tiers rather than mine an unrelated site. A wrong
 * answer here is more expensive than no answer: accepting a directory as the
 * company's own site points the whole waterfall at that directory, which then
 * yields some other company's phone and email, presented as this company's.
 *
 * Ported from GrapUp's `pipeline/domain-discovery.ts`.
 */
class DomainDiscovery
{
    public function __construct(private readonly Serper $serper) {}

    /** @return array{domain: ?string, rejected: list<string>} */
    public function find(string $companyName, string $country, ?string $gl): array
    {
        $results = $this->serper->search(trim("{$companyName} {$country}"), $gl);
        $target = PersonName::normalizePart($companyName);
        $rejected = [];

        foreach ($results as $result) {
            $link = mb_strtolower($result['link']);
            if ($link === '') {
                continue;
            }

            // The blacklist yields to the company name being in the URL
            // itself: a firm really can be listed at its own name on a
            // marketplace host.
            $blacklisted = false;
            foreach (Lists::DOMAIN_BLACKLIST as $bad) {
                if (str_contains($link, $bad) && ! str_contains($link, $target)) {
                    $blacklisted = true;
                    break;
                }
            }
            if ($blacklisted || CompanyMatch::isGovernmentSite($link)) {
                continue;
            }

            $domain = Parse::domain($link);
            if ($domain === null || Lists::has(Lists::PLACEHOLDER_DOMAINS, $domain)) {
                continue;
            }

            if (! CompanyMatch::domainMatches($domain, $companyName)) {
                $rejected[] = $domain;
                continue;
            }

            Log::info('grapup: domain found', ['company' => $companyName, 'domain' => $domain]);

            return ['domain' => $domain, 'rejected' => $rejected];
        }

        Log::warning(
            $rejected === []
                ? 'grapup: no domain found'
                : 'grapup: no domain — every candidate was unrelated to the company name',
            ['company' => $companyName, 'rejected' => implode(', ', array_slice(array_unique($rejected), 0, 5))],
        );

        return ['domain' => null, 'rejected' => $rejected];
    }

    /**
     * The last-resort domain, when nothing corroborated one.
     *
     * A guess, and flagged as one by the caller — but a useful guess: the MX
     * check downstream costs nothing and discards it for free when it does not
     * resolve, which is most of the time.
     */
    public static function guess(string $companyName): ?string
    {
        $slug = PersonName::normalizePart($companyName);

        return strlen($slug) >= 3 ? Parse::domain("{$slug}.com") : null;
    }
}
