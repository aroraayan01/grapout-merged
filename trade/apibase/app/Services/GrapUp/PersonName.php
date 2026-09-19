<?php

namespace App\Services\GrapUp;

/**
 * Turning a search-result title into the two name parts an email is built
 * from.
 *
 * This is load-bearing in a way that is easy to miss: whatever comes out of
 * here *is* the candidate list. A surname wrong by one token does not merely
 * rank the candidates badly — it keeps the real address off the list
 * entirely, and the whole verification budget is then spent proving that a
 * set of addresses nobody has does not exist.
 *
 * Ported from GrapUp's `domain/name.ts`.
 */
final class PersonName
{
    public function __construct(
        public readonly string $first,
        /** Empty for mononyms. Never a middle name. */
        public readonly string $last = '',
    ) {}

    /**
     * How LinkedIn separates the segments of a result title.
     *
     * Splitting on a bare "-" tears hyphenated names apart: "Jean-Luc Picard
     * - CTO" yields "Jean" as the name and "Luc Picard" as the job title.
     * LinkedIn always pads its separator with spaces, so requiring that keeps
     * the two apart.
     */
    private const TITLE_DELIMITER_RE = '/\s+[-–—·]\s+|\s*\|\s*/u';

    /** More tokens than this in the name slot means a headline leaked into it. */
    private const MAX_NAME_TOKENS = 4;

    /**
     * How LinkedIn titles an activity page rather than a profile:
     * "Rahul Verma on LinkedIn: We are hiring across five teams".
     *
     * These rank alongside profiles and are not evidence that the person works
     * at the company — they may only have commented. Tested against the
     * segment *before* the first delimiter, because a real profile can
     * legitimately say "Top Voice on LinkedIn" in its headline, and that
     * segment is a job title.
     */
    private const ACTIVITY_MARKER_RE = '/\b(?:on|posted on|shared on|commented on)\s+LinkedIn\b/i';

    /** Strip a name part down to what can legally appear in a local part. */
    public static function normalizePart(?string $part): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($part ?? '')) ?? '';
    }

    /**
     * Remove everything that is decoration rather than name: honorifics,
     * post-nominals, emoji, pronoun tags, and the bracketed asides people put
     * after their surname.
     */
    public static function clean(string $raw): string
    {
        $name = trim($raw);

        $name = preg_replace('/\([^)]*\)/u', ' ', $name);      // "(He/Him)", "(Acme)"
        $name = preg_replace('/\[[^\]]*\]/u', ' ', $name);
        // Separate passes: combining a variation selector with the pictograph
        // ranges in one class can cut a grapheme in half rather than remove it.
        $name = preg_replace('/[\x{1F000}-\x{1FAFF}]/u', ' ', $name);
        $name = preg_replace('/[\x{2600}-\x{27BF}]/u', ' ', $name);
        $name = preg_replace('/[\x{FE0E}\x{FE0F}]/u', '', $name);
        $name = preg_replace('/[“”"\'`*]/u', ' ', $name);
        $name = trim(preg_replace('/\s+/u', ' ', $name));

        $name = preg_replace(Lists::HONORIFIC_RE, '', $name);
        $name = preg_replace(Lists::POST_NOMINAL_RE, '', $name);
        $name = preg_replace('/[,;]+$/u', '', $name);

        return trim(preg_replace('/\s+/u', ' ', $name));
    }

    /**
     * Split name tokens into the first name and the surname an email pattern
     * is actually built from.
     *
     * Everything between the two is a middle name. Gluing it onto the surname
     * is the mistake this exists to avoid: "Rajesh Kumar Sharma" produced
     * rajesh.kumarsharma@, rkumarsharma@ and rajeshkumarsharma@, spent the
     * whole budget, and never once tested rajesh.sharma@ — the address that
     * exists.
     *
     * Particles are kept, because they are part of the surname rather than
     * between it and the first name: "Jan van der Berg" still yields
     * jan.vanderberg@.
     *
     * Not universal. Spanish names carry two surnames and use the first, so
     * "Maria Garcia Lopez" is usually maria.garcia@ rather than the
     * maria.lopez@ this produces. The bare {f} form is second in the walk and
     * catches a fair share of those; the glued form caught neither.
     *
     * @param  list<string>  $parts
     */
    public static function split(array $parts): ?self
    {
        $parts = array_values($parts);
        if ($parts === []) {
            return null;
        }

        $first = $parts[0];
        if (count($parts) === 1) {
            return new self($first);
        }

        $start = count($parts) - 1;
        while ($start > 1) {
            $previous = mb_strtolower($parts[$start - 1]);
            if (! Lists::has(Lists::SURNAME_PARTICLES, $previous)) {
                break;
            }
            $start--;
        }

        return new self($first, implode(' ', array_slice($parts, $start)));
    }

    /**
     * "Jane Doe - Head of Sales | LinkedIn" -> first: Jane, last: Doe.
     *
     * Returns null when the title is not a name at all. That matters more
     * than it looks: whatever comes out of here gets verification credits
     * spent on it, so a post headline parsed as a person is money spent on an
     * address nobody has.
     */
    public static function fromTitle(?string $title): ?self
    {
        $raw = trim($title ?? '');
        if ($raw === '') {
            return null;
        }

        $head = preg_split(self::TITLE_DELIMITER_RE, $raw)[0] ?? '';

        // Checked before "LinkedIn" is stripped — removing it first would
        // erase the very marker that identifies an activity page.
        if (preg_match(self::ACTIVITY_MARKER_RE, $head) === 1) {
            return null;
        }

        $cleaned = self::clean(trim(preg_replace('/\bLinkedIn\b/i', '', $head)));

        // Any remaining colon form is a sentence, not a name.
        $beforeColon = explode(':', $cleaned)[0] ?? '';
        $parts = array_values(array_filter(preg_split('/\s+/u', $beforeColon) ?: [], fn ($p) => $p !== ''));

        if ($parts === [] || count($parts) > self::MAX_NAME_TOKENS) {
            return null;
        }

        // A "name" made only of particles or single letters is not one.
        $substantial = false;
        foreach ($parts as $part) {
            if (mb_strlen(self::normalizePart($part)) >= 2) {
                $substantial = true;
                break;
            }
        }
        if (! $substantial) {
            return null;
        }

        return self::split($parts);
    }

    /** A contact needs at least a usable first name to build anything from. */
    public function isUsable(): bool
    {
        return mb_strlen(self::normalizePart($this->first)) >= 2;
    }

    /** The formal names a short form stands for: "chuck" -> ["charles"]. */
    public function formalFirstNames(): array
    {
        return Lists::NICKNAMES[mb_strtolower(trim($this->first))] ?? [];
    }
}
