<?php

namespace App\Services\GrapUp\Pipeline;

use App\Models\User;
use App\Services\GrapUp\Candidate;
use App\Services\GrapUp\Contact;
use App\Services\GrapUp\Domain\Parse;
use App\Services\GrapUp\DomainFacts;
use App\Services\GrapUp\EmailPatterns;
use App\Services\GrapUp\PersonName;
use App\Services\GrapUp\Verifier\Status;
use App\Services\GrapUp\Verifier\Verifier;
use Illuminate\Support\Facades\Log;

/**
 * The verification walk: the only part of the pipeline that spends money.
 *
 * The order of operations is the whole design, because every step exists to
 * avoid paying for the one after it:
 *
 *  1. **No MX** — the domain cannot receive mail, so every permutation is
 *     guaranteed waste. One free DNS lookup, cached per domain.
 *  2. **A pattern this domain already taught us** — one check instead of a
 *     sweep. This is where the domain-fact cache earns its keep: the second
 *     contact at a known domain, and every contact after, costs one credit.
 *  3. **Every contact, hint first.** Whatever pattern last worked leads the
 *     next person's candidates, so a company with a convention answers most
 *     people on their first check.
 *  4. **Nobody is handed an unchecked address.** The pattern orders guesses;
 *     it never stands in for an answer. An earlier version of GrapUp read one
 *     hit as the company's convention and applied it to everyone unchecked,
 *     which is how Pardaen shipped nine addresses that do not exist.
 *  5. **Catch-all is not the end of the road.** A catch-all server accepts
 *     every mailbox, so a naive reading marks all twenty candidates valid and
 *     ships twenty fakes — but the server does not always answer identically.
 *     A clean `deliverable` among `catch_all` siblings is the real mailbox
 *     identifying itself.
 *
 * Ported from GrapUp's `pipeline/verify.ts`.
 */
class VerificationWalk
{
    public function __construct(private readonly Verifier $verifier) {}

    /**
     * Is this verdict good enough to ship?
     *
     * On an ordinary domain, deliverable or risky both mean a mailbox is
     * there. On a catch-all the server says yes to everything, so only a
     * verdict that *differs* from that baseline carries information — and
     * `risky` is the server hedging about a mailbox it would have accepted
     * regardless.
     */
    private function accepts(string $status, bool $isCatchAll): bool
    {
        return $isCatchAll ? $status === Status::DELIVERABLE : Status::isAcceptable($status);
    }

    /**
     * Walk a candidate list until one verifies, the vendor stops answering, or
     * the credit budget runs out.
     *
     * @param  list<Candidate>  $candidates
     */
    public function sweep(array $candidates, string $domain, WalkOptions $options): WalkResult
    {
        $creditsSpent = 0;
        $checked = 0;
        $unknownStreak = 0;
        $isCatchAll = $options->knownCatchAll ?? false;
        $sawCatchAll = $isCatchAll;

        $catchAllMax = (int) config('grapup.verifier.catch_all_max_checks', 4);
        $streakLimit = (int) config('grapup.verifier.unknown_streak_limit', 3);

        // A catch-all sweep is speculative by nature — it is hunting for one
        // mailbox that answers differently from all the others — so it gets a
        // tighter ceiling than a sweep that can actually prove something.
        $budget = $isCatchAll ? min($options->budget, $catchAllMax) : $options->budget;

        foreach ($candidates as $candidate) {
            // The budget bounds paid calls, not cache hits — a cached verdict
            // costs nothing, so letting it consume budget would throw away
            // free answers.
            if ($creditsSpent >= $budget) {
                Log::warning('grapup: budget exhausted', ['budget' => $budget]);
                break;
            }

            /*
             * The catch-all ceiling counts checks, not only paid ones. A hunt
             * on a catch-all is bounded by how long it is worth looking, and a
             * free answer takes as long as a bought one: counting credits alone
             * let free catch-all verdicts walk Havells' whole twenty-guess list
             * for one person, a minute spent learning the same thing twenty times.
             */
            if ($isCatchAll && $checked >= $catchAllMax) {
                break;
            }

            $outcome = $this->verifier->verify(
                $candidate->email,
                context: $options->context,
                user: $options->user,
                reference: $options->reference,
                pattern: $candidate->template,
            );
            $checked++;
            if ($outcome->paid) {
                $creditsSpent++;
            }

            if ($this->accepts($outcome->status, $isCatchAll)) {
                return new WalkResult(
                    email: $candidate->email,
                    template: $candidate->template,
                    status: $outcome->status,
                    catchAll: $sawCatchAll,
                    creditsSpent: $creditsSpent,
                    checked: $checked,
                    vendorUnavailable: false,
                    verifiedBy: $outcome->by,
                    fromCache: $outcome->cached,
                );
            }

            /*
             * The first catch-all verdict establishes the domain's baseline.
             * It does not end the walk — it changes what counts as a hit, and
             * tightens the budget, because from here only a standout answer
             * means anything.
             */
            if ($outcome->status === Status::CATCH_ALL && ! $isCatchAll) {
                $isCatchAll = true;
                $sawCatchAll = true;
                Log::info('grapup: catch-all baseline established, hunting for a standout', ['domain' => $domain]);
                DomainFacts::recordCatchAll($domain, true);
                if ($checked >= $catchAllMax) {
                    break;
                }
            }

            /*
             * `unknown` is not a miss — it is the check itself failing, so it
             * is no reason to move on to the next candidate. A run of them is
             * the vendor, and continuing would spend the whole budget
             * discovering the same outage twenty times.
             */
            $unknownStreak = $outcome->status === Status::UNKNOWN ? $unknownStreak + 1 : 0;
            if ($unknownStreak >= $streakLimit) {
                Log::error('grapup: vendor appears unavailable, abandoning the walk', [
                    'provider' => $this->verifier->describe(), 'checked' => $checked,
                ]);

                return new WalkResult(null, null, Status::UNKNOWN, $sawCatchAll, $creditsSpent, $checked, true);
            }

            if ($isCatchAll && $checked >= $catchAllMax) {
                break;
            }
        }

        if ($checked > 0 && ! $sawCatchAll) {
            // The expensive outcome, and the easy one to miss: every candidate
            // tested, none accepted. Worth a line, because a run quietly
            // spending the full budget looks identical to a fast one.
            Log::warning('grapup: nothing verified — the domain may be wrong', [
                'domain' => $domain, 'checked' => $checked, 'credits' => $creditsSpent,
            ]);
        }

        return new WalkResult(
            null, null,
            $sawCatchAll ? Status::CATCH_ALL : Status::UNDELIVERABLE,
            $sawCatchAll, $creditsSpent, $checked, false,
        );
    }

    /**
     * Verify contacts one at a time, using the last pattern that worked as the
     * first guess for the next.
     *
     * There is no step here that decides a pattern is "the company's". That
     * decision is what kept going wrong in GrapUp: a colleague's address
     * failing has two explanations — the pattern is wrong, or that colleague
     * has no mailbox — and nothing in the data separates them. Tuning how many
     * colleagues had to disagree just moved the error around.
     *
     * So the pattern is demoted from a claim to a hint. It orders the next
     * person's candidates, nothing more. The result is that every address
     * shipped has been checked.
     *
     * @param  list<Contact>  $contacts
     */
    public function verifyAndPropagate(
        array $contacts,
        string $domain,
        string $context,
        ?User $user = null,
        ?string $reference = null,
        ?int $budget = null,
    ): PropagationResult {
        // What each provider was asked during this pass, whichever of the
        // walk's many exits it leaves by.
        $before = $this->verifier->calls();
        $result = $this->propagate($contacts, $domain, $context, $user, $reference, $budget);

        $calls = [];
        foreach ($this->verifier->calls() as $provider => $total) {
            $made = $total - ($before[$provider] ?? 0);
            if ($made > 0) {
                $calls[$provider] = $made;
            }
        }

        return $result->withCalls($calls);
    }

    /** @param  list<Contact>  $contacts */
    private function propagate(
        array $contacts,
        string $domain,
        string $context,
        ?User $user,
        ?string $reference,
        ?int $budget,
    ): PropagationResult {
        $budget ??= (int) config('grapup.budget.max_verifications_per_search', 25);

        // One dedup memo for this whole walk: the pattern probe and a person's
        // own permutation run reach the same address, and without this the
        // second is paid for again because `unknown` is never cached.
        $this->verifier->beginSearch();

        if ($contacts === []) {
            return new PropagationResult($contacts, null, 0, false);
        }

        /*
         * Drop the placeholders before anything else, so this method owns the
         * address field outright.
         *
         * Sourcing fills each contact with a guess built from their first
         * name, to keep the row from looking empty while it is still locked.
         * Anyone the walk never reaches would otherwise keep that guess and
         * ship it. Windsor came back with eight addresses and no pattern that
         * way: every one invented, none checked, all reported as found.
         */
        foreach ($contacts as $contact) {
            $contact->email = null;
            $contact->status = 'locked';
        }

        /*
         * Never permute on a shared mailbox host.
         *
         * There is no company naming pattern on gmail.com, and worse: a
         * permutation there verifies as valid because a real stranger owns
         * that mailbox. The result is a confident wrong answer
         * indistinguishable from a right one, and a "pattern" learned from
         * somebody unconnected to the company.
         */
        if (Parse::isFreeMailDomain($domain)) {
            Log::warning('grapup: refusing to permute on a shared mailbox host', ['domain' => $domain]);

            return new PropagationResult($this->markAll($contacts, 'locked'), null, 0, false);
        }

        $known = DomainFacts::get($domain);

        /*
         * A domain with no mail exchanger cannot accept mail at any address,
         * so the whole walk is guaranteed to come back empty. One free DNS
         * lookup replaces up to twenty paid checks — and once recorded,
         * replaces the lookup too.
         */
        if (! $this->verifier->domainAcceptsMail($domain)) {
            Log::info('grapup: domain cannot receive mail, skipping verification entirely', ['domain' => $domain]);

            return new PropagationResult($this->markAll($contacts, 'undeliverable'), null, 0, false);
        }

        $pattern = $known?->verified_pattern;
        $creditsSpent = 0;

        /*
         * A catch-all domain is the one case where checking everybody cannot
         * work. The server accepts every address, so a per-person check
         * answers yes for a mailbox that does not exist, and thirteen people
         * would come back thirteen for thirteen — every one a fiction wearing
         * a verified badge.
         */
        if ((bool) ($known?->is_catchall ?? false) === true) {
            return $this->catchAllTeam($contacts, $domain, $pattern, null, new WalkOptions(
                budget: $budget, context: $context, user: $user, reference: $reference, knownCatchAll: true,
            ));
        }

        /** @var \SplObjectStorage<Contact, ?string> what hint each person was last asked under */
        $askedUnder = new \SplObjectStorage;
        $walked = new \SplObjectStorage;

        $spend = function (Contact $contact, bool $deep, bool $hintOnly = false)
            use ($domain, $context, $user, $reference, $budget, &$creditsSpent, &$pattern, $askedUnder, $known): ?WalkResult {
            $askedUnder[$contact] = $pattern;

            $split = EmailPatterns::splitByDepth(
                EmailPatterns::candidates($contact->name(), $domain),
                (int) config('grapup.common_depth'),
                $pattern,
            );
            // splitByDepth promotes the hint to the front, so the cheap probe
            // is the first candidate and nothing else.
            $all = [...$split['common'], ...$split['tail']];
            $candidates = $hintOnly ? array_slice($all, 0, 1) : ($deep ? $all : $split['common']);

            $allowance = min(count($candidates), $budget - $creditsSpent);
            if ($allowance <= 0) {
                return null;
            }

            $pass = $this->sweep($candidates, $domain, new WalkOptions(
                budget: $allowance, context: $context, user: $user, reference: $reference,
                knownCatchAll: $known?->is_catchall === null ? null : (bool) $known->is_catchall,
            ));
            $creditsSpent += $pass->creditsSpent;

            if ($pass->email === null) {
                // The placeholder guessed from a first name is worth nothing,
                // and leaving it in place is what once made a failed pass look
                // like a successful one.
                $contact->email = null;
                $contact->status = $pass->vendorUnavailable ? 'unknown' : 'locked';
                $contact->verifiedBy = null;
                $contact->fromCache = false;

                return $pass;
            }

            $contact->email = $pass->email;
            $contact->status = Status::forDisplay($pass->status);
            $contact->verifiedBy = $pass->verifiedBy;
            $contact->fromCache = $pass->fromCache;

            // Whatever worked for this person leads for the next. A role
            // mailbox teaches nothing — "sales.uk@" is shaped exactly like
            // first.last — so detect() refuses those and the hint stands.
            $learned = EmailPatterns::detect(Parse::localPart($pass->email), $contact->name());
            if ($learned !== null && $learned !== $pattern) {
                $pattern = $learned;
                DomainFacts::recordVerifiedPattern($domain, $learned);
            }

            return $pass;
        };

        /*
         * Confirmed employees first, whatever order they arrived in. They are
         * the ones worth going deep on, and the sooner one turns up a pattern
         * the cheaper everybody else becomes. The array itself is left alone —
         * this is the order of spend, not the order of the answer.
         */
        $queue = $contacts;
        usort($queue, fn (Contact $a, Contact $b) => (int) $b->corroborated <=> (int) $a->corroborated);

        $pending = fn () => array_values(array_filter($queue, fn (Contact $c) => $c->email === null));

        $target = (int) config('grapup.verifier.target_contacts', 3);
        $probeLimit = (int) config('grapup.verifier.pattern_probes', 3);
        $deadEnd = (int) config('grapup.verifier.dead_end_walks', 3);

        $verified = 0;
        $asked = 0;

        while ($verified < $target) {
            // ── Offer a working pattern around, one check a head ────────────
            if ($pattern !== null) {
                $probes = 0;
                foreach ($pending() as $contact) {
                    if ($verified >= $target || $probes >= $probeLimit) {
                        break;
                    }
                    // Already asked under this exact hint — nothing to learn.
                    if ($askedUnder->contains($contact) && $askedUnder[$contact] === $pattern) {
                        continue;
                    }
                    $probes++;

                    $probe = $spend($contact, false, true);
                    if ($probe === null) {
                        break 2;
                    }
                    if ($probe->vendorUnavailable) {
                        return $this->outage($contacts, $creditsSpent);
                    }
                    if ($probe->email !== null) {
                        $verified++;
                    }
                }
                if ($verified >= $target) {
                    break;
                }
            }

            /*
             * Who to ask next, and how hard. Somebody nobody has asked yet is
             * the cheap question, so they go first. Only when everybody has
             * been asked once is it worth going back over someone the whole
             * way.
             */
            $fresh = null;
            foreach ($pending() as $contact) {
                if (! $askedUnder->contains($contact)) {
                    $fresh = $contact;
                    break;
                }
            }

            $next = $fresh;
            if ($next === null) {
                foreach ($pending() as $contact) {
                    if (! $walked->contains($contact)) {
                        $next = $contact;
                        break;
                    }
                }
            }
            if ($next === null) {
                break;
            }

            /*
             * The long walk is how an unusual convention is discovered, and it
             * is worth doing twice at most.
             *
             * The first goes to whoever the sort put first — the likeliest to
             * hold a personal address at all. Everybody after gets the common
             * few, because asking more people cheaply beats asking fewer
             * expensively: JAGO's first two contacts genuinely have no
             * mailbox, and two full walks wrote the domain off while Simon
             * Jago, third in the list, holds simon.jago@ — the commonest
             * pattern there is, one check away.
             */
            $deep = $asked === 0 || ($fresh === null && $pattern !== null);
            $asked++;
            if ($deep) {
                $walked->attach($next);
            }

            $pass = $spend($next, $deep);
            if ($pass === null) {
                break;
            }
            if ($pass->vendorUnavailable) {
                return $this->outage($contacts, $creditsSpent);
            }

            if ($pass->catchAll) {
                Log::info('grapup: domain turned out to be catch-all, switching to plausible answers', ['domain' => $domain]);
                $team = $this->catchAllTeam($contacts, $domain, $pattern, $pass, new WalkOptions(
                    budget: max(0, $budget - $creditsSpent), context: $context,
                    user: $user, reference: $reference, knownCatchAll: true,
                ));

                return new PropagationResult(
                    $team->contacts, $team->corporateFormat,
                    $creditsSpent + $team->creditsSpent, $team->vendorUnavailable,
                );
            }

            if ($pass->email !== null) {
                $verified++;
                continue;
            }

            /*
             * Give up on the company, not on the person. A run of people with
             * no address at all is the domain saying it does not issue
             * personal mailboxes. Windsor spent fifty-two credits establishing
             * that one person at a time before this existed.
             */
            if ($asked >= $deadEnd) {
                Log::info('grapup: nobody here has a personal mailbox, so the rest of the list is not worth asking', [
                    'domain' => $domain, 'asked' => $asked, 'credits' => $creditsSpent,
                ]);
                break;
            }
        }

        foreach ($contacts as $contact) {
            if ($contact->email === null) {
                $contact->status = 'locked';
            }
        }

        $this->settleCollisions($contacts, array_filter($contacts, fn (Contact $c) => $c->email !== null), $domain);

        return new PropagationResult(
            $contacts,
            $pattern === null ? null : EmailPatterns::format($pattern, $domain),
            $creditsSpent,
            false,
        );
    }

    /**
     * The team on a domain that accepts every address.
     *
     * Nothing here can be proven, only noticed. The sweep hunts under a tight
     * ceiling for one address that answers *differently* from the domain's own
     * baseline, and whatever pattern that suggests is applied to the rest.
     * Everyone is labelled `catch_all`, which is the honest badge: a
     * plausible address on a server that would have said yes regardless.
     *
     * @param  list<Contact>  $contacts
     */
    private function catchAllTeam(
        array $contacts,
        string $domain,
        ?string $hint,
        ?WalkResult $probe,
        WalkOptions $options,
    ): PropagationResult {
        $first = $contacts[0] ?? null;
        if ($first === null) {
            return new PropagationResult($contacts, null, 0, false);
        }

        /*
         * Reuse the sweep that found the catch-all rather than paying for
         * another. The domain reveals itself partway through somebody's walk,
         * and that walk has already done the hunt this would otherwise start.
         */
        if ($probe !== null) {
            $pass = $probe;
        } else {
            $split = EmailPatterns::splitByDepth(
                EmailPatterns::candidates($first->name(), $domain),
                (int) config('grapup.common_depth'),
                $hint,
            );
            $pass = $this->sweep([...$split['common'], ...$split['tail']], $domain, $options);
        }

        if ($pass->vendorUnavailable) {
            return new PropagationResult(
                $this->markAll($contacts, 'unknown'), null,
                $probe === null ? $pass->creditsSpent : 0, true,
            );
        }

        $pattern = null;
        if ($pass->email !== null) {
            $pattern = EmailPatterns::detect(Parse::localPart($pass->email), $first->name());
            if ($pattern !== null) {
                DomainFacts::recordVerifiedPattern($domain, $pattern);
            }
            $first->email = $pass->email;
            $first->status = Status::forDisplay($pass->status);
            $first->verifiedBy = $pass->verifiedBy;
            $first->fromCache = $pass->fromCache;
        }

        /*
         * A pattern the cache already holds is only trusted here when it is
         * well evidenced. One address once saying so is not a naming
         * convention, and on a catch-all there is no way to find out.
         */
        $known = DomainFacts::get($domain);
        if ($pattern === null && DomainFacts::isWellEvidenced($known)) {
            $pattern = $known?->verified_pattern;
        }

        $rest = $pass->email === null
            ? $contacts
            : array_values(array_filter($contacts, fn (Contact $c) => $c !== $first));

        foreach ($rest as $contact) {
            // No pattern worth trusting, but the domain accepts everything —
            // so guess the commonest convention there is, first.last, and
            // flag it as a guess. The bare first name was the old fallback
            // and the weakest guess available: at Havells it put
            // ranjit@ where ranjit.kumar@ was far likelier. apply() still
            // falls back to the first name for someone with no last name.
            $contact->email = EmailPatterns::apply($pattern ?? EmailPatterns::TEMPLATES[0], $contact->name(), $domain);
            $contact->status = 'catch_all';
            // A pattern applied, not an address anybody checked.
            $contact->verifiedBy = null;
            $contact->fromCache = false;
        }

        $this->settleCollisions($contacts, $pass->email === null ? [] : [$first], $domain);

        return new PropagationResult(
            $contacts,
            $pattern === null ? null : EmailPatterns::format($pattern, $domain),
            $probe === null ? $pass->creditsSpent : 0,
            false,
        );
    }

    /**
     * Two people cannot share a mailbox, so only one of them may be shown it.
     *
     * Initials collide constantly: at Pardaen, Jan Dierckx and Jorne De Smet
     * both render jd@pardaen.be under {fi}{li}. Jan's was bought and is real;
     * showing the same address for Jorne presents a known-wrong answer with
     * the same confidence as a right one, and somebody would have written to it.
     *
     * The address stays with whoever it was verified for. Everyone else loses
     * it and is left locked, because the honest answer for the loser is that
     * we do not know — not a longer guess invented to break the tie, which is
     * how a collision becomes a second fabrication.
     *
     * @param  list<Contact>  $contacts
     * @param  iterable<Contact>  $proven
     */
    private function settleCollisions(array $contacts, iterable $proven, string $domain): void
    {
        $provenSet = new \SplObjectStorage;
        foreach ($proven as $contact) {
            $provenSet->attach($contact);
        }

        /** @var array<string, Contact> */
        $owner = [];

        // Proven addresses claim first, whatever the list order — and the
        // first claim wins, so a later namesake cannot evict the person it was
        // bought for.
        foreach ($contacts as $contact) {
            if ($contact->email !== null && $provenSet->contains($contact) && ! isset($owner[$contact->email])) {
                $owner[$contact->email] = $contact;
            }
        }

        foreach ($contacts as $contact) {
            $email = $contact->email;
            if ($email === null) {
                continue;
            }

            $held = $owner[$email] ?? null;
            if ($held === null) {
                $owner[$email] = $contact;
                continue;
            }
            if ($held === $contact) {
                continue;
            }

            Log::info('grapup: two contacts derive the same address, so only the first keeps it', [
                'domain' => $domain, 'email' => $email,
            ]);
            $contact->email = null;
            $contact->status = 'locked';
            $contact->verifiedBy = null;
            $contact->fromCache = false;
        }
    }

    /** Give up on a team without claiming to have learned anything about it. */
    private function markAll(array $contacts, string $status): array
    {
        foreach ($contacts as $contact) {
            $contact->email = null;
            $contact->status = $status;
            $contact->verifiedBy = null;
            $contact->fromCache = false;
        }

        return $contacts;
    }

    /** A timeout is not a finding: everyone unanswered is unknown, not missing. */
    private function outage(array $contacts, int $creditsSpent): PropagationResult
    {
        foreach ($contacts as $contact) {
            if ($contact->email === null) {
                $contact->status = 'unknown';
            }
        }

        return new PropagationResult($contacts, null, $creditsSpent, true);
    }
}
