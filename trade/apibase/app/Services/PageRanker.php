<?php

namespace App\Services;

use App\Models\Business\Page;
use Illuminate\Support\Facades\DB;

/**
 * Who comes first in a search.
 *
 * Three things, in this order: the plan the page's owner is on (Platinum
 * above Diamond above Gold above Free), then the boost the GrapOut team
 * gave the page by hand, then how many of the last thirty days the page
 * was worked on — a product added or edited, the page or its trade lines
 * changed. The three are folded into one number kept on the page, so a
 * search is one ORDER BY.
 *
 *   rank_score = tier × 1,000,000 + boost × 1,000 + active days × 10
 */
class PageRanker
{
    public const WINDOW_DAYS = 30;

    public const TIER_LABEL = [0 => 'Free', 1 => 'Gold', 2 => 'Diamond', 3 => 'Platinum'];

    /** The page was worked on today: note the day, and re-rank. */
    public static function touch(?Page $page): void
    {
        if (! $page) {
            return;
        }
        DB::table('business_page_activity')->insertOrIgnore(['page_id' => $page->id, 'day' => now()->toDateString()]);
        self::recompute($page);
    }

    /** The owner's plan, as a tier: 0 free, 1 gold, 2 diamond, 3 platinum. */
    public static function tierOf(Page $page): int
    {
        $page->loadMissing('owner');
        if (! $page->owner) {
            return 0;
        }
        $plan = app(SubscriptionEntitlementService::class)->planFor($page->owner);
        $tier = $plan->limit('rank_tier');
        if ($tier === null) {
            // A plan from before the tiers: paid counts as Gold.
            $tier = $plan->hasFeature('trade_priority') ? 1 : 0;
        }

        return max(0, min(3, (int) $tier));
    }

    public static function activityOf(Page $page): int
    {
        return (int) DB::table('business_page_activity')->where('page_id', $page->id)
            ->where('day', '>=', now()->subDays(self::WINDOW_DAYS)->toDateString())->count();
    }

    public static function recompute(Page $page): int
    {
        $tier = self::tierOf($page);
        $activity = self::activityOf($page);
        $boost = max(0, min(999, (int) $page->rank_boost));
        $score = $tier * 1_000_000 + $boost * 1_000 + $activity * 10;
        Page::whereKey($page->id)->update([
            'activity_score' => $activity,
            'rank_score' => $score,
            'last_activity_at' => DB::table('business_page_activity')->where('page_id', $page->id)->max('day'),
        ]);
        $page->setRawAttributes(array_merge($page->getAttributes(), ['activity_score' => $activity, 'rank_score' => $score]), true);

        return $score;
    }

    /** Every page, once a night: yesterday's work counts, thirty-one-day-old work stops counting. */
    public static function recomputeAll(): int
    {
        DB::table('business_page_activity')->where('day', '<', now()->subDays(self::WINDOW_DAYS + 31)->toDateString())->delete();
        $n = 0;
        Page::with('owner')->chunkById(200, function ($pages) use (&$n) {
            foreach ($pages as $page) {
                self::recompute($page);
                $n++;
            }
        });

        return $n;
    }

    /** The owner's plan changed: the page moves with it. */
    public static function recomputeForOwner(int $userId): void
    {
        foreach (Page::where('user_id', $userId)->get() as $page) {
            self::recompute($page);
        }
    }
}
