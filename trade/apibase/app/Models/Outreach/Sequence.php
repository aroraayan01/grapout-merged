<?php

namespace App\Models\Outreach;

use App\Models\Business\Page;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A first email and its follow-ups: step one on day zero, step two so
 * many days later if nobody answered, and so on. A campaign that uses
 * a sequence keeps going until every contact has had every step, or
 * answered. A sequence with no page is the GrapOut team's, offered to
 * every page.
 */
class Sequence extends Model
{
    use HasUuids;

    protected $table = 'outreach_sequences';

    protected $fillable = ['page_id', 'name', 'description', 'stop_on_reply', 'stop_on_click', 'active', 'created_by'];

    protected function casts(): array
    {
        return ['stop_on_reply' => 'boolean', 'stop_on_click' => 'boolean', 'active' => 'boolean'];
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

    public function steps(): HasMany
    {
        return $this->hasMany(SequenceStep::class, 'sequence_id')->orderBy('position');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class, 'sequence_id');
    }
}
