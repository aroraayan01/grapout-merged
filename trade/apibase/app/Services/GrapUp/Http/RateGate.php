<?php

namespace App\Services\GrapUp\Http;

use Illuminate\Support\Facades\Cache;

/**
 * Client-side pacing for a vendor API.
 *
 * Vendors publish a requests-per-minute ceiling and answer 429 past it.
 * Discovering that ceiling by hitting it is the expensive way round: a
 * throttled verification still costs latency, and the retry that follows can
 * cost a credit. Spacing calls out keeps us under the limit by construction.
 *
 * A minimum interval rather than a token bucket, deliberately. Bursting to the
 * full minute's allowance and then stalling is worse here than a steady drip —
 * the pipeline is mostly sequential, so there is nothing a burst would speed
 * up, and a stall in the middle of a run looks like a hang.
 *
 * Like the breaker beside it, the next-slot marker lives in the cache rather
 * than in a closure: PHP has no process to hold it between requests, and two
 * php-fpm workers each keeping their own marker would pace to twice the limit.
 * The slot is claimed atomically before sleeping, so concurrent callers queue
 * behind each other instead of all reading the same free one.
 */
class RateGate
{
    public function __construct(
        private readonly string $name,
        private readonly int $perMinute,
    ) {}

    /** Blocks until the caller may make its request. */
    public function wait(): void
    {
        if ($this->perMinute <= 0) {
            return; // Unlimited.
        }

        $intervalMs = (int) ceil(60_000 / $this->perMinute);
        $key = "grapup:rate:{$this->name}";

        $lock = Cache::lock("{$key}:lock", 5);
        $delayMs = 0;

        try {
            // Without the lock two workers read the same free slot and both
            // take it, which is how a 60/min gate becomes 120/min under load.
            $lock->block(5);

            $nowMs = (int) (microtime(true) * 1000);
            $nextSlot = (int) (Cache::get($key) ?? 0);
            $start = max($nowMs, $nextSlot);

            Cache::put($key, $start + $intervalMs, now()->addMinutes(5));
            $delayMs = $start - $nowMs;
        } catch (\Throwable) {
            // A cache that cannot lock is not a reason to refuse to call the
            // vendor. Pace pessimistically instead and carry on.
            $delayMs = $intervalMs;
        } finally {
            optional($lock)->release();
        }

        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }
}
