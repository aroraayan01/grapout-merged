<?php

namespace Tests\Feature;

use App\Services\GrapUp\Http\CircuitBreaker;
use App\Services\GrapUp\Verifier\Clearout;
use App\Services\GrapUp\Verifier\Hunter;
use App\Services\GrapUp\Verifier\Status;
use App\Services\GrapUp\Verifier\VerificationProvider;
use App\Services\GrapUp\Verifier\Verifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The rules about spending money.
 *
 * Nothing here makes a network call — the provider is a stub that counts how
 * often it was asked. That count is the assertion in most of these tests,
 * because the bug worth catching is not "the wrong status came back", it is
 * "we paid for something we already knew".
 */
class GrapUpVerifierTest extends TestCase
{
    use RefreshDatabase;

    private function stub(string $status, ?int &$calls = null): VerificationProvider
    {
        $calls = 0;

        return new class($status, $calls) implements VerificationProvider
        {
            public function __construct(private string $status, private int &$calls) {}

            public function name(): string
            {
                return 'stub';
            }

            public function verify(string $email): string
            {
                $this->calls++;

                return $this->status;
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function circuit(): array
            {
                return ['state' => 'closed', 'consecutive_failures' => 0, 'retry_in_seconds' => null];
            }
        };
    }

    // --- Not paying twice ---------------------------------------------------

    public function test_an_address_checked_before_costs_nothing(): void
    {
        $verifier = new Verifier($this->stub(Status::DELIVERABLE, $calls));

        $first = $verifier->verify('jane.doe@acme.com');
        $second = $verifier->verify('jane.doe@acme.com');

        $this->assertSame(Status::DELIVERABLE, $second->status);
        $this->assertTrue($first->paid);
        $this->assertFalse($second->paid, 'the second answer came from the cache');
        $this->assertSame('cache', $second->source);
        $this->assertSame(1, $calls, 'the vendor was asked once');
    }

    public function test_the_cache_is_keyed_on_the_address_not_its_spelling(): void
    {
        $verifier = new Verifier($this->stub(Status::DELIVERABLE, $calls));

        $verifier->verify('Jane.Doe@Acme.com');
        $verifier->verify('  jane.doe@acme.com ');

        $this->assertSame(1, $calls);
    }

    public function test_asking_for_a_fresh_answer_bypasses_the_cache(): void
    {
        $verifier = new Verifier($this->stub(Status::DELIVERABLE, $calls));

        $verifier->verify('jane.doe@acme.com');
        $outcome = $verifier->verify('jane.doe@acme.com', fresh: true);

        $this->assertTrue($outcome->paid);
        $this->assertSame(2, $calls);
    }

    public function test_a_stale_verdict_is_re_asked(): void
    {
        $verifier = new Verifier($this->stub(Status::DELIVERABLE, $calls));
        $verifier->verify('jane.doe@acme.com');

        // A verdict is a fact about a mailbox at a moment; people leave.
        DB::table('grap_verification_cache')->update([
            'checked_at' => now()->subDays((int) config('grapup.cache_ttl_days') + 1),
        ]);

        $verifier->verify('jane.doe@acme.com');

        $this->assertSame(2, $calls);
    }

    /**
     * The rule that keeps an outage from becoming permanent.
     *
     * Caching "unknown" would mean every future lookup reads the failure back
     * and never re-asks — five bad minutes turned into a hole that never heals.
     */
    public function test_a_failure_is_never_cached(): void
    {
        $verifier = new Verifier($this->stub(Status::UNKNOWN, $calls));

        $verifier->verify('jane.doe@acme.com');
        $verifier->verify('jane.doe@acme.com');

        $this->assertSame(2, $calls, 'we learned nothing, so we ask again');
        $this->assertDatabaseCount('grap_verification_cache', 0);
    }

    /**
     * The other half of not caching `unknown`: within one search the same
     * address is reached twice — a pattern probe, then that person's own
     * permutation run — and the walk opens a memo so the second is answered
     * from memory rather than paid for again. Without it, the fix that keeps
     * `unknown` out of the cache would double every failing check in a walk.
     */
    public function test_the_same_address_is_paid_for_once_within_a_search(): void
    {
        $verifier = new Verifier($this->stub(Status::UNKNOWN, $calls));
        $verifier->beginSearch();

        $verifier->verify('jane.doe@acme.com');
        $verifier->verify('jane.doe@acme.com');

        $this->assertSame(1, $calls, 'the second reached the memo, not the vendor');
    }

    /** A new search asks again — the memo is one walk's, not forever. */
    public function test_the_dedup_memo_does_not_leak_across_searches(): void
    {
        $verifier = new Verifier($this->stub(Status::UNKNOWN, $calls));

        $verifier->beginSearch();
        $verifier->verify('jane.doe@acme.com');
        $verifier->beginSearch();
        $verifier->verify('jane.doe@acme.com');

        $this->assertSame(2, $calls, 'a fresh search may find the vendor recovered');
    }

    // --- The ledger ---------------------------------------------------------

    public function test_every_paid_call_lands_in_the_ledger(): void
    {
        $user = \App\Models\User::factory()->create();
        $verifier = new Verifier($this->stub(Status::DELIVERABLE));

        $verifier->verify(
            'ravi@acme.com',
            context: Verifier::CONTEXT_SEARCH,
            user: $user,
            reference: 'Acme Importers',
            pattern: '{f}',
        );

        $this->assertDatabaseHas('grap_credit_ledger', [
            'email' => 'ravi@acme.com',
            'status' => Status::DELIVERABLE,
            'context' => 'search',
            'reference' => 'Acme Importers',
            'pattern' => '{f}',
            'user_id' => $user->id,
        ]);
    }

    public function test_a_cache_hit_is_not_recorded_as_spend(): void
    {
        // Credit counts have to stay honest, or "why did this cost 400
        // credits" gets an answer that is off by the cache hit rate.
        $verifier = new Verifier($this->stub(Status::DELIVERABLE));

        $verifier->verify('jane@acme.com');
        $verifier->verify('jane@acme.com');

        $this->assertDatabaseCount('grap_credit_ledger', 1);
    }

    /** A failure still cost a call, even though it taught us nothing. */
    public function test_a_failed_call_is_still_recorded(): void
    {
        $verifier = new Verifier($this->stub(Status::UNKNOWN));
        $verifier->verify('jane@acme.com');

        $this->assertDatabaseHas('grap_credit_ledger', ['status' => Status::UNKNOWN]);
    }

    // --- Vendor vocabularies ------------------------------------------------

    public function test_clearout_keeps_undeliverable_apart_from_unknown(): void
    {
        /*
         * Collapsing these two is how a billing lapse quietly deletes a week
         * of good contacts: out of credits reads as "invalid", and a thousand
         * real people get marked bad.
         */
        $this->assertSame(Status::UNDELIVERABLE, Clearout::mapStatus('invalid', null));
        $this->assertSame(Status::UNKNOWN, Clearout::mapStatus('unknown', null));
        $this->assertSame(Status::UNKNOWN, Clearout::mapStatus(null, null));
        $this->assertSame(Status::UNKNOWN, Clearout::mapStatus('something-new-the-vendor-added', null));
    }

    public function test_clearout_separates_catch_all_from_valid(): void
    {
        // A catch-all proves only that the domain exists.
        $this->assertSame(Status::DELIVERABLE, Clearout::mapStatus('valid', 'safe'));
        $this->assertSame(Status::CATCH_ALL, Clearout::mapStatus('catch_all', null));
        // Valid but risky to send to — a role mailbox, a full one. Worth
        // showing, not worth discarding.
        $this->assertSame(Status::RISKY, Clearout::mapStatus('valid', 'risky'));
    }

    public function test_hunter_checks_accept_all_before_anything_else(): void
    {
        /*
         * Hunter reports a deliverable result on a catch-all domain. Taking
         * that at face value ends the permutation walk on an address nothing
         * confirmed — and then teaches the domain a naming pattern from it.
         */
        $this->assertSame(Status::CATCH_ALL, Hunter::mapStatus('valid', 'deliverable', true));
        $this->assertSame(Status::DELIVERABLE, Hunter::mapStatus('valid', 'deliverable', false));
    }

    // --- What the UI is told ------------------------------------------------

    public function test_unknown_keeps_its_own_badge(): void
    {
        // Folding it into undeliverable reports a vendor outage as a finding
        // about a person.
        $this->assertSame('unknown', Status::forDisplay(Status::UNKNOWN));
        $this->assertSame('undeliverable', Status::forDisplay(Status::UNDELIVERABLE));
        // Risky and catch-all share one: both mean "plausible, not proven".
        $this->assertSame('catch_all', Status::forDisplay(Status::CATCH_ALL));
        $this->assertSame('accept_all', Status::forDisplay(Status::RISKY));
    }

    public function test_only_a_proven_or_risky_address_stops_the_walk(): void
    {
        $this->assertTrue(Status::isAcceptable(Status::DELIVERABLE));
        $this->assertTrue(Status::isAcceptable(Status::RISKY));
        // A catch-all has proved nothing about this particular address, so the
        // walk goes on rather than banking it.
        $this->assertFalse(Status::isAcceptable(Status::CATCH_ALL));
        $this->assertFalse(Status::isAcceptable(Status::UNKNOWN));
    }

    // --- The circuit --------------------------------------------------------

    public function test_the_circuit_opens_after_repeated_failures_and_lets_one_probe_through(): void
    {
        $breaker = new CircuitBreaker('test-vendor', failureThreshold: 3, cooldownSeconds: 0);

        $this->assertTrue($breaker->canAttempt());
        $breaker->recordFailure();
        $breaker->recordFailure();
        $this->assertTrue($breaker->canAttempt(), 'two failures is not enough to stop trying');

        $breaker->recordFailure();
        // Cooldown of zero means the next look moves it straight to half-open
        // and allows exactly one probe.
        $this->assertTrue($breaker->canAttempt());
        $this->assertFalse($breaker->canAttempt(), 'the probe is in flight; nothing else goes');

        $breaker->recordSuccess();
        $this->assertTrue($breaker->canAttempt(), 'the vendor is answering again');
    }

    /**
     * The departure from GrapUp worth pinning: there the breaker is a field on
     * a long-lived Node object. PHP has no process to hold one, so it lives in
     * the cache — and a breaker that did not survive the request would never
     * once stop a call.
     */
    public function test_the_circuit_survives_a_new_instance(): void
    {
        (new CircuitBreaker('shared-vendor', failureThreshold: 1, cooldownSeconds: 300))->recordFailure();

        $this->assertFalse(
            (new CircuitBreaker('shared-vendor', failureThreshold: 1, cooldownSeconds: 300))->canAttempt(),
            'another worker must not have to rediscover the same outage',
        );
    }
}
