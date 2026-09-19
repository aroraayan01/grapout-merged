<?php

namespace App\Models\Outreach;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One email in a sequence: which template, how many days after the previous one, and whether a reply cancels it. */
class SequenceStep extends Model
{
    protected $table = 'outreach_sequence_steps';

    protected $fillable = ['sequence_id', 'position', 'template_id', 'template_kind', 'delay_days', 'only_if_no_reply'];

    protected function casts(): array
    {
        return ['only_if_no_reply' => 'boolean'];
    }

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(Sequence::class, 'sequence_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'template_id');
    }

    /** The built-in kind this step sends when it has no written template, or the template's own kind. */
    public function kind(): string
    {
        $t = $this->template;

        return $t ? ($t->kind === 'catalogue' ? 'catalogue' : 'invitation') : ($this->template_kind ?: 'invitation');
    }
}
