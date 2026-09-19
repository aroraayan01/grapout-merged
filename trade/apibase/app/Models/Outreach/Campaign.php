<?php

namespace App\Models\Outreach;

use App\Models\Business\Page;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One mailing: a template, a slice of the list, a time. Drafted, then
 * launched now or at the hour set; counted as it goes out and as it is
 * opened and clicked.
 */
class Campaign extends Model
{
    use HasUuids;

    protected $table = 'outreach_campaigns';

    public const STATUSES = ['draft', 'scheduled', 'sending', 'done', 'paused'];

    protected $fillable = [
        'page_id', 'name', 'template_id', 'sequence_id', 'cohort_id', 'list_id', 'template_kind', 'mailbox_id', 'mailbox_group_id', 'status', 'filter_status', 'filter_country',
        'with_prices', 'note', 'scheduled_at', 'total', 'sent', 'failed', 'opened', 'clicked', 'replied', 'created_by', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return ['with_prices' => 'boolean', 'scheduled_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'template_id');
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class, 'mailbox_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(MailboxGroup::class, 'mailbox_group_id');
    }

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(Sequence::class, 'sequence_id');
    }

    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class, 'cohort_id');
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(ContactList::class, 'list_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sends(): HasMany
    {
        return $this->hasMany(Send::class, 'campaign_id');
    }

    /** The contacts this campaign writes to: a list, a cohort, or the plain filters. */
    public function audience()
    {
        $q = Contact::where('page_id', $this->page_id)
            ->whereNotIn('email_status', ['unsubscribed', 'bounced'])
            ->when($this->filter_status, fn ($q) => $q->where('email_status', $this->filter_status))
            ->when($this->filter_country, fn ($q) => $q->where('country', $this->filter_country))
            ->when($this->list_id, fn ($q) => $q->whereHas('lists', fn ($l) => $l->where('outreach_lists.id', $this->list_id)));
        if ($this->cohort_id && ($cohort = $this->cohort)) {
            $cohort->apply($q);
        }

        return $q;
    }

    /** Done when every first email is out and nobody is owed a follow-up. */
    public function settle(): void
    {
        \App\Services\CampaignRunner::settle($this);
    }
}
