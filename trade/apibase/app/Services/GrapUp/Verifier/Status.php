<?php

namespace App\Services\GrapUp\Verifier;

/**
 * The five canonical verdicts, and what the UI makes of them.
 *
 * The distinction that carries the most weight is `UNDELIVERABLE` against
 * `UNKNOWN`. Running out of credits, a timeout, a bad token and a 429 all
 * resolve to `UNKNOWN` — we learned nothing. Only a positive vendor verdict
 * marks an address bad, because collapsing those two is how a billing lapse
 * quietly deletes a week of good contacts.
 */
final class Status
{
    public const DELIVERABLE = 'deliverable';

    /** The domain accepts everything, so a hit on one address proves nothing. */
    public const CATCH_ALL = 'catch_all';

    /** Real, but a role mailbox or a full one. Worth showing, not discarding. */
    public const RISKY = 'risky';

    public const UNDELIVERABLE = 'undeliverable';

    /** We learned nothing. Never a statement about the address. */
    public const UNKNOWN = 'unknown';

    /** A status worth stopping the permutation walk on. */
    public static function isAcceptable(string $status): bool
    {
        return $status === self::DELIVERABLE || $status === self::RISKY;
    }

    /**
     * Map a canonical status onto what the UI renders.
     *
     * A catch-all gets its own badge: it is a specific, nameable finding —
     * the domain accepts everything — and saying so is more honest than the
     * softer "plausible". `risky` stays "plausible, not proven". `unknown`
     * keeps its own, because folding it into `undeliverable` would report a
     * vendor outage as a finding about a person.
     */
    public static function forDisplay(string $status): string
    {
        return match ($status) {
            self::DELIVERABLE => 'deliverable',
            self::CATCH_ALL => 'catch_all',
            self::RISKY => 'accept_all',
            self::UNKNOWN => 'unknown',
            default => 'undeliverable',
        };
    }
}
