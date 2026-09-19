<?php

namespace App\Services\GrapUp;

/**
 * Generating, detecting and applying corporate email naming patterns.
 *
 * The economics of the whole feature live in this class. Verification is the
 * only thing that costs money, the budget cuts the candidate list off
 * partway, and so a pattern's *position* matters more than its presence: one
 * sitting past the budget may as well not exist.
 *
 * Ported from GrapUp's `domain/email-patterns.ts`.
 */
final class EmailPatterns
{
    /**
     * The permutation templates, in descending order of real-world frequency.
     *
     * Order is the design here: it is the order verification credits are
     * spent in. The underscore forms are interleaved at the frequency they
     * actually occur rather than appended, which matters for Indian, Gulf and
     * wider Asian companies where first_last is common — and those are most
     * of the book.
     */
    public const TEMPLATES = [
        /*
         * Ordered by how often each is actually used. Sendburg's July 2026
         * study of 336,782 verified work emails: first.last 47.7%, flast
         * 26.8%, first 8.1%, then firstlast, first_last and f.last at about
         * 2% each. flast used to sit behind first despite being three times
         * as common, which cost one extra check per person at every flast
         * company.
         */
        '{f}.{l}',   // jane.doe   — by far the most common
        '{fi}{l}',   // jdoe
        '{f}',       // jane
        '{f}{l}',    // janedoe
        '{f}_{l}',   // jane_doe
        '{fi}.{l}',  // j.doe
        '{l}',       // doe
        '{f}.{li}',  // jane.d
        '{l}.{f}',   // doe.jane
        '{f}{li}',   // janed
        '{fi}_{l}',  // j_doe
        '{l}{f}',    // doejane
        '{f}-{l}',   // jane-doe
        '{l}_{f}',   // doe_jane
        '{fi}{li}',  // jd
        '{l}{fi}',   // doej
        '{l}.{fi}',  // doe.j
        '{li}.{f}',  // d.jane
        '{li}{f}',   // djane
        '{fi}-{l}',  // j-doe
    ];

    /**
     * How many of the top templates are also tried under a formal first name.
     *
     * Interleaved rather than appended: a budget that runs out after eight
     * checks would never reach an alternate sitting at position twenty-one,
     * which is the same as not having it.
     */
    public const ALTERNATE_DEPTH = 5;

    /** Characters that join name parts — never valid at either end of a local part. */
    private const STRANDED_SEPARATOR_RE = '/^[._-]|[._-]$|[._-]{2}/';

    private const TOKENS = ['{f}', '{l}', '{fi}', '{li}'];

    /**
     * Expand a template such as "{f}.{l}" against a name.
     *
     * Returns null when a token the template needs has no value — "{f}.{l}"
     * for a mononym would otherwise render "jane." and burn a check on an
     * address that cannot be delivered to.
     */
    public static function render(string $template, PersonName $name): ?string
    {
        $f = PersonName::normalizePart($name->first);
        $l = PersonName::normalizePart($name->last);

        $values = [
            '{f}' => $f,
            '{l}' => $l,
            '{fi}' => mb_substr($f, 0, 1),
            '{li}' => mb_substr($l, 0, 1),
        ];

        $local = $template;
        foreach (self::TOKENS as $token) {
            if (! str_contains($local, $token)) {
                continue;
            }
            if ($values[$token] === '') {
                return null;
            }
            $local = str_replace($token, $values[$token], $local);
        }

        // A separator left stranded at either end, or doubled up, means a name
        // part rendered empty — the address would be malformed and the check
        // wasted.
        if ($local === '' || preg_match(self::STRANDED_SEPARATOR_RE, $local) === 1) {
            return null;
        }

        // A single-character mailbox is almost never a person's corporate
        // address, and generating one is how "Ananthkumar S" produced s@ — a
        // credit spent on an address that, if it exists, belongs to somebody
        // else.
        if (mb_strlen($local) < 2) {
            return null;
        }

        return $local;
    }

    /**
     * Every plausible address for a name at a domain, most likely first and
     * de-duplicated. The order is the order credits are spent in.
     *
     * When the first name is a known short form, the top few templates are
     * also rendered against the formal name it stands for — "Chuck Waters"
     * gets charles.waters@ near the front, because every permutation of
     * "chuck" can fail against a perfectly healthy domain and report a person
     * as undeliverable when only the guesses were.
     *
     * @return list<Candidate>
     */
    public static function candidates(PersonName $name, string $domain): array
    {
        $seen = [];
        $result = [];
        $ranks = array_flip(self::TEMPLATES);

        $push = function (string $template, PersonName $forName, bool $fromFormalName)
            use ($domain, &$seen, &$result, $ranks): void {
            $local = self::render($template, $forName);
            if ($local === null || isset($seen[$local])) {
                return;
            }
            $seen[$local] = true;

            $email = self::makeEmail($local, $domain);
            if ($email === null) {
                return;
            }

            $result[] = new Candidate(
                email: $email,
                template: $template,
                fromFormalName: $fromFormalName,
                rank: $ranks[$template] ?? count(self::TEMPLATES),
            );
        };

        $alternates = array_map(
            fn (string $first) => new PersonName($first, $name->last),
            $name->formalFirstNames(),
        );

        foreach (self::TEMPLATES as $index => $template) {
            $push($template, $name, false);
            if ($index < self::ALTERNATE_DEPTH) {
                foreach ($alternates as $alternate) {
                    $push($template, $alternate, true);
                }
            }
        }

        // Anything the interleave did not reach, still in frequency order.
        foreach ($alternates as $alternate) {
            foreach (self::TEMPLATES as $template) {
                $push($template, $alternate, true);
            }
        }

        return $result;
    }

    /**
     * The reverse: given the local part that actually verified, work out which
     * template produced it, so it can be applied to the rest of the team.
     *
     * Returns null when nothing matches, which happens on aliases a template
     * could never generate — "jd2@", "jane.doe.ext@". Better to report no
     * pattern than to propagate one that was never really there.
     *
     * Also null for a role mailbox. "sales.uk@" is shaped exactly like
     * first.last, and a company whose first confirmed address happens to be a
     * functional inbox would otherwise teach the cache a naming convention
     * nobody uses — and then spend the rest of the quarter deriving addresses
     * from it.
     */
    public static function detect(string $local, PersonName $name): ?string
    {
        if (self::isRoleMailbox($local)) {
            return null;
        }

        foreach (self::TEMPLATES as $template) {
            if (self::render($template, $name) === $local) {
                return $template;
            }
        }

        return null;
    }

    /** True when a local part names a function rather than a person. */
    public static function isRoleMailbox(string $local): bool
    {
        $parts = array_values(array_filter(
            preg_split('/[._-]/', mb_strtolower($local)) ?: [],
            fn ($part) => $part !== '',
        ));

        if ($parts === []) {
            return false;
        }

        // Every segment being a role word is the tell: "sales.uk", "info",
        // "hr.in". A real "sales.patel" keeps its surname and survives.
        foreach ($parts as $part) {
            if (! Lists::has(Lists::ROLE_MAILBOX_WORDS, $part)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Apply a detected template to another contact at the same domain.
     *
     * Falls back to the bare first name when the template cannot render for
     * this particular person — a "{f}.{l}" company still has mononym staff.
     */
    public static function apply(string $template, PersonName $name, string $domain): ?string
    {
        $local = self::render($template, $name) ?? PersonName::normalizePart($name->first);
        if ($local === '') {
            return null;
        }

        return self::makeEmail($local, $domain);
    }

    /**
     * Split a candidate list into the common head and the rare tail.
     *
     * The two halves are spent very differently. The head is cheap enough to
     * try against several people; the tail is only worth entering for somebody
     * we have reason to believe actually works at the company.
     *
     * Measured on a ten-company run, every pattern that cracked sat at rank
     * 1–3: `{f}`, `{fi}{l}` and `{f}{l}`. The tail is not empty in the wider
     * world, but it is rare enough that reaching it on the wrong person is the
     * more expensive mistake.
     *
     * A pattern the domain already taught us leads the head whatever its rank
     * — a company that genuinely uses `{l}.{fi}` should not be re-discovered
     * from scratch every time, and one check settles it.
     *
     * @param  list<Candidate>  $candidates
     * @return array{common: list<Candidate>, tail: list<Candidate>}
     */
    public static function splitByDepth(array $candidates, int $depth, ?string $known = null): array
    {
        $promoted = [];
        $common = [];
        $tail = [];

        foreach ($candidates as $candidate) {
            if ($known !== null && $candidate->template === $known) {
                $promoted[] = $candidate;
            } elseif ($candidate->rank < $depth) {
                $common[] = $candidate;
            } else {
                $tail[] = $candidate;
            }
        }

        return ['common' => [...$promoted, ...$common], 'tail' => $tail];
    }

    /** Render a template and domain the way the UI shows it. */
    public static function format(string $template, string $domain): string
    {
        return "{$template}@{$domain}";
    }

    /** A local part and a domain, joined only if the result is a real address. */
    public static function makeEmail(string $local, string $domain): ?string
    {
        $email = mb_strtolower(trim($local)) . '@' . mb_strtolower(trim($domain));

        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : $email;
    }
}
