<?php

namespace App\Console\Commands;

use App\Models\Outreach\Campaign;
use App\Services\CampaignRunner;
use Illuminate\Console\Command;

/**
 * Every few minutes: campaigns whose hour has come start; campaigns still
 * sending pick up what yesterday's allowance left behind, and queue the
 * follow-ups of their sequence whose day has come.
 */
class RunScheduledCampaigns extends Command
{
    protected $signature = 'trade:outreach-campaigns';

    protected $description = 'Start scheduled outreach campaigns, continue the ones the daily allowance held back, and send sequence follow-ups';

    public function handle(): int
    {
        $started = 0;
        foreach (Campaign::where('status', 'scheduled')->where('scheduled_at', '<=', now())->get() as $campaign) {
            CampaignRunner::start($campaign);
            $started++;
        }
        $continued = 0;
        $followUps = 0;
        foreach (Campaign::where('status', 'sending')->get() as $campaign) {
            $campaign = $campaign->fresh();
            if ($campaign->sends()->where('status', 'queued')->doesntExist()) {
                // First emails still owed from yesterday's allowance, then follow-ups whose day has come.
                if ($campaign->audience()->whereNotIn('id', $campaign->sends()->pluck('contact_id')->filter()->all())->exists()) {
                    $continued += CampaignRunner::start($campaign) > 0 ? 1 : 0;
                }
                $followUps += CampaignRunner::followUps($campaign->fresh());
            }
            CampaignRunner::settle($campaign->fresh());
        }
        $this->info("Started {$started}, continued {$continued}, follow-ups queued {$followUps}.");

        return self::SUCCESS;
    }
}
