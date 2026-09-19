<?php

namespace App\Services\GrapUp\Domain;

/**
 * How senior a contact's headline says they are.
 *
 * Used to decide who the expensive half of the verification walk is spent on,
 * and it exists because of one company. Pardaen returned both a buyer and a
 * Managing Director, both confirmed employees. The walk took the buyer — he
 * was ranked first by the search engine — drove him through every rare
 * pattern, and proved he has no mailbox at all. The Managing Director's
 * address is `jd@pardaen.be`, his initials, and it was never bought.
 *
 * A founder or director is far likelier to hold a personal mailbox than a
 * junior in the same company, so seniority is the better tie-break when
 * several people could prove the domain. It is only a tie-break: a confirmed
 * employee always outranks an unconfirmed one, whatever either headline claims.
 *
 * Deliberately shallow. This is not a job-title ontology, it is a way of
 * putting the likeliest mailbox first, and a wrong guess costs the position of
 * one person in a list rather than a wrong answer.
 *
 * Ported from GrapUp's `domain/seniority.ts`.
 */
final class Seniority
{
    /** Lower is more senior. Contacts sort ascending. */
    public const UNKNOWN = 3;

    private const TIERS = [
        // Runs the company. Nearly always has a personal address.
        [
            'founder', 'co-founder', 'cofounder', 'owner', 'proprietor', 'ceo',
            'chief executive', 'managing director', 'general manager', 'president', 'chairman',
            'chairwoman', 'chair', 'partner', 'directeur', 'geschäftsführer',
            'geschaftsfuhrer', 'gerente', 'zaakvoerder'
        ],
        // Runs a function.
        [
            'director', 'chief', 'cto', 'cfo', 'coo', 'cmo', 'cio', 'vice president', 'vp',
            'head of', 'partner manager'
        ],
        // Runs a team or a desk.
        [
            'manager', 'lead', 'principal', 'senior', 'supervisor', 'controller'
        ],
    ];

    /**
     * Rank a headline, lower being more senior.
     *
     * Matched on word boundaries: "vp" must not fire on "development", and
     * "chair" must not fire on "purchaser".
     */
    public static function rank(?string $headline): int
    {
        $text = mb_strtolower($headline ?? '');
        if ($text === '') {
            return self::UNKNOWN;
        }

        foreach (self::TIERS as $index => $tier) {
            foreach ($tier as $term) {
                if (preg_match('/(^|[^a-z])' . preg_quote($term, '/') . '([^a-z]|$)/u', $text) === 1) {
                    return $index;
                }
            }
        }

        return self::UNKNOWN;
    }
}
