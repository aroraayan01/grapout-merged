<?php

namespace Tests\Feature;

use App\Services\GrapUp\Prefilter\Inboxx;
use App\Services\GrapUp\Prefilter\PrefilterProvider;
use App\Services\GrapUp\Prefilter\PrefilterVerdict;
use App\Services\GrapUp\Verifier\Status;
use App\Services\GrapUp\Verifier\VerificationProvider;
use App\Services\GrapUp\Verifier\Verifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The free tier, and the line between a free answer worth keeping and one
 * worth paying to settle.
 *
 * The prize is not subtle. Of GrapUp's last 2,887 credits, 2,528 — 88% —
 * bought the verdict `undeliverable`, and those are exactly the addresses
 * syntax, DNS and a directory lookup answer for nothing. What every test here
 * asserts is some version of "and the paid provider was never asked".
 */
class GrapUpPrefilterTest extends TestCase
{
    use RefreshDatabase;

    /** A paid provider that counts how often it was reached. */
    private function paid(string $status, ?int &$calls = null): VerificationProvider
    {
        $calls = 0;

        return new class($status, $calls) implements VerificationProvider
        {
            public function __construct(private string $status, private int &$calls) {}

            public function name(): string
            {
                return 'paid';
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

    /** A free tier that answers with whatever the test hands it. */
    private function free(?PrefilterVerdict $verdict, ?int &$calls = null): PrefilterProvider
    {
        $calls = 0;

        return new class($verdict, $calls) implements PrefilterProvider
        {
            public function __construct(private ?PrefilterVerdict $verdict, private int &$calls) {}

            public function name(): string
            {
                return 'free';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function check(string $email): ?PrefilterVerdict
            {
                $this->calls++;

                return $this->verdict;
            }
        };
    }

    // --- What the free tier settles ------------------------------------------

    /**
     * The 88% case. An address the engine proved does not exist never reaches
     * the paid tier at all.
     */
    public function test_a_free_undeliverable_verdict_is_never_paid_for(): void
    {
        $verifier = new Verifier(
            $this->paid(Status::DELIVERABLE, $paidCalls),
            $this->free(new PrefilterVerdict(Status::UNDELIVERABLE, null, 'microsoft')),
        );

        $outcome = $verifier->verify('nobody@acme.com');

        $this->assertSame(Status::UNDELIVERABLE, $outcome->status);
        $this->assertFalse($outcome->paid);
        $this->assertSame('prefilter', $outcome->source);
        $this->assertSame(0, $paidCalls, 'the vendor was never asked');
        $this->assertDatabaseCount('grap_credit_ledger', 0);
    }

    public function test_a_free_deliverable_verdict_is_kept_too(): void
    {
        // Proof is proof. Buying a second opinion buys nothing.
        $verifier = new Verifier(
            $this->paid(Status::UNDELIVERABLE, $paidCalls),
            $this->free(new PrefilterVerdict(Status::DELIVERABLE, null, 'microsoft')),
        );

        $this->assertSame(Status::DELIVERABLE, $verifier->verify('ravi@acme.com')->status);
        $this->assertSame(0, $paidCalls);
    }

    /**
     * A free answer is cached exactly like a bought one. It is an answer about
     * the address, and where it came from does not change what it says.
     */
    public function test_a_free_verdict_is_cached_and_credited_to_the_tier_that_gave_it(): void
    {
        $verifier = new Verifier(
            $this->paid(Status::DELIVERABLE),
            $this->free(new PrefilterVerdict(Status::UNDELIVERABLE, null, 'local'), $freeCalls),
        );

        $verifier->verify('nobody@acme.com');
        $verifier->verify('nobody@acme.com');

        $this->assertSame(1, $freeCalls, 'the second answer came from the cache, not the tier');
        $this->assertDatabaseHas('grap_verification_cache', [
            'email' => 'nobody@acme.com', 'provider' => 'free',
        ]);
    }

    /**
     * Every answer names the provider behind it, and every call is counted
     * against the provider it went to — so a search can say how many Inboxx
     * and how many Clearout credits it used, and which one answered each person.
     */
    public function test_each_answer_names_its_provider_and_each_call_is_counted(): void
    {
        // The free tier settles it: one free call, no paid one.
        $settled = new Verifier(
            $this->paid(Status::DELIVERABLE),
            $this->free(new PrefilterVerdict(Status::UNDELIVERABLE, null, 'microsoft')),
        );
        $this->assertSame('free', $settled->verify('nobody@acme.com')->by);
        $this->assertSame(['free' => 1], $settled->calls());

        // The free tier shrugs: both are asked, and the paid one answers.
        $escalated = new Verifier(
            $this->paid(Status::DELIVERABLE),
            $this->free(new PrefilterVerdict(Status::UNKNOWN, null, 'pattern')),
        );
        $first = $escalated->verify('ravi@acme.com');
        $this->assertSame('paid', $first->by);
        $this->assertFalse($first->cached);
        $this->assertSame(['free' => 1, 'paid' => 1], $escalated->calls());

        // Asked again: read back from the cache, still credited to who gave it,
        // and no call to anyone.
        $again = $escalated->verify('ravi@acme.com');
        $this->assertSame('paid', $again->by);
        $this->assertTrue($again->cached);
        $this->assertSame(['free' => 1, 'paid' => 1], $escalated->calls(), 'a cached answer is not a call');
    }

    public function test_a_prefilter_that_did_not_answer_is_not_counted_as_used(): void
    {
        $verifier = new Verifier($this->paid(Status::DELIVERABLE), $this->free(null));

        $verifier->verify('ravi@acme.com');

        $this->assertSame(['paid' => 1], $verifier->calls());
    }

    // --- What escalates ------------------------------------------------------

    /**
     * A catch-all proves nothing about this particular address, which is the
     * same reason the pipeline never treats one as verified. So it escalates —
     * the expensive half of the rule, and deliberate.
     */
    public function test_a_free_catch_all_escalates_by_default(): void
    {
        $verifier = new Verifier(
            $this->paid(Status::DELIVERABLE, $paidCalls),
            $this->free(new PrefilterVerdict(Status::CATCH_ALL, null, 'smtp')),
        );

        $outcome = $verifier->verify('ravi@acme.com');

        $this->assertTrue($outcome->paid);
        $this->assertSame(1, $paidCalls);
    }

    /**
     * The Havells regression. Inboxx sends a high confidence with a catch-all,
     * and that number used to fall through to the threshold and settle it —
     * every guess kept for free as catch_all, Clearout never asked.
     */
    public function test_a_confident_catch_all_still_escalates(): void
    {
        $verifier = new Verifier(
            $this->paid(Status::DELIVERABLE, $paidCalls),
            $this->free(new PrefilterVerdict(Status::CATCH_ALL, 95, 'vendor')),
        );

        $outcome = $verifier->verify('ranjit.kumar@havells.com');

        $this->assertSame(1, $paidCalls, 'a confidence number does not settle a catch-all');
        $this->assertSame('paid', $outcome->by);
    }

    public function test_a_catch_all_can_be_trusted_for_anyone_who_prefers_the_finding(): void
    {
        config()->set('grapup.verifier.prefilter_trust_catch_all', true);

        $verifier = new Verifier(
            $this->paid(Status::DELIVERABLE, $paidCalls),
            $this->free(new PrefilterVerdict(Status::CATCH_ALL, null, 'smtp')),
        );

        $this->assertFalse($verifier->verify('ravi@acme.com')->paid);
        $this->assertSame(0, $paidCalls);
    }

    /** An unscored guess is not an answer, whatever it guessed. */
    public function test_an_unknown_with_no_confidence_escalates(): void
    {
        $verifier = new Verifier(
            $this->paid(Status::DELIVERABLE, $paidCalls),
            $this->free(new PrefilterVerdict(Status::UNKNOWN, null, 'pattern')),
        );

        $this->assertTrue($verifier->verify('ravi@acme.com')->paid);
        $this->assertSame(1, $paidCalls);
    }

    /**
     * An `unknown` never settles, however high the confidence stapled to it —
     * "I could not tell" is not a verdict to trust. It escalates to the paid
     * tier, the only thing that stops a flaky prefilter blanking a whole
     * company a run of shrugs at a time. And since the answer that comes back
     * is the vendor's, nothing false is left in the cache either.
     */
    public function test_a_high_confidence_unknown_still_escalates(): void
    {
        config()->set('grapup.verifier.prefilter_threshold', 80);

        $verifier = new Verifier(
            $this->paid(Status::DELIVERABLE, $paidCalls),
            $this->free(new PrefilterVerdict(Status::UNKNOWN, 99, 'pattern')),
        );

        $outcome = $verifier->verify('ravi@acme.com');

        $this->assertTrue($outcome->paid, 'a shrug with a number on it is still a shrug');
        $this->assertSame(1, $paidCalls);
        $this->assertSame(Status::DELIVERABLE, $outcome->status, 'the paid tier gave the real answer');
    }

    // --- The confidence line -------------------------------------------------

    /**
     * The threshold does not apply to an `unknown` at all — it escalates below
     * and above the line alike, because the number is stapled to a shrug, not
     * to a verdict. (The line still gates a real guess; `unknown` is simply not
     * one.)
     */
    public function test_an_unknown_escalates_below_and_above_the_line_alike(): void
    {
        config()->set('grapup.verifier.prefilter_threshold', 80);

        foreach ([60, 85] as $confidence) {
            $verifier = new Verifier(
                $this->paid(Status::DELIVERABLE, $paidCalls),
                $this->free(new PrefilterVerdict(Status::UNKNOWN, $confidence, 'pattern')),
            );
            $this->assertTrue($verifier->verify("c{$confidence}@acme.com")->paid, "unknown at {$confidence}% must still be bought");
            $this->assertSame(1, $paidCalls);
        }
    }

    // --- When the free tier says nothing -------------------------------------

    /**
     * The distinction the whole class exists for. `unknown` is a finding,
     * which would be cached and would stop the address ever reaching the paid
     * tier. Null means the prefilter said nothing.
     */
    public function test_a_prefilter_that_cannot_be_reached_costs_nothing_and_blocks_nothing(): void
    {
        $verifier = new Verifier(
            $this->paid(Status::DELIVERABLE, $paidCalls),
            $this->free(null),  // quota exhausted, circuit open, service down
        );

        $outcome = $verifier->verify('ravi@acme.com');

        $this->assertTrue($outcome->paid, 'the paid tier decides when the free one abstains');
        $this->assertSame(1, $paidCalls);
        $this->assertSame(Status::DELIVERABLE, $outcome->status);
    }

    public function test_a_prefilter_that_throws_is_a_prefilter_that_said_nothing(): void
    {
        $throwing = new class implements PrefilterProvider
        {
            public function name(): string
            {
                return 'broken';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function check(string $email): ?PrefilterVerdict
            {
                throw new \RuntimeException('boom');
            }
        };

        $verifier = new Verifier($this->paid(Status::DELIVERABLE, $paidCalls), $throwing);

        $this->assertSame(Status::DELIVERABLE, $verifier->verify('ravi@acme.com')->status);
        $this->assertSame(1, $paidCalls);
    }

    /** Asking to pay for a fresh answer means the paid one. */
    public function test_a_fresh_request_skips_the_free_tier(): void
    {
        $verifier = new Verifier(
            $this->paid(Status::DELIVERABLE, $paidCalls),
            $this->free(new PrefilterVerdict(Status::UNDELIVERABLE, null, 'local'), $freeCalls),
        );

        $verifier->verify('ravi@acme.com', fresh: true);

        $this->assertSame(0, $freeCalls);
        $this->assertSame(1, $paidCalls);
    }

    // --- The vocabulary ------------------------------------------------------

    public function test_the_engine_has_no_risky_so_uncertainty_arrives_as_unknown(): void
    {
        /*
         * Its design rule is that an address is only marked invalid on
         * positive proof and everything uncertain escalates, so there is no
         * hedge to map.
         */
        $this->assertSame(Status::DELIVERABLE, Inboxx::mapStatus('valid'));
        $this->assertSame(Status::UNDELIVERABLE, Inboxx::mapStatus('invalid'));
        $this->assertSame(Status::UNDELIVERABLE, Inboxx::mapStatus('disposable'));
        $this->assertSame(Status::CATCH_ALL, Inboxx::mapStatus('catch_all'));
        $this->assertSame(Status::UNKNOWN, Inboxx::mapStatus('something-new'));
        $this->assertSame(Status::UNKNOWN, Inboxx::mapStatus(null));
    }

    public function test_the_chain_is_named_in_full_so_a_health_check_says_which_tiers_are_live(): void
    {
        $chained = new Verifier($this->paid(Status::DELIVERABLE), $this->free(null));
        $this->assertSame('free -> paid', $chained->describe());

        $alone = new Verifier($this->paid(Status::DELIVERABLE), null);
        $this->assertSame('paid', $alone->describe());
    }
}
