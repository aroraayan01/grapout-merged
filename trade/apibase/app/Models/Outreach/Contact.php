<?php

namespace App\Models\Outreach;

use App\Models\Business\Page;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One buyer or supplier on a company's own list: who they are, how to
 * reach them, and what has been sent to them from here.
 */
class Contact extends Model
{
    use HasUuids;

    protected $table = 'outreach_contacts';

    public const STATUSES = ['unknown', 'valid', 'bounced', 'unsubscribed'];

    protected $fillable = [
        'page_id', 'company_name', 'contact_name', 'email', 'email_status', 'mobile', 'phone', 'country', 'notes',
        'source', 'sent_count', 'last_sent_at', 'last_template', 'created_by', 'replied_at', 'last_reply_at', 'reply_count',
    ];

    protected function casts(): array
    {
        return ['last_sent_at' => 'datetime', 'replied_at' => 'datetime', 'last_reply_at' => 'datetime'];
    }

    /** The lists this contact is on. */
    public function lists(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(ContactList::class, 'outreach_contact_list', 'contact_id', 'list_id');
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Somebody who said no is never written to again, whatever the list says. */
    public function mayBeWrittenTo(): bool
    {
        return ! in_array($this->email_status, ['unsubscribed', 'bounced'], true);
    }
}
