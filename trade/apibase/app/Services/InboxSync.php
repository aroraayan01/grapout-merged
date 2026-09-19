<?php

namespace App\Services;

use App\Models\Outreach\Campaign;
use App\Models\Outreach\Contact;
use App\Models\Outreach\InboxMessage;
use App\Models\Outreach\Mailbox;
use App\Models\Outreach\Send;

/**
 * Reads what arrived in an outreach mailbox and files it: each message
 * is matched to the email it answers (by In-Reply-To first, by the
 * sender's address second), the contact is marked as having replied,
 * the campaign counts one more answer, and a sequence stops writing to
 * somebody who wrote back.
 */
class InboxSync
{
    public function __construct(private MailboxDoctor $doctor)
    {
    }

    /** Fetch what is new in the mailbox. @return int messages filed */
    public function sync(Mailbox $box): int
    {
        if (! $box->imap_host || ! $box->active) {
            return 0;
        }
        try {
            $client = $this->doctor->imapClient($box);
            $client->connect();
            $folder = $client->getFolder('INBOX');
            $since = $box->inbox_synced_at ? $box->inbox_synced_at->copy()->subDay() : now()->subDays(7);
            $messages = $folder->messages()->since($since)->leaveUnread()->get();
            $n = 0;
            foreach ($messages as $m) {
                try {
                    $from = $m->getFrom()->first();
                    $date = $m->getDate();
                    $received = null;
                    try {
                        $received = $date?->toDate();
                    } catch (\Throwable) {
                        $received = null;
                    }
                    $text = (string) $m->getTextBody();
                    if ($text === '') {
                        $text = trim(strip_tags((string) $m->getHTMLBody()));
                    }
                    $filed = $this->record($box, [
                        'imap_uid' => (int) $m->getUid(),
                        'message_id' => trim((string) $m->getMessageId(), '<> '),
                        'in_reply_to' => trim((string) $m->getInReplyTo(), '<> '),
                        'from_email' => strtolower((string) ($from->mail ?? '')),
                        'from_name' => (string) ($from->personal ?? ''),
                        'subject' => (string) $m->getSubject(),
                        'body_text' => $text,
                        'received_at' => $received,
                    ]);
                    if ($filed) {
                        $n++;
                    }
                } catch (\Throwable) {
                    // One unreadable message does not stop the rest.
                }
            }
            $client->disconnect();
            $box->forceFill(['inbox_synced_at' => now(), 'imap_ok' => true, 'last_error' => null])->save();

            return $n;
        } catch (\Throwable $e) {
            $box->forceFill(['imap_ok' => false, 'last_error' => 'Inbox: ' . mb_substr($e->getMessage(), 0, 900)])->save();

            return 0;
        }
    }

    /** File one message; the matching is here so it can be tested without a mail server. */
    public function record(Mailbox $box, array $msg): ?InboxMessage
    {
        if ($msg['from_email'] === '' || InboxMessage::where('mailbox_id', $box->id)->where('imap_uid', $msg['imap_uid'])->exists()) {
            return null;
        }
        // Our own mail, bounced back into the box, is not a reply.
        if (strtolower($msg['from_email']) === strtolower($box->from_address)) {
            return null;
        }
        $send = null;
        if (! empty($msg['in_reply_to'])) {
            $send = Send::where('message_id', $msg['in_reply_to'])->first();
        }
        $send ??= Send::where('mailbox_id', $box->id)->where('status', 'sent')->whereRaw('LOWER(to_email) = ?', [strtolower($msg['from_email'])])->orderByDesc('id')->first();
        // Not one of ours: perhaps still somebody on a list that sends from this box.
        $contact = $send?->contact ?? Contact::whereRaw('LOWER(email) = ?', [strtolower($msg['from_email'])])->orderByDesc('id')->first();

        $body = (string) ($msg['body_text'] ?? '');
        $row = InboxMessage::create([
            'mailbox_id' => $box->id,
            'page_id' => $send?->page_id ?? $contact?->page_id,
            'contact_id' => $contact?->id,
            'send_id' => $send?->id,
            'campaign_id' => $send?->campaign_id,
            'imap_uid' => $msg['imap_uid'],
            'message_id' => $msg['message_id'] ?: null,
            'in_reply_to' => $msg['in_reply_to'] ?: null,
            'from_email' => strtolower($msg['from_email']),
            'from_name' => $msg['from_name'] ?: null,
            'subject' => mb_substr((string) ($msg['subject'] ?? ''), 0, 500) ?: null,
            'snippet' => mb_substr(preg_replace('/\s+/', ' ', $body) ?? '', 0, 240) ?: null,
            'body_text' => $body ?: null,
            'received_at' => $msg['received_at'] ?? now(),
            'is_reply' => $send !== null,
        ]);

        if ($send) {
            $first = $send->replied_at === null;
            $send->update(['replied_at' => $send->replied_at ?? ($row->received_at ?? now())]);
            if ($first && $send->campaign_id) {
                Campaign::whereKey($send->campaign_id)->increment('replied');
            }
        }
        if ($contact) {
            $contact->update([
                'replied_at' => $contact->replied_at ?? ($row->received_at ?? now()),
                'last_reply_at' => $row->received_at ?? now(),
                'reply_count' => ($contact->reply_count ?? 0) + 1,
                'email_status' => $contact->email_status === 'unknown' ? 'valid' : $contact->email_status,
            ]);
        }

        return $row;
    }
}
