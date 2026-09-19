<?php

namespace App\Services\GrapUp;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What a whole domain has taught us, so the next contact at it is cheap.
 *
 * This is where the pipeline's economics turn. The first person at a company
 * may cost a full walk; everyone after them costs about one check, because the
 * pattern that worked is remembered here. The MX answer is remembered too, so
 * a dead domain costs one DNS lookup once rather than a lookup per search.
 *
 * Writes are best-effort throughout: a fact that fails to save costs one
 * re-check later, and a fact that takes the search down with it costs the
 * whole search.
 *
 * Ported from GrapUp's `repositories/domain-facts.repo.ts`.
 */
final class DomainFacts
{
    /**
     * Enough agreement to derive an address for somebody nobody has checked.
     *
     * Guards both ways. Too few samples and a shape is a coincidence; low
     * confidence means the domain disagrees with itself — a company
     * publishing both `pavan.kumar@` and `p.kanchi@` should not be
     * extrapolated from at all.
     */
    private const WELL_EVIDENCED_SAMPLES = 2;

    private const WELL_EVIDENCED_CONFIDENCE = 0.75;

    public static function get(string $domain): ?object
    {
        return DB::table('grap_domain_facts')->where('domain', mb_strtolower($domain))->first();
    }

    public static function isWellEvidenced(?object $facts): bool
    {
        return $facts !== null
            && $facts->verified_pattern !== null
            && (int) $facts->pattern_samples >= self::WELL_EVIDENCED_SAMPLES
            && (float) ($facts->pattern_confidence ?? 0) >= self::WELL_EVIDENCED_CONFIDENCE;
    }

    public static function recordMx(string $domain, bool $hasMx): void
    {
        self::write($domain, ['has_mx' => $hasMx, 'mx_checked_at' => now()]);
    }

    public static function recordCatchAll(string $domain, bool $isCatchAll): void
    {
        self::write($domain, ['is_catchall' => $isCatchAll, 'catchall_checked_at' => now()]);
    }

    /**
     * Record a pattern a mail server actually confirmed.
     *
     * Agreement pulls confidence back towards 1 without ever asserting more
     * certainty than the disagreements already recorded allow. A *different*
     * pattern at a domain that already had one resets the count and says
     * plainly that the domain is inconsistent, rather than averaging two
     * conventions into a third that nobody uses.
     */
    public static function recordVerifiedPattern(string $domain, string $pattern): void
    {
        $existing = self::get($domain);

        $samples = 1;
        $confidence = 1.0;

        if ($existing !== null && $existing->verified_pattern !== null) {
            if ($existing->verified_pattern === $pattern) {
                $samples = (int) $existing->pattern_samples + 1;
                $was = (float) ($existing->pattern_confidence ?? 1);
                $confidence = min(1.0, $was + (1 - $was) / 2);
            } else {
                $samples = 1;
                $confidence = 0.5;
                Log::info('grapup: domain disagrees with itself about its own pattern', [
                    'domain' => $domain, 'was' => $existing->verified_pattern, 'now' => $pattern,
                ]);
            }
        }

        self::write($domain, [
            'verified_pattern' => $pattern,
            'pattern_samples' => $samples,
            'pattern_confidence' => $confidence,
        ]);
    }

    /**
     * A pattern read off an address the company published.
     *
     * Kept in its own column on purpose: `verified_pattern` is read everywhere
     * as "a server confirmed this", and letting a guess share that column is
     * how it gets laundered into a fact one join later.
     */
    public static function recordInferredPattern(string $domain, string $pattern, float $confidence = 0.5): void
    {
        self::write($domain, ['inferred_pattern' => $pattern, 'inferred_confidence' => $confidence]);
    }

    private static function write(string $domain, array $values): void
    {
        try {
            DB::table('grap_domain_facts')->updateOrInsert(
                ['domain' => mb_strtolower($domain)],
                $values + ['last_checked_at' => now()],
            );
        } catch (\Throwable $e) {
            Log::warning('grapup: could not record a domain fact', [
                'domain' => $domain, 'error' => $e->getMessage(),
            ]);
        }
    }
}
