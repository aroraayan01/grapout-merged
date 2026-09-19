<?php

namespace App\Services;

use App\Models\File;
use App\Models\Group;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Task;
use App\Models\User;

/**
 * Single authority for "what can this user do on their plan".
 * All plan restrictions are enforced here on the backend — the frontend only
 * mirrors what these checks return.
 */
class SubscriptionEntitlementService
{
    /** @var array<int, Plan> per-request cache */
    protected array $planCache = [];

    public function planFor(User $user): Plan
    {
        if (isset($this->planCache[$user->id])) {
            return $this->planCache[$user->id];
        }

        $subscription = Subscription::with('plan')
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'trial'])
            ->latest('started_at')
            ->first();

        $plan = $subscription?->isCurrentlyActive() ? $subscription->plan : null;

        // Everyone falls back to the Free plan.
        $plan ??= Plan::where('slug', 'free')->first()
            ?? new Plan(['slug' => 'free', 'name' => 'Free', 'limits' => [], 'features' => []]);

        return $this->planCache[$user->id] = $plan;
    }

    public function forget(User $user): void
    {
        unset($this->planCache[$user->id]);
    }

    public function subscriptionFor(User $user): ?Subscription
    {
        return Subscription::with('plan')
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'trial'])
            ->latest('started_at')
            ->first();
    }

    // --- Limit checks (null limit = unlimited) ------------------------------

    public function canCreateTask(User $user): bool
    {
        $limit = $this->planFor($user)->limit('max_tasks');

        return $limit === null
            || Task::where('user_id', $user->id)->where('status', '!=', 'archived')->count() < $limit;
    }

    public function canUploadBytes(User $user, int $incoming): bool
    {
        $limit = $this->storageLimitBytes($user);

        return $limit === null || ($this->usedStorageBytes($user) + $incoming) <= $limit;
    }

    /**
     * Everything this user has put on disk, not just their Drive.
     *
     * Chat attachments and meeting chat files were never counted, so the
     * quota could be bypassed entirely by sending files through a
     * conversation instead of uploading them — and the storage figure shown
     * to the user understated what they were actually using.
     */
    public function usedStorageBytes(User $user): int
    {
        $drive = (int) File::where('user_id', $user->id)->sum('size');

        $chat = (int) \App\Models\MessageAttachment::whereHas(
            'message',
            fn ($m) => $m->where('user_id', $user->id),
        )->sum('size');

        $meetings = (int) \App\Models\MeetingFile::where('user_id', $user->id)->sum('size');

        return $drive + $chat + $meetings;
    }

    public function storageLimitBytes(User $user): ?int
    {
        return $this->planFor($user)->limit('storage_bytes')
            ?? (int) config('mypa.files.storage_limit_bytes');
    }

    public function canCreateGroup(User $user): bool
    {
        $limit = $this->planFor($user)->limit('max_groups');

        return $limit === null
            || Group::where('owner_id', $user->id)->count() < $limit;
    }

    public function canAddGroupMember(User $user, Group $group): bool
    {
        $limit = $this->planFor($group->owner ?? $user)->limit('max_group_members');

        return $limit === null || $group->members()->count() < $limit;
    }

    /**
     * How many people may be in one of this user's meetings, null for no cap.
     *
     * Always the host's plan, never the joiner's — the meeting belongs to
     * whoever opened it, and a guest has no plan at all to consult.
     */
    public function meetingParticipantLimit(User $host): ?int
    {
        return $this->planFor($host)->limit('max_meeting_participants');
    }

    /** How long one of this user's meetings may run, in minutes. Null = no cap. */
    public function meetingMinutesLimit(User $host): ?int
    {
        return $this->planFor($host)->limit('max_meeting_minutes');
    }

    // --- GrapOut Trade ------------------------------------------------------------------------

    /** How many products a page may list on this plan; null is unlimited. */
    public function productLimit(User $user): ?int
    {
        return $this->planFor($user)->limit('max_products');
    }

    public function canAddProducts(User $user, int $adding = 1): bool
    {
        $limit = $this->productLimit($user);
        if ($limit === null) {
            return true;
        }
        $have = \App\Models\Business\Product::whereHas('page', fn ($p) => $p->where('user_id', $user->id))->count();

        return $have + $adding <= $limit;
    }

    /** Paid pages rank first in the public search and the directory. */
    public function tradePriority(User $user): bool
    {
        return $this->planFor($user)->hasFeature('trade_priority');
    }

    public function hasFeature(User $user, string $feature): bool
    {
        // An admin's Hot Leads grant opens it, export included, whatever the plan says.
        if (in_array($feature, ['grap_leads', 'grap_export'], true) && (bool) $user->grap_leads_granted) {
            return true;
        }

        return $this->planFor($user)->hasFeature($feature);
    }

    /**
     * A Hot Leads limit for this user. Null means unlimited.
     *
     * Read through here rather than off the plan, because an admin's grant
     * lifts every Hot Leads limit: the Free plan allows no unlocks at all, so
     * opening Hot Leads without lifting them would open nothing.
     */
    public function grapLimit(User $user, string $key): ?int
    {
        if ((bool) $user->grap_leads_granted) {
            return null;
        }

        return $this->planFor($user)->limit($key);
    }

    /** Upgrade hint: the cheapest public plan whose limit satisfies $needed. */
    public function planWithHigherLimit(string $limitKey, int $needed): ?Plan
    {
        return Plan::where('is_active', true)->where('is_public', true)
            ->orderBy('monthly_price')
            ->get()
            ->first(fn (Plan $plan) => $plan->limit($limitKey) === null || $plan->limit($limitKey) > $needed);
    }

    public function usage(User $user): array
    {
        $plan = $this->planFor($user);

        return [
            'tasks' => [
                'used' => Task::where('user_id', $user->id)->where('status', '!=', 'archived')->count(),
                'limit' => $plan->limit('max_tasks'),
            ],
            'storage' => [
                'used' => $this->usedStorageBytes($user),
                'limit' => $this->storageLimitBytes($user),
            ],
            'groups' => [
                'used' => Group::where('owner_id', $user->id)->count(),
                'limit' => $plan->limit('max_groups'),
            ],
            'products' => [
                'used' => \App\Models\Business\Product::whereHas('page', fn ($p) => $p->where('user_id', $user->id))->count(),
                'limit' => $plan->limit('max_products'),
            ],
            // No "used" for these: they are per-meeting ceilings rather than
            // a running total, so the settings page shows the ceiling alone.
            'meeting_participants' => ['limit' => $plan->limit('max_meeting_participants')],
            'meeting_minutes' => ['limit' => $plan->limit('max_meeting_minutes')],
            'grap_searches_today' => [
                'used' => $this->grapSearchesToday($user),
                'limit' => $this->grapLimit($user, 'grap_searches_per_day'),
            ],
            'grap_reveals_today' => [
                'used' => $this->grapRevealsToday($user),
                'limit' => $this->grapLimit($user, 'grap_reveals_per_day'),
            ],
            'grap_reveals_month' => [
                'used' => $this->grapRevealsThisMonth($user),
                'limit' => $this->grapLimit($user, 'grap_reveals_per_month'),
            ],
        ];
    }

    // --- Hot Leads ---------------------------------------------------------
    //
    // The old site kept a table per allowance — pointgrapbuyersearchlimit,
    // pointgrapbuyergetcontactlimit, and the same pair again for suppliers,
    // for LinkedIn companies, for LinkedIn people, for Google. Eleven tables
    // saying the same sentence. Here the allowance is two keys on the plan
    // and the count comes from the rows themselves, so nothing can drift
    // out of step with what actually happened.
    //
    // Buyers and suppliers share one allowance rather than having one each:
    // a person looking for a supplier this week and a buyer the next was
    // previously charged twice over for one month's work. Grap Company shares
    // it too — a verified address is a verified address, whichever tab found
    // it, and two counters would make "how many have I left today" a question
    // with two answers.

    public function grapSearchesToday(User $user): int
    {
        return \App\Models\Grap\SearchLog::where('user_id', $user->id)
            ->where('created_at', '>=', now()->startOfDay())->count();
    }

    public function grapRevealsToday(User $user): int
    {
        return \App\Models\Grap\Reveal::where('user_id', $user->id)
            ->where('created_at', '>=', now()->startOfDay())->count();
    }

    public function grapRevealsThisMonth(User $user): int
    {
        return \App\Models\Grap\Reveal::where('user_id', $user->id)
            ->where('created_at', '>=', now()->startOfMonth())->count();
    }

    public function canSearchGrap(User $user): bool
    {
        if (! $this->hasFeature($user, 'grap_leads')) {
            return false;
        }
        $limit = $this->grapLimit($user, 'grap_searches_per_day');

        return $limit === null || $this->grapSearchesToday($user) < $limit;
    }

    /**
     * Whether one more contact may be unlocked.
     *
     * A lead already unlocked is always readable again and does not ask
     * this question — see `GrapController::reveal`.
     */
    public function canRevealGrap(User $user): bool
    {
        if (! $this->hasFeature($user, 'grap_leads')) {
            return false;
        }
        $daily = $this->grapLimit($user, 'grap_reveals_per_day');
        $monthly = $this->grapLimit($user, 'grap_reveals_per_month');

        return ($daily === null || $this->grapRevealsToday($user) < $daily)
            && ($monthly === null || $this->grapRevealsThisMonth($user) < $monthly);
    }
}
