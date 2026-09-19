<?php

namespace App\Console\Commands;

use App\Models\SavedSearch;
use App\Notifications\SocialNotification;
use Illuminate\Console\Command;

/**
 * The daily look at what arrived.
 *
 * Every active saved search is run against what was added since it last
 * ran. Anything new becomes one notification — a count and a link — in
 * the bell, and in the inbox when the address is verified. Nothing new,
 * nothing sent: an alert that fires with no news trains people to ignore
 * it.
 */
class SendTradeAlerts extends Command
{
    protected $signature = 'mypa:trade-alerts';

    protected $description = 'Tell people about new products and requirements that match their saved searches';

    public function handle(): int
    {
        $sent = 0;
        SavedSearch::with('user')->where('active', true)->chunkById(200, function ($searches) use (&$sent) {
            foreach ($searches as $search) {
                $user = $search->user;
                if (! $user || $user->status !== 'active') {
                    continue;
                }
                $hits = $search->newMatches()->count();
                $search->forceFill(['last_run_at' => now(), 'last_hits' => $hits])->save();
                if ($hits === 0) {
                    continue;
                }
                $what = $search->kind === 'products' ? 'new product' : 'new buyer requirement';
                $user->notify(new SocialNotification(
                    'business_alert',
                    "{$hits} {$what}" . ($hits === 1 ? '' : 's') . " match your saved search {$search->label()}.",
                    ['saved_search_uuid' => $search->uuid, 'hits' => $hits],
                    $search->path(),
                    'trade-alert-' . $search->uuid,
                    'GrapOut Trade',
                ));
                $sent++;
            }
        });

        $this->info("Sent {$sent} alert(s).");

        return self::SUCCESS;
    }
}
