<?php

namespace App\Services\GrapUp\Domain;

use App\Services\GrapUp\Lists;

/**
 * Pulling addresses, numbers and job titles out of pages and search snippets.
 *
 * Ported from GrapUp's `domain/text.ts`.
 */
final class Text
{
    private const EMAIL_CANDIDATE_RE = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/';

    private const PHONE_CANDIDATE_RE = '/(?<![\d.])\+?\(?\d[\d\s().-]{6,17}\d(?![\d.])/';

    private const PROSE_STARTS = ['experienced', 'passionate', 'results', 'experience', 'as a ', 'i am', 'we are'];

    public static function htmlToText(string $html): string
    {
        $text = preg_replace('/<(script|style|noscript|template)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? '';
        $text = preg_replace('/<!--.*?-->/s', ' ', $text) ?? '';
        // Tags, and with them every URL attribute — which is where asset paths
        // that parse as perfectly plausible ten-digit phone numbers live.
        $text = preg_replace('/<[^>]+>/', ' ', $text) ?? '';
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /** @return list<string> */
    public static function emails(string $text): array
    {
        preg_match_all(self::EMAIL_CANDIDATE_RE, $text, $matches);

        $seen = [];
        foreach ($matches[0] ?? [] as $candidate) {
            $email = Parse::email($candidate);
            if ($email !== null) {
                $seen[$email] = true;
            }
        }

        return array_keys($seen);
    }

    /** @return list<string> */
    public static function phones(string $text): array
    {
        preg_match_all(self::PHONE_CANDIDATE_RE, $text, $matches);

        $seen = [];
        foreach ($matches[0] ?? [] as $candidate) {
            $phone = Parse::phone(trim($candidate));
            if ($phone !== null) {
                $seen[$phone] = true;
            }
        }

        return array_keys($seen);
    }

    /**
     * Prefer a corporate address over a consumer one, and a role mailbox over
     * a person — info@ is the company's, and a named person's address found on
     * a contact page may belong to whoever built the site.
     *
     * @param  list<string>  $emails
     */
    public static function pickBestEmail(array $emails): ?string
    {
        if ($emails === []) {
            return null;
        }

        $corporate = array_values(array_filter(
            $emails,
            fn ($e) => ! Lists::has(Lists::FREE_MAIL_PROVIDERS, Parse::emailDomain($e)),
        ));
        $pool = $corporate !== [] ? $corporate : $emails;

        foreach ($pool as $email) {
            if (preg_match(Lists::ROLE_MAILBOX_PREFIXES, $email) === 1) {
                return $email;
            }
        }

        return $pool[0];
    }

    /**
     * Cloudflare replaces mailto links with a hex blob XOR'd against its first
     * byte. Undoing that is the single highest-yield trick in the homepage
     * tier — plenty of sites expose no other address at all.
     */
    public static function decodeCloudflareEmail(string $hash): string
    {
        if (strlen($hash) < 4 || strlen($hash) % 2 !== 0) {
            return '';
        }

        $key = hexdec(substr($hash, 0, 2));
        $decoded = '';

        for ($i = 2; $i < strlen($hash); $i += 2) {
            $byte = hexdec(substr($hash, $i, 2));
            $decoded .= chr($byte ^ $key);
        }

        return $decoded;
    }

    /** @return list<string> */
    public static function cloudflareEmails(string $html): array
    {
        preg_match_all('/data-cfemail="([0-9a-f]+)"/i', $html, $matches);

        $result = [];
        foreach ($matches[1] ?? [] as $hash) {
            $email = Parse::email(self::decodeCloudflareEmail($hash));
            if ($email !== null) {
                $result[] = $email;
            }
        }

        return $result;
    }

    /**
     * The job title from a LinkedIn result, from the title where possible and
     * the snippet otherwise.
     *
     * Returns null rather than a guess. A headline that is really a sentence
     * tells the reader nothing, and putting prose in a job-title column makes
     * the export worse, not richer.
     */
    public static function role(?string $title, ?string $snippet, string $companyName): ?string
    {
        $candidate = self::segmentAfterName($title ?? '') ?? self::segmentAfterName($snippet ?? '');
        if ($candidate === null) {
            return null;
        }

        $role = trim(preg_replace('/\s+/u', ' ', $candidate) ?? '');
        if (mb_strlen($role) < 3 || mb_strlen($role) > 60) {
            return null;
        }

        $lower = mb_strtolower($role);
        if (str_contains($lower, '...') || str_contains($lower, ' is a ')) {
            return null;
        }
        foreach (self::PROSE_STARTS as $start) {
            if (str_starts_with($lower, $start)) {
                return null;
            }
        }

        // "Acme" as a job title is the company name leaking through the split.
        if (self::normalise($role) === self::normalise($companyName)) {
            return null;
        }

        return $role;
    }

    /**
     * A profile title with the person's own name removed: their current role
     * and the employer it names, and nothing else.
     *
     * Two live failures make this the only text worth testing a company name
     * against.
     *
     * The snippet is not safe, because it carries employment *history*.
     * Melanie Walton's read "Commercial Services Specialist - Promethean ·
     * Experience: Traditional Hardware Direct Ltd" — she left, and the company
     * name in that string is a former job. Twenty credits went on her.
     *
     * The name is not safe either, because companies get named after people.
     * Searching "Andries de Jong" returns everyone *called* Andries de Jong,
     * and each corroborates the company perfectly if you test the whole title.
     * Dropping the first segment drops the name with it.
     *
     * Unlike `role`, everything after the name is kept: LinkedIn writes both
     * "Buying Manager at Screwfix Direct Limited" and "Business Development
     * Manager - Mighton Products", and taking only the second segment loses
     * the employer in the second form.
     */
    public static function currentRoleText(?string $title): string
    {
        return implode(' ', array_slice(self::splitTitle($title ?? ''), 1));
    }

    private static function segmentAfterName(string $text): ?string
    {
        // [name, role, ...] — the second segment is the title.
        return self::splitTitle($text)[1] ?? null;
    }

    /** A profile title cut into segments, with LinkedIn's own branding dropped. */
    private static function splitTitle(string $text): array
    {
        $segments = preg_split('/\s+[-–—·]\s+|\s*\|\s*/u', $text) ?: [];

        $clean = [];
        foreach ($segments as $segment) {
            $segment = trim(preg_replace('/\bLinkedIn\b/i', '', $segment) ?? '');
            if ($segment !== '') {
                $clean[] = $segment;
            }
        }

        return $clean;
    }

    private static function normalise(string $text): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($text)) ?? '';
    }
}
