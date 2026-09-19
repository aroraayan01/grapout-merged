<?php

namespace App\Services\GrapUp\Verifier;

use App\Models\User;
use App\Services\GrapUp\Prefilter\Inboxx;
use App\Services\GrapUp\Prefilter\PrefilterProvider;
use App\Services\GrapUp\Prefilter\PrefilterVerdict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one place the pipeline talks to a verification vendor.
 *
 * Everything about spending money is decided here:
 *
 *  - a cached verdict is returned without a call, and reported as unpaid, so
 *    credit counts stay honest rather than counting cache hits as spend;
 *  - a free tier is asked before the paid one, and what it can settle is
 *    settled for nothing — 88% of GrapUp's credits bought `undeliverable`,
 *    which is exactly what syntax, DNS and a directory lookup answer free;
 *  - a settled verdict is written back to the cache, but a failure never is,
 *    because caching an outage turns five bad minutes into a permanent hole;
 *  - every genuinely paid call lands in the ledger before the caller sees it.
 *
 * Providers normalise their own vocabulary to five canonical statuses, so
 * nothing downstream knows which vendor is configured.
 */
class Verifier
{
    /** Context labels for the ledger: what the pipeline was doing. */
    public const CONTEXT_SEARCH = 'search';

    public const CONTEXT_REVEAL = 'reveal';

    private VerificationProvider $provider;

    private ?PrefilterProvider $prefilter;

    /**
     * Addresses already answered in the current search, so none is paid for
     * twice within one company walk.
     *
     * Null until a search opens it, and only the walk opens it — a bare
     * verify() (a reveal, a test) has no memo and behaves exactly as before.
     * It has to be its own thing rather than lean on the cache, because the
     * cache deliberately never stores `unknown`, and `unknown` is precisely
     * the verdict the same address returns twice in one walk: the pattern
     * probe, then that person's own permutation run.
     */
    private ?array $searchMemo = null;

    /**
     * Calls actually made to each provider over this verifier's life, e.g.
     * ['inboxx' => 12, 'clearout' => 3]. Cache and memo hits are not calls.
     *
     * Never reset: the walk reads it before and after a pass and reports the
     * difference, so two passes over one search add up rather than overwrite.
     *
     * @var array<string, int>
     */
    private array $calls = [];

    public function __construct(?VerificationProvider $provider = null, ?PrefilterProvider $prefilter = null)
    {
        $this->provider = $provider ?? self::select();
        $this->prefilter = $prefilter ?? self::selectPrefilter();
    }

    /** Open a fresh dedup memo for one company walk. Called by the walk. */
    public function beginSearch(): void
    {
        $this->searchMemo = [];
    }

    /**
     * The free tier, when one is named and has a key.
     *
     * A missing key disables it rather than refusing to boot: verification
     * still works, it just costs what it cost before. An unrecognised name
     * disables it too — a typo should not take verification down.
     */
    private static function selectPrefilter(): ?PrefilterProvider
    {
        if ((string) config('grapup.verifier.prefilter') !== 'inboxx') {
            return null;
        }

        $inboxx = new Inboxx;

        return $inboxx->isConfigured() ? $inboxx : null;
    }

    /**
     * Is this free answer good enough to keep without paying?
     *
     * Two things settle an address, and they are not the same thing:
     *
     *  - `deliverable` and `undeliverable` are *proof*. The engine's design
     *    rule is that nothing is marked invalid without positive evidence, so
     *    when it says invalid it has evidence, and buying a second opinion
     *    buys nothing.
     *  - a confidence at or above the line is a *guess we have decided to
     *    trust* — but only when the guess is an actual verdict. An `unknown`
     *    carrying a high number is not a guess we trust; it is a shrug with a
     *    number on it, and it always escalates (below).
     *
     * Everything else — `catch_all`, and every `unknown` whatever its score —
     * escalates. `catch_all` escalating is the expensive half of this rule and
     * is deliberate: a server that accepts every address has proved nothing
     * about this one, the same reason the pipeline never treats a catch-all as
     * verified.
     */
    public static function settledByPrefilter(PrefilterVerdict $verdict): bool
    {
        if ($verdict->status === Status::DELIVERABLE || $verdict->status === Status::UNDELIVERABLE) {
            return true;
        }
        /*
         * A catch-all is decided by the trust setting alone — never by a
         * confidence number riding along with it. Inboxx attaches a high
         * confidence to catch-all answers too, and letting that fall through
         * to the threshold below settled every Havells guess for free as
         * `catch_all`: the expensive half of this rule silently switched off.
         */
        if ($verdict->status === Status::CATCH_ALL) {
            return (bool) config('grapup.verifier.prefilter_trust_catch_all');
        }
        /*
         * An `unknown` is never settled, whatever confidence rides with it.
         * "I could not tell" is not a verdict to trust — the number is stapled
         * to a shrug — so it always escalates to the paid tier. Skipping this
         * is how a flaky prefilter returned `unknown` at 80%+, had it trusted,
         * and blanked whole companies a run of shrugs at a time.
         */
        if ($verdict->status === Status::UNKNOWN) {
            return false;
        }
        if ($verdict->confidence === null) {
            return false;
        }

        return $verdict->confidence >= (int) config('grapup.verifier.prefilter_threshold');
    }

    private static function select(): VerificationProvider
    {
        return match ((string) config('grapup.verifier.provider')) {
            'hunter' => new Hunter,
            default => new Clearout,
        };
    }

    /**
     * Test seam. Returns a restore closure, so a test cannot forget to put the
     * real provider back and silently spend credits in the next file.
     */
    public function use(VerificationProvider $provider): callable
    {
        $previous = $this->provider;
        $this->provider = $provider;

        return function () use ($previous): void {
            $this->provider = $previous;
        };
    }

    /** The same seam for the free tier. */
    public function usePrefilter(?PrefilterProvider $prefilter): callable
    {
        $previous = $this->prefilter;
        $this->prefilter = $prefilter;

        return function () use ($previous): void {
            $this->prefilter = $previous;
        };
    }

    /**
     * Verify one address. Never throws.
     *
     * The cache is consulted first and written last, and only a call that
     * actually reached the vendor is recorded as spend.
     */
    public function verify(
        string $email,
        string $context = self::CONTEXT_SEARCH,
        ?User $user = null,
        ?string $reference = null,
        ?string $pattern = null,
        bool $fresh = false,
    ): VerifyOutcome {
        $email = mb_strtolower(trim($email));

        // Already answered in this search — reuse it, and never pay twice.
        if ($this->searchMemo !== null && ! $fresh && array_key_exists($email, $this->searchMemo)) {
            $memo = $this->searchMemo[$email];

            return new VerifyOutcome($memo['status'], paid: false, source: 'memo', by: $memo['by'], cached: $memo['cached']);
        }

        if (! $fresh) {
            $cached = $this->cached($email);
            if ($cached !== null) {
                $this->memo($email, $cached->status, $cached->provider, true);

                return new VerifyOutcome($cached->status, paid: false, source: 'cache', by: $cached->provider, cached: true);
            }
        }

        /*
         * The free tier first. A verdict it can settle costs nothing and is
         * cached exactly like a bought one — it is an answer about the
         * address, and where an answer came from does not change what it says.
         *
         * Skipped on a `fresh` request: somebody asking to pay for a fresh
         * answer means the paid one.
         */
        if ($this->prefilter !== null && ! $fresh) {
            $verdict = null;
            try {
                $verdict = $this->prefilter->check($email);
            } catch (\Throwable $e) {
                // A prefilter that throws is a prefilter that said nothing.
                Log::warning('grapup: prefilter failed', ['email' => $email, 'error' => $e->getMessage()]);
            }

            // Counted only when it answered. A timeout or an open circuit
            // returns null having told us nothing.
            if ($verdict !== null) {
                $this->count($this->prefilter->name());
            }

            if ($verdict !== null && self::settledByPrefilter($verdict)) {
                Log::debug('grapup: settled without paying', [
                    'email' => $email, 'status' => $verdict->status, 'tier' => $verdict->tier,
                ]);
                $this->remember($email, $verdict->status, $this->prefilter->name());
                $this->memo($email, $verdict->status, $this->prefilter->name(), false);

                return new VerifyOutcome($verdict->status, paid: false, source: 'prefilter', by: $this->prefilter->name());
            }
        }

        $status = $this->provider->verify($email);
        $this->count($this->provider->name());

        $this->record($email, $status, $context, $user, $reference, $pattern);

        // Written unconditionally; remember() itself refuses to cache `unknown`.
        $this->remember($email, $status);
        $this->memo($email, $status, $this->provider->name(), false);

        return new VerifyOutcome($status, paid: true, source: 'vendor', by: $this->provider->name());
    }

    /** @return array<string, int> calls made to each provider so far */
    public function calls(): array
    {
        return $this->calls;
    }

    private function count(string $provider): void
    {
        $this->calls[$provider] = ($this->calls[$provider] ?? 0) + 1;
    }

    /** A verdict from before, with the provider that gave it, if it has not gone stale. */
    private function cached(string $email): ?object
    {
        $days = (int) config('grapup.cache_ttl_days');

        return DB::table('grap_verification_cache')
            ->where('email', $email)
            ->where('checked_at', '>=', now()->subDays($days))
            ->first(['status', 'provider']);
    }

    /** Record an answer for the rest of this search, when a search is open. */
    private function memo(string $email, string $status, ?string $by, bool $cached): void
    {
        if ($this->searchMemo !== null) {
            $this->searchMemo[$email] = ['status' => $status, 'by' => $by, 'cached' => $cached];
        }
    }

    private function remember(string $email, string $status, ?string $provider = null): void
    {
        /*
         * Only a real verdict is a fact about the address. `unknown` means the
         * check learned nothing — a timeout, a bad token, a spent quota — and
         * caching it turns five bad minutes into a permanent hole: every future
         * lookup would read "unknown" and never re-ask. This is the one gate,
         * so the free tier and the paid one are both held to it — a settled
         * prefilter verdict is written here too.
         */
        if ($status === Status::UNKNOWN) {
            return;
        }

        try {
            DB::table('grap_verification_cache')->updateOrInsert(
                ['email' => $email],
                ['status' => $status, 'provider' => $provider ?? $this->provider->name(), 'checked_at' => now()],
            );
        } catch (\Throwable $e) {
            // A cache that cannot be written is slower, not wrong.
            Log::warning('grapup: could not cache a verdict', ['email' => $email, 'error' => $e->getMessage()]);
        }
    }

    /** Every paid call, so "why did this month cost 400 credits" has an answer. */
    private function record(
        string $email,
        string $status,
        string $context,
        ?User $user,
        ?string $reference,
        ?string $pattern,
    ): void {
        try {
            DB::table('grap_credit_ledger')->insert([
                'occurred_at' => now(),
                'provider' => $this->provider->name(),
                'email' => $email,
                'status' => $status,
                'context' => $context,
                'reference' => $reference,
                'pattern' => $pattern,
                'user_id' => $user?->id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('grapup: could not write the ledger', ['email' => $email, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Can this domain receive mail at all?
     *
     * Free, and run before anything paid: a domain with no MX cannot receive
     * mail, so every permutation at it is undeliverable and checking even one
     * of them is a credit spent to learn nothing. The answer is kept per
     * domain, because it is a fact about the domain rather than the address.
     */
    public function domainAcceptsMail(string $domain): bool
    {
        $domain = mb_strtolower(trim($domain));
        $days = (int) config('grapup.mx_ttl_days');

        $fact = DB::table('grap_domain_facts')->where('domain', $domain)->first();
        if ($fact !== null && $fact->has_mx !== null && $fact->mx_checked_at !== null
            && now()->diffInDays($fact->mx_checked_at) < $days) {
            return (bool) $fact->has_mx;
        }

        // getmxrr is the whole check. A domain with no MX and no A record
        // cannot take delivery; one with an A record but no MX still can, by
        // the implicit-MX rule, so both are asked.
        $records = [];
        $hasMx = @getmxrr($domain, $records) && $records !== [];
        if (! $hasMx) {
            $hasMx = checkdnsrr($domain, 'A');
        }

        try {
            DB::table('grap_domain_facts')->updateOrInsert(
                ['domain' => $domain],
                ['has_mx' => $hasMx, 'mx_checked_at' => now(), 'last_checked_at' => now()],
            );
        } catch (\Throwable) {
            // Not knowing is survivable; it costs one DNS lookup next time.
        }

        return $hasMx;
    }

    public function describe(): string
    {
        return $this->prefilter === null
            ? $this->provider->name()
            : $this->prefilter->name() . ' -> ' . $this->provider->name();
    }

    public function circuit(): array
    {
        return $this->provider->circuit();
    }

    public function isConfigured(): bool
    {
        return $this->provider->isConfigured();
    }
}
