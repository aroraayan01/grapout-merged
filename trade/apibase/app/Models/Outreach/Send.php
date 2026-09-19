<?php

namespace App\Models\Outreach;

use App\Models\Business\Page;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One email that went, or is going, out of the platform for a company. */
class Send extends Model
{
    use HasUuids;

    protected $table = 'outreach_sends';

    public const TEMPLATES = ['invitation', 'catalogue'];

    protected $fillable = [
        'page_id', 'contact_id', 'mailbox_id', 'campaign_id', 'template_id', 'sequence_step_id', 'step_position', 'template', 'to_email', 'subject', 'status', 'error',
        'with_prices', 'note', 'sent_by', 'sent_at', 'opened_at', 'clicked_at', 'open_count', 'replied_at', 'message_id',
    ];

    protected function casts(): array
    {
        return ['with_prices' => 'boolean', 'sent_at' => 'datetime', 'opened_at' => 'datetime', 'clicked_at' => 'datetime', 'replied_at' => 'datetime'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class, 'mailbox_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(SequenceStep::class, 'sequence_step_id');
    }

    /** The template written in words, when one was used ("template" itself is the kind: invitation or catalogue). */
    public function tpl(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'template_id');
    }
}
