<?php

namespace App\Console\Commands;

use App\Services\PageRanker;
use Illuminate\Console\Command;

/** Once a night: every page's rank, with yesterday's work counted and old work forgotten. */
class RankPages extends Command
{
    protected $signature = 'trade:rank';

    protected $description = 'Recompute every business page rank score (plan tier, admin boost, days active)';

    public function handle(): int
    {
        $n = PageRanker::recomputeAll();
        $this->info("Ranked {$n} pages.");

        return self::SUCCESS;
    }
}
