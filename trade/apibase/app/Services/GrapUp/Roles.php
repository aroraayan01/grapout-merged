<?php

namespace App\Services\GrapUp;

/**
 * Department shorthands, expanded into boolean query fragments.
 *
 * These are injected into the LinkedIn dork so results are pre-filtered to
 * people worth contacting, rather than whoever happens to rank first. The
 * filtering happens at the search engine, which means it costs nothing: a
 * narrower query is the same one call.
 *
 * Ported from GrapUp's `domain/roles.ts`.
 */
final class Roles
{
    public const QUERIES = [
        // Decision makers who own the requisition.
        'hiring' => '("Hiring Manager" OR "Engineering Manager" OR "VP Engineering" OR "Tech Lead")',
        // Gatekeepers actively reading resumes.
        'recruiter' => '("Technical Recruiter" OR "Talent Acquisition" OR "Head of Staffing" OR "Recruiter")',
        'hr' => '("HR Manager" OR "Human Resources" OR "People Ops" OR "HRBP")',

        'sales' => '("Sales" OR "Account Executive" OR "Business Development" OR "BDM" OR "SDR")',
        'marketing' => '("Marketing" OR "CMO" OR "Growth" OR "Brand Manager")',
        'tech' => '("CTO" OR "Software Architect" OR "Senior Developer" OR "IT Director")',
        'finance' => '("CFO" OR "Finance" OR "Accounting" OR "Controller")',
        'exec' => '("CEO" OR "Founder" OR "Managing Director" OR "President" OR "COO")',
        'procurement' => '("Procurement" OR "Purchasing Manager" OR "Sourcing Manager" OR "Buyer")',
        'operations' => '("Operations Manager" OR "COO" OR "Head of Operations" OR "Plant Manager")',
        'logistics' => '("Logistics" OR "Supply Chain" OR "Shipping Manager" OR "Freight")',
    ];

    /** Aliases so the frontend can send either label. */
    private const ALIASES = [
        'it' => 'tech',
        'engineering' => 'tech',
        'ceo' => 'exec',
        'executive' => 'exec',
        'leadership' => 'exec',
        'hrbp' => 'hr',
        'people' => 'hr',
        'talent' => 'recruiter',
        'purchasing' => 'procurement',
        'ops' => 'operations',
        'supply chain' => 'logistics',
    ];

    /** The department labels the UI offers, in the order it offers them. */
    public static function known(): array
    {
        return array_keys(self::QUERIES);
    }

    /**
     * Turn a department label into a query fragment.
     *
     * Unknown labels are passed through as a literal quoted phrase, so a
     * free-text role still narrows the search instead of being silently
     * dropped — a search for "kiln operator" should look for kiln operators.
     */
    public static function query(?string $targetRole): string
    {
        $raw = trim($targetRole ?? '');
        if ($raw === '') {
            return '';
        }

        $role = mb_strtolower($raw);
        $key = self::ALIASES[$role] ?? $role;

        if (isset(self::QUERIES[$key])) {
            return self::QUERIES[$key];
        }

        // Quotes inside the term would break out of the phrase and change the
        // query.
        return '"' . str_replace('"', '', $raw) . '"';
    }
}
