<?php

namespace App\Services\GrapUp\Http;

use Illuminate\Support\Facades\Cache;

/**
 * A circuit breaker per vendor.
 *
 * The point is not resilience in the abstract — it is that every call to a
 * vendor that is down still costs the full timeout in wall-clock, and across a
 * run that is minutes spent rediscovering the same outage. Once enough calls
 * in a row have failed the breaker opens, and calls fail instantly until a
 * cooldown has passed and one probe is allowed through.
 *
 * Failure means "the vendor did not answer usefully" — a timeout, a 5xx, a 429
 * that survived the retry. A 400 is not a failure of the vendor, it is a
 * failure of the request, and tripping on those would take the pipeline down
 * over one malformed domain.
 *
 * **The one real departure from GrapUp.** There the breaker is a long-lived
 * object in a Node process, so its state is simply a field. PHP has no such
 * process: every request starts with an empty heap, so an in-memory breaker
 * would be permanently closed and would never once stop a call. The state
 * lives in the cache instead, which also makes it shared — if one php-fpm
 * worker discovers Clearout is down, the other thirty do not have to discover
 * it separately.
 */
class CircuitBreaker
{
    public const CLOSED = 'closed';

    public const OPEN = 'open';

    public const HALF_OPEN = 'half_open';

    public function __construct(
        private readonly string $name,
        /** Consecutive failures before the circuit opens. */
        private readonly int $failureThreshold = 5,
        /** How long it stays open before allowing a probe. */
        private readonly int $cooldownSeconds = 30,
        /** Consecutive probe successes needed to close again. */
        private readonly int $successThreshold = 1,
    ) {}

    private function key(): string
    {
        return "grapup:circuit:{$this->name}";
    }

    /** @return array{state: string, failures: int, successes: int, opened_at: int|null} */
    private function state(): array
    {
        return Cache::get($this->key()) ?? [
            'state' => self::CLOSED, 'failures' => 0, 'successes' => 0, 'opened_at' => null,
        ];
    }

    private function save(array $state): void
    {
        // Long enough to outlive any cooldown; a breaker that expires simply
        // starts closed again, which is the safe direction to fail.
        Cache::put($this->key(), $state, now()->addHours(6));
    }

    /**
     * Whether a call may proceed. Moves an expired open circuit to half-open
     * as a side effect, which is what lets exactly one probe through.
     */
    public function canAttempt(): bool
    {
        $state = $this->state();

        if ($state['state'] === self::CLOSED) {
            return true;
        }

        if ($state['state'] === self::OPEN) {
            if (time() - (int) ($state['opened_at'] ?? 0) >= $this->cooldownSeconds) {
                $state['state'] = self::HALF_OPEN;
                $state['successes'] = 0;
                $this->save($state);

                return true;
            }

            return false;
        }

        // half_open: the probe is already in flight, so nothing else goes.
        return false;
    }

    public function recordSuccess(): void
    {
        $state = $this->state();

        if ($state['state'] === self::HALF_OPEN) {
            $state['successes']++;
            if ($state['successes'] >= $this->successThreshold) {
                $this->close();

                return;
            }
            $this->save($state);

            return;
        }

        $state['failures'] = 0;
        $this->save($state);
    }

    public function recordFailure(): void
    {
        $state = $this->state();
        $state['failures']++;
        $state['successes'] = 0;

        if ($state['state'] === self::HALF_OPEN
            || ($state['state'] === self::CLOSED && $state['failures'] >= $this->failureThreshold)) {
            $state['state'] = self::OPEN;
            $state['opened_at'] = time();
        }

        $this->save($state);
    }

    public function retryInSeconds(): int
    {
        $state = $this->state();
        if ($state['state'] !== self::OPEN || $state['opened_at'] === null) {
            return 0;
        }

        return max(0, $this->cooldownSeconds - (time() - (int) $state['opened_at']));
    }

    /** What the health endpoint reports. */
    public function snapshot(): array
    {
        $state = $this->state();

        return [
            'state' => $state['state'],
            'consecutive_failures' => $state['failures'],
            'retry_in_seconds' => $state['state'] === self::OPEN ? $this->retryInSeconds() : null,
        ];
    }

    public function close(): void
    {
        Cache::forget($this->key());
    }
}
