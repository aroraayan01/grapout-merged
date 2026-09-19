<?php

namespace App\Services\GrapUp\Domain;

use App\Services\GrapUp\Lists;

/**
 * Smart constructors for the values the pipeline passes around.
 *
 * All of them return null rather than throwing. A malformed domain scraped off
 * a search result is an ordinary Tuesday, not an exceptional condition.
 *
 * Ported from GrapUp's `domain/parse.ts`. The rejections are the valuable part
 * — each one is something that reached production wearing the right shape.
 */
final class Parse
{
    /** A hostname: labels of alphanumerics and hyphens, a dot, a real TLD. */
    private const HOSTNAME_RE = '/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    /**
     * Deliberately stricter than RFC 5321. The addresses here are either
     * scraped from a page or generated from a template, and neither produces
     * quoted local parts or address literals — accepting them would only widen
     * what a malformed scrape can smuggle through.
     */
    private const EMAIL_RE = '/^[a-z0-9](?:[a-z0-9._%+-]{0,62}[a-z0-9])?@([a-z0-9.-]+\.[a-z]{2,63})$/';

    /** File extensions the email shape happily matches inside markup. */
    private const ASSET_SUFFIX_RE = '/\.(png|jpe?g|gif|svg|webp|css|js|mjs|woff2?|ttf|eot|ico|mp4|webm|pdf|zip)$/i';

    /**
     * Reduce anything URL-shaped to a bare hostname.
     * "https://www.Acme.com/contact?x=1" -> "acme.com"
     */
    public static function domain(?string $input): ?string
    {
        $value = mb_strtolower(trim($input ?? ''));
        if ($value === '') {
            return null;
        }

        $value = preg_replace('/^[a-z][a-z0-9+.-]*:\/\//', '', $value);  // scheme
        $value = preg_replace('/^[^\/@]*@/', '', $value);                 // userinfo
        $value = explode('/', $value)[0];
        $value = explode('?', $value)[0];
        $value = explode('#', $value)[0];
        $value = explode(':', $value)[0];                                 // port
        $value = preg_replace('/^www\./', '', $value);
        $value = rtrim($value, '.');

        return preg_match(self::HOSTNAME_RE, $value) === 1 ? $value : null;
    }

    /**
     * The registrable domain: "a.b.acme.co.uk" -> "acme.co.uk".
     *
     * Driven by the TLD label list rather than a full public-suffix table.
     * A deliberate trade: the table is large, needs updating, and the cost of
     * being wrong here is one company matched slightly differently, not a
     * wrong answer.
     */
    public static function registrable(string $domain): string
    {
        $labels = explode('.', $domain);
        $significant = array_values(array_filter($labels, fn ($l) => ! Lists::has(Lists::TLD_LABELS, $l)));

        $last = end($significant);
        if ($last === false) {
            return $domain;
        }

        $start = array_keys($labels, $last, true);

        return implode('.', array_slice($labels, (int) end($start)));
    }

    /** A domain we can build corporate addresses on. */
    public static function isUsableCorporateDomain(?string $domain): bool
    {
        return $domain !== null
            && ! Lists::has(Lists::FREE_MAIL_PROVIDERS, $domain)
            && ! Lists::has(Lists::PLACEHOLDER_DOMAINS, $domain);
    }

    public static function isFreeMailDomain(string $domain): bool
    {
        return Lists::has(Lists::FREE_MAIL_PROVIDERS, $domain);
    }

    public static function email(?string $input): ?string
    {
        $value = preg_replace('/^mailto:/', '', mb_strtolower(trim($input ?? '')));
        if ($value === '' || str_contains($value, '..')) {
            return null;
        }
        if (preg_match(self::ASSET_SUFFIX_RE, $value) === 1) {
            return null;
        }
        if (preg_match(self::EMAIL_RE, $value, $m) !== 1) {
            return null;
        }

        // The domain half has to survive the domain parser too, or the address
        // points somewhere that cannot exist.
        return self::domain($m[1]) === null ? null : $value;
    }

    public static function localPart(string $email): string
    {
        return substr($email, 0, (int) strrpos($email, '@'));
    }

    public static function emailDomain(string $email): string
    {
        return substr($email, (int) strrpos($email, '@') + 1);
    }

    public static function makeEmail(string $local, string $domain): ?string
    {
        return self::email("{$local}@{$domain}");
    }

    /**
     * A phone number is only worth reporting if it has enough digits to dial
     * and is not something else numeric wearing a phone number's shape.
     *
     * Every rejection below is one that reached production. IP addresses are
     * the notable trap: 46.17.172.215 is ten digits and passes a naive length
     * check, and server addresses appear in plenty of scraped pages.
     */
    public static function phone(?string $input): ?string
    {
        $value = self::normalizeParens(trim($input ?? ''));
        if ($value === '') {
            return null;
        }

        // An IPv4 address.
        if (preg_match('/^\d{1,3}(?:\.\d{1,3}){3}$/', $value) === 1) {
            return null;
        }

        // A page, year or figure range: "1239-1240" came off a real homepage.
        if (self::isNumericRange($value)) {
            return null;
        }

        /*
         * A calendar date wearing a phone number's shape. J. D. Beardmore's
         * listing yielded "2009-10-23" as the company switchboard: eight
         * digits, one separator, no repeated run — it passes every other test
         * here, and only the year-month-day shape gives it away.
         */
        if (preg_match('/^(?:19|20)\d{2}[-\/.](?:0?[1-9]|1[0-2])[-\/.](?:0?[1-9]|[12]\d|3[01])$/', $value) === 1) {
            return null;
        }

        // Real numbers use at most one dot-ish separator per group; three or
        // more says version string or address, not something you can dial.
        if (substr_count($value, '.') >= 3) {
            return null;
        }

        /*
         * A number written with both spaces and dots and no country code is
         * not a number. Stripe's page yielded "2025 99.999" as a phone. A real
         * number picks one separator — "+65 6293 4567", or "022.4567.8901" —
         * and the mixed form only appears with a country code in front of it.
         */
        if (! str_starts_with($value, '+') && str_contains($value, '.') && preg_match('/\s/', $value) === 1) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $value);
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return null;
        }

        /*
         * A country code in front means the rest of the number is there too.
         *
         * Eight digits is an ordinary local number in Singapore or Norway, so
         * the floor has to stay low — but nowhere on earth dials an
         * international number in eight. A short one is a truncated capture,
         * and publishing it is worse than publishing nothing because it looks
         * dialable. Screwfix's listing yielded "+44 333 011".
         */
        if (str_starts_with($value, '+') && strlen($digits) < 10) {
            return null;
        }

        // A run of one repeated digit is placeholder markup, not a switchboard.
        if (preg_match('/^(\d)\1+$/', $digits) === 1) {
            return null;
        }

        return $value;
    }

    /**
     * Repair or reject a candidate whose brackets do not balance.
     *
     * Both halves matter and they pull in opposite directions:
     *
     *   "(01254 773945"  — a bracket opened around a trunk prefix the
     *                      extractor never closed. The number is real; strip it.
     *   "78727 (512"     — a snippet cut off mid-area-code. What survives is a
     *                      postcode and a fragment. Reject it.
     *
     * The tell is position. A stray bracket at the very start is a framing
     * character; one in the middle means the text ran out.
     */
    private static function normalizeParens(string $value): string
    {
        $opens = substr_count($value, '(');
        $closes = substr_count($value, ')');
        if ($opens === $closes) {
            return $value;
        }

        if ($opens === 1 && $closes === 0 && str_starts_with($value, '(')) {
            return trim(substr($value, 1));
        }

        return '';
    }

    /**
     * Two similar-length numbers joined by a dash: "1239-1240", "2019-2020".
     *
     * The tell is that the halves run consecutively or nearly so. A real
     * number written "6293-4567" has unrelated halves and survives.
     */
    public static function isNumericRange(string $candidate): bool
    {
        if (preg_match('/^(\d{3,4})\s*-\s*(\d{3,4})$/', $candidate, $m) !== 1) {
            return false;
        }
        if (strlen($m[1]) !== strlen($m[2])) {
            return false;
        }

        $gap = (int) $m[2] - (int) $m[1];

        return $gap >= 0 && $gap <= 10;
    }
}
