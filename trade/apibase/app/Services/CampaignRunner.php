<?php

namespace App\Services;

use App\Jobs\SendOutreachMail;
use App\Models\Business\Page;
use App\Models\Outreach\Campaign;
use App\Models\Outreach\Contact;
use App\Models\Outreach\Mailbox;
use App\Models\Outreach\Send;
use App\Models\Outreach\SequenceStep;
use Illuminate\Support\Facades\DB;

/**
 * Turns a campaign into queued emails, a day's allowance at a time.
 *
 * The first email goes to everyone in the audience; a campaign with a
 * sequence then keeps going: each follow-up is queued once its day has
 * come, unless the contact answered, clicked (when the sequence says
 * so), or said no. Emails are spread across the mailboxes of a group
 * when the page or the campaign has one.
 */
class CampaignRunner
{
    public const MAX_PER_CAMPAIGN = 5000;

    /** The mailboxes a campaign may send from right now, each with the room it has left today. */
    public static function boxesFor(Campaign $campaign, Page $page): array
    {
        $group = $campaign->mailbox_group_id ? $campaign->group : ($page->outreach_mailbox_group_id ? $page->mailboxGroup : null);
        if ($group && $group->active) {
            $rows = $group->boxesWithRoom()->map(fn ($r) => ['box' => $r['box'], 'room' => $r['room']])->all();
            if ($rows) {
                return $rows;
            }
        }
        $box = $campaign->mailbox_id ? Mailbox::find($campaign->mailbox_id) : ($page->outreach_mailbox_id ? Mailbox::find($page->outreach_mailbox_id) : Mailbox::where('is_default', true)->where('active', true)->first());
        if (! $box || ! $box->active) {
            return [];
        }

        return [['box' => $box, 'room' => max(0, (int) $box->daily_limit - $box->sentToday())]];
    }

    /**
     * Queue the first email of a campaign to everyone in its audience not yet
     * written to, within today's allowance. Used by the launch button and by
     * the scheduler. @return int how many were queued now
     */
    public static function start(Campaign $campaign, ?int $by = null): int
    {
        $page = $campaign->page;
        $boxes = self::boxesFor($campaign, $page);
        if (! $page->outreach_enabled || ! $boxes) {
            return 0;
        }
        $already = Send::where('campaign_id', $campaign->id)->pluck('contact_id')->filter()->all();
        $targets = $campaign->audience()->whereNotIn('id', $already)->limit(self::MAX_PER_CAMPAIGN)->get();

        $step = $campaign->sequence_id ? $campaign->sequence?->steps()->orderBy('position')->first() : null;
        $template = $step ? $step->template : $campaign->template;
        $kind = $step ? $step->kind() : ($template ? ($template->kind === 'catalogue' ? 'catalogue' : 'invitation') : $campaign->template_kind);

        $queued = self::queue($campaign, $page, $boxes, $targets, $template?->id, $kind, $step?->id, 1, $by);
        $campaign->update([
            'status' => 'sending',
            'started_at' => $campaign->started_at ?? now(),
            'total' => count($already) + $targets->count(),
        ]);
        if ($campaign->fresh()->total === 0) {
            $campaign->update(['status' => 'done', 'finished_at' => now()]);
        }

        return $queued;
    }

    /** The follow-ups of a sequence whose day has come. @return int how many were queued now */
    public static function followUps(Campaign $campaign): int
    {
        if (! $campaign->sequence_id || $campaign->status !== 'sending') {
            return 0;
        }
        $page = $campaign->page;
        $sequence = $campaign->sequence;
        if (! $sequence || ! $page->outreach_enabled) {
            return 0;
        }
        $steps = $sequence->steps()->with('template')->get()->keyBy('position');
        $boxes = self::boxesFor($campaign, $page);
        if (! $boxes) {
            return 0;
        }
        $queued = 0;
        foreach (self::dueFollowUps($campaign, $steps, $sequence) as [$contact, $next]) {
            $room = array_sum(array_column($boxes, 'room'));
            if ($room <= 0 || self::pageRoom($page) <= 0) {
                break;
            }
            $queued += self::queue($campaign, $page, $boxes, collect([$contact]), $next->template_id, $next->kind(), $next->id, $next->position, $campaign->created_by);
        }

        return $queued;
    }

    /** Which contacts are owed a next step now: [contact, step] pairs. */
    private static function dueFollowUps(Campaign $campaign, $steps, $sequence): array
    {
        $last = Send::where('campaign_id', $campaign->id)
            ->select('contact_id', DB::raw('MAX(step_position) as position'))
            ->groupBy('contact_id')->get()->keyBy('contact_id');
        $due = [];
        foreach ($last as $contactId => $row) {
            $next = $steps->get($row->position + 1);
            if (! $next) {
                continue;
            }
            $send = Send::where('campaign_id', $campaign->id)->where('contact_id', $contactId)->where('step_position', $row->position)->orderByDesc('id')->first();
            if (! $send || $send->status !== 'sent' || ! $send->sent_at) {
                continue; // still queued, or failed: the sequence stops here for them
            }
            if ($send->sent_at->copy()->addDays((int) $next->delay_days)->isFuture()) {
                continue;
            }
            $contact = Contact::find($contactId);
            if (! $contact || ! $contact->mayBeWrittenTo()) {
                continue;
            }
            $replied = Send::where('campaign_id', $campaign->id)->where('contact_id', $contactId)->whereNotNull('replied_at')->exists();
            if ($replied && ($sequence->stop_on_reply || $next->only_if_no_reply)) {
                continue;
            }
            if ($sequence->stop_on_click && Send::where('campaign_id', $campaign->id)->where('contact_id', $contactId)->whereNotNull('clicked_at')->exists()) {
                continue;
            }
            $due[] = [$contact, $next];
        }

        return $due;
    }

    /** Anybody still owed a step later on — the campaign is not done while there is. */
    public static function hasWorkLeft(Campaign $campaign): bool
    {
        if (Send::where('campaign_id', $campaign->id)->where('status', 'queued')->exists()) {
            return true;
        }
        if (! $campaign->sequence_id) {
            return false;
        }
        $sequence = $campaign->sequence;
        $lastPosition = (int) $sequence?->steps()->max('position');
        $last = Send::where('campaign_id', $campaign->id)
            ->select('contact_id', DB::raw('MAX(step_position) as position'))
            ->groupBy('contact_id')->get();
        foreach ($last as $row) {
            if ($row->position >= $lastPosition) {
                continue;
            }
            $send = Send::where('campaign_id', $campaign->id)->where('contact_id', $row->contact_id)->where('step_position', $row->position)->orderByDesc('id')->first();
            if (! $send || $send->status !== 'sent') {
                continue;
            }
            $contact = Contact::find($row->contact_id);
            if (! $contact || ! $contact->mayBeWrittenTo()) {
                continue;
            }
            $replied = Send::where('campaign_id', $campaign->id)->where('contact_id', $row->contact_id)->whereNotNull('replied_at')->exists();
            if ($replied && $sequence->stop_on_reply) {
                continue;
            }

            return true;
        }

        return false;
    }

    /** Done when the first emails are all out and nobody is owed a follow-up. */
    public static function settle(Campaign $campaign): void
    {
        if ($campaign->status !== 'sending') {
            return;
        }
        $firstDone = $campaign->total > 0 && $campaign->sent + $campaign->failed >= $campaign->total;
        if (! $campaign->sequence_id) {
            if ($firstDone) {
                $campaign->update(['status' => 'done', 'finished_at' => now()]);
            }

            return;
        }
        // A sequence counts every step it sends; "sent" runs past "total".
        $firstOut = Send::where('campaign_id', $campaign->id)->where('step_position', 1)->where('status', '!=', 'queued')->count() >= $campaign->total;
        if ($firstOut && ! self::hasWorkLeft($campaign)) {
            $campaign->update(['status' => 'done', 'finished_at' => now()]);
        }
    }

    private static function pageRoom(Page $page): int
    {
        $sentToday = Send::where('page_id', $page->id)->whereDate('created_at', now()->toDateString())->where('status', '!=', 'failed')->count();

        return max(0, (int) $page->outreach_daily_limit - $sentToday);
    }

    /** Queue emails to these contacts, spread across the boxes with room, within the page's allowance. */
    private static function queue(Campaign $campaign, Page $page, array &$boxes, $contacts, ?int $templateId, string $kind, ?int $stepId, int $position, ?int $by): int
    {
        $room = min(self::pageRoom($page), array_sum(array_column($boxes, 'room')));
        $batch = $contacts->take(max(0, $room));
        $queued = 0;
        DB::transaction(function () use ($batch, $campaign, $page, &$boxes, $templateId, $kind, $stepId, $position, $by, &$queued) {
            foreach ($batch as $c) {
                // The box with the most room left takes the next one.
                usort($boxes, fn ($a, $b) => $b['room'] <=> $a['room']);
                if (($boxes[0]['room'] ?? 0) <= 0) {
                    break;
                }
                $box = $boxes[0]['box'];
                $boxes[0]['room']--;
                $send = Send::create([
                    'page_id' => $page->id, 'contact_id' => $c->id, 'mailbox_id' => $box->id, 'campaign_id' => $campaign->id, 'template_id' => $templateId,
                    'sequence_step_id' => $stepId, 'step_position' => $position,
                    'template' => $kind, 'to_email' => $c->email, 'subject' => '', 'status' => 'queued',
                    'with_prices' => $campaign->with_prices, 'note' => $campaign->note, 'sent_by' => $by ?? $campaign->created_by,
                ]);
                SendOutreachMail::dispatch($send->id);
                $queued++;
            }
        });

        return $queued;
    }
}
