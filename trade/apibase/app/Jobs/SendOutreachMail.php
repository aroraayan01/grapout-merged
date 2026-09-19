<?php

namespace App\Jobs;

use App\Models\Outreach\Send;
use App\Services\OutreachMailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One outreach email, off the request and onto the queue: a list of two
 * hundred must not hold the browser for two hundred SMTP round trips.
 */
class SendOutreachMail implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 60;

    public function __construct(public int $sendId)
    {
    }

    public function handle(OutreachMailer $mailer): void
    {
        $send = Send::find($this->sendId);
        if (! $send || $send->status !== 'queued') {
            return;
        }
        // Somebody who unsubscribed between queueing and sending is left alone.
        if ($send->contact && ! $send->contact->mayBeWrittenTo()) {
            $send->update(['status' => 'failed', 'error' => 'Unsubscribed before it went out.']);

            return;
        }
        $mailer->deliver($send);
    }
}
