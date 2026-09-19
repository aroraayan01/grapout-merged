<?php

namespace App\Models\Outreach;

use App\Models\Business\Page;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An email that arrived in an outreach mailbox. Matched to the contact
 * and the send it answers where it can be; a page sees the ones that
 * answer its own emails, the GrapOut team sees them all.
 */
class InboxMessage extends Model
{
    use HasUuids;

    protected $table = 'outreach_inbox_messages';

    protected $fillable = [
        'mailbox_id', 'page_id', 'contact_id', 'send_id', 'campaign_id', 'imap_uid', 'message_id', 'in_reply_to',
        'from_email', 'from_name', 'subject', 'snippet', 'body_text', 'received_at', 'read_at', 'is_reply',
    ];

    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'read_at' => 'datetime', 'is_reply' => 'boolean'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class, 'mailbox_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function send(): BelongsTo
    {
        return $this->belongsTo(Send::class, 'send_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }
}
