<?php

namespace App\Services\GrapUp\Domain;

use App\Services\GrapUp\Lists;

/**
 * Deciding whether a domain plausibly belongs to a company, and how to widen a
 * company's name when the registered form matches nobody.
 *
 * A blacklist can only exclude the directories we already know about, and the
 * long tail of company-registry sites is effectively infinite. So this is the
 * positive test instead: the hostname has to share an identifying word with
 * the company name.
 *
 * The failure it prevents is worse than a miss. Accepting a directory as the
 * company's own site points the whole contact waterfall at that directory,
 * which then yields some other company's phone and email, presented as this
 * company's. A wrong answer costs more than "not found".
 *
 * Ported from GrapUp's `domain/company-match.ts`.
 */
final class CompanyMatch
{
    /** Anything in round brackets: a trading name, a parent, a former name. */
    private const BRACKETED_RE = '/\(([^)]*)\)/';

    /**
     * The identifying part of a hostname: "thillai.com.sg" -> "thillai".
     *
     * Only the registrable label counts, unless the host is a site builder —
     * where the subdomain is the company's own name and the only identifying
     * part there is.
     */
    public static function domainIdentity(string $domain): string
    {
        $registrable = Parse::registrable($domain);

        if (Lists::has(Lists::SITE_BUILDER_HOSTS, $registrable)) {
            // "siomex.weebly.com" -> "siomex"
            return str_replace('.', '', substr($domain, 0, -(strlen($registrable) + 1)));
        }

        $labels = array_filter(
            explode('.', $registrable),
            fn ($label) => ! Lists::has(Lists::TLD_LABELS, $label),
        );

        return implode('', $labels);
    }

    /** The identifying words of a company name, longest first. */
    public static function identityWords(string $companyName): array
    {
        $words = preg_split('/[^a-z0-9]+/i', mb_strtolower($companyName)) ?: [];

        $words = array_values(array_filter(
            $words,
            fn ($w) => $w !== '' && strlen($w) >= 3 && ! Lists::has(Lists::GENERIC_NAME_WORDS, $w),
        ));

        usort($words, fn ($a, $b) => strlen($b) <=> strlen($a));

        return $words;
    }

    /**
     * Is there anything in this name a domain could be tested against?
     *
     * False for a name made entirely of initials and legal forms. "B&Q
     * Limited" reduces to nothing: "b" and "q" are below the length floor and
     * "limited" is a legal form.
     *
     * Callers decide for themselves what to do with that, because the safe
     * answer differs. Discovering a domain should not block on a name it
     * cannot test — B&Q would find no website at all. Adopting a *different*
     * domain from a scraped address must block, because "cannot test" is not
     * evidence in favour.
     */
    public static function hasTestableIdentity(string $companyName): bool
    {
        return self::identityWords($companyName) !== [];
    }

    /**
     * Does this domain plausibly belong to this company?
     *
     * Permissive by design: a name with nothing distinctive in it returns true
     * rather than blocking.
     */
    public static function domainMatches(string $domain, string $companyName): bool
    {
        $identity = self::domainIdentity($domain);
        $words = self::identityWords($companyName);

        // Nothing distinctive to match on — do not block on a name we cannot test.
        if ($identity === '' || $words === []) {
            return true;
        }

        foreach ($words as $word) {
            if (str_contains($identity, $word) || str_contains($word, $identity)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Do two domains look like the same organisation?
     *
     * Covers the case a company name cannot: acme.com and acme-group.com are
     * plainly related, and a firm may send mail from a domain whose
     * relationship to its website is obvious to a human and invisible to a
     * name match.
     */
    public static function sharesIdentity(string $a, string $b): bool
    {
        $left = self::domainIdentity($a);
        $right = self::domainIdentity($b);

        if ($left === '' || $right === '' || strlen($left) < 3 || strlen($right) < 3) {
            return false;
        }

        return str_contains($left, $right) || str_contains($right, $left);
    }

    /** Government portals list companies but are never the company's own site. */
    public static function isGovernmentSite(string $url): bool
    {
        $lower = mb_strtolower($url);

        return str_contains($lower, '.gov') || str_contains($lower, '.nic.in');
    }

    /**
     * Does this text actually name the company?
     *
     * The question verification credits hinge on. A LinkedIn search returns
     * profiles ranked by relevance, not by employment, and the tail of that
     * list is people who have left, people at similarly named firms, and
     * people the engine merely thought were adjacent.
     *
     * Seen live: the only profile returned for Traditional Hardware Direct Ltd
     * was "Melanie Walton — Commercial Services Specialist", naming no
     * employer at all. Twenty permutations were bought against a healthy
     * domain with a perfectly ordinary {fi}{l} convention, and all twenty came
     * back undeliverable — not because the pattern was wrong, but because she
     * does not work there.
     *
     * Half the identifying words, rounded up, has to appear. Requiring all of
     * them loses "Concept Services, Inc." written as "Concept Services";
     * requiring one lets a bare "Direct" corroborate Traditional Hardware
     * Direct.
     */
    public static function mentionsCompany(string $text, string $companyName): bool
    {
        $words = self::identityWords($companyName);

        // Nothing distinctive to test against — do not claim corroboration we
        // cannot check, and do not deny it either. Treated as unconfirmed.
        if ($words === []) {
            return false;
        }

        $haystack = mb_strtolower($text);
        $hits = 0;
        foreach ($words as $word) {
            if (str_contains($haystack, $word)) {
                $hits++;
            }
        }

        return $hits >= (int) ceil(count($words) / 2);
    }

    /**
     * Progressively broader forms of a company name, most specific first.
     *
     * An exact-phrase search on the full registered name often matches
     * nothing, because nobody writes "Om Packaging Melbourne Pty Ltd" in a
     * LinkedIn headline — they write "Om Packaging". Measured:
     *
     *   "Om Packaging Melbourne Pty Ltd"  ->  0 profiles
     *   "Om Packaging Melbourne"          ->  0 profiles
     *   "Om Packaging"                    ->  6 profiles
     *
     * So the search widens in steps rather than accepting the zero.
     *
     * @return list<string>
     */
    public static function nameVariants(string $companyName): array
    {
        $full = trim($companyName);
        if ($full === '') {
            return [];
        }

        $outside = trim(preg_replace('/\s+/u', ' ', preg_replace(self::BRACKETED_RE, ' ', $full) ?? '') ?? '');

        $candidates = array_merge(
            [
                $full,
                /*
                 * "SignatureThings (Owned and Operated by Execula LLC)" as
                 * written matches one profile. Its two halves match eleven
                 * between them, and the bracketed half is usually the trading
                 * name or the parent — the form staff actually put in a
                 * headline.
                 */
                $outside,
                self::strip($outside),
            ],
            self::bracketedNames($full, $outside),
            [self::firstWords(self::strip($outside), 2)],
        );

        $seen = [];
        $variants = [];

        foreach ($candidates as $index => $candidate) {
            $trimmed = trim(preg_replace('/^[\s&,.-]+|[\s&,.-]+$/u', '', $candidate) ?? '');
            if (strlen($trimmed) < 3 || isset($seen[$trimmed])) {
                continue;
            }

            /*
             * A *widened* variant with nothing distinctive left in it matches
             * every company in the country. "F.L. Pardaen & Co NV" cut to
             * initials gives "F L", which is not a search, it is a coin toss.
             *
             * The exception is the name we were handed. "B&Q Limited" has no
             * word of three letters that is not a legal form, and filtering it
             * would leave nothing to search for at all.
             */
            if ($index > 0 && self::identityWords($trimmed) === []) {
                continue;
            }

            $seen[$trimmed] = true;
            $variants[] = $trimmed;
        }

        return $variants;
    }

    /**
     * The names hiding inside brackets, most useful first.
     *
     * A book of companies writes the same relationship several ways: "(Armac
     * Martin)" is a name on its own, "(Brassfounders)" is a description, and
     * "(Owned and Operated by Execula LLC)" is a sentence with a name at the
     * end of it. All three are unwound, shortest-and-cleanest first.
     *
     * Brackets sharing a word with the name outside them are tried first:
     * "Armac Martin" alongside "Armac Manufacturing" is almost certainly the
     * same firm's trading name, where "Brassfounders" could be anything.
     */
    private static function bracketedNames(string $full, string $outside): array
    {
        preg_match_all(self::BRACKETED_RE, $full, $matches);

        $outsideWords = array_flip(self::identityWords($outside));
        $related = [];
        $unrelated = [];

        foreach ($matches[1] ?? [] as $raw) {
            $inner = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
            if ($inner === '') {
                continue;
            }

            // "Owned and Operated by Execula LLC" -> "Execula LLC". From the
            // connective on, because the name is what the sentence led up to.
            $tail = preg_match('/\b(?:by|of|trading as|t\/a|formerly|part of)\s+(.+)$/i', $inner, $m) === 1
                ? trim($m[1])
                : '';

            $names = array_values(array_filter([
                self::strip($tail), $tail, self::strip($inner), $inner,
            ], fn ($n) => $n !== ''));

            $isRelated = false;
            foreach (self::identityWords($inner) as $word) {
                if (isset($outsideWords[$word])) {
                    $isRelated = true;
                    break;
                }
            }

            $isRelated ? array_push($related, ...$names) : array_push($unrelated, ...$names);
        }

        return [...$related, ...$unrelated];
    }

    /** Drop the legal form: "Armac Martin Ltd" -> "Armac Martin". */
    private static function strip(string $name): string
    {
        $stripped = preg_replace(Lists::LEGAL_SUFFIX_RE, ' ', $name) ?? '';
        $stripped = str_replace(['.', ','], ' ', $stripped);

        return trim(preg_replace('/\s+/u', ' ', $stripped) ?? '');
    }

    private static function firstWords(string $name, int $count): string
    {
        $words = array_values(array_filter(preg_split('/\s+/u', $name) ?: [], fn ($w) => $w !== ''));

        return implode(' ', array_slice($words, 0, $count));
    }
}
