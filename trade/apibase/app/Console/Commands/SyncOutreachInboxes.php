<?php

namespace App\Console\Commands;

use App\Models\Outreach\Mailbox;
use App\Services\InboxSync;
use Illuminate\Console\Command;

/** Every few minutes: what came back to each outreach mailbox that can receive. */
class SyncOutreachInboxes extends Command
{
    protected $signature = 'trade:outreach-inbox {--mailbox= : one mailbox uuid}';

    protected $description = 'Read replies from the outreach mailboxes over IMAP and file them';

    public function handle(InboxSync $sync): int
    {
        $boxes = Mailbox::where('active', true)->whereNotNull('imap_host')
            ->when($this->option('mailbox'), fn ($q, $u) => $q->where('uuid', $u))
            ->get();
        $total = 0;
        foreach ($boxes as $box) {
            $n = $sync->sync($box);
            $total += $n;
            $this->line("{$box->label}: {$n} new" . ($box->fresh()->last_error ? ' — ' . $box->fresh()->last_error : ''));
        }
        $this->info("Filed {$total} messages from {$boxes->count()} mailboxes.");

        return self::SUCCESS;
    }
}
