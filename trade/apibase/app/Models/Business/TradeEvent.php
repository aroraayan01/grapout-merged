<?php

namespace App\Models\Business;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A trade show, an exhibition, a buyer meet.
 *
 * Posted by a member (with their page as organiser when they have one),
 * attended with one tap. Attending also writes the dates into the
 * person's own calendar, so the app reminds them like any other event.
 */
class TradeEvent extends Model
{
    use HasUuids;

    public const KINDS = ['fair', 'exhibition', 'webinar', 'buyer_meet', 'other'];

    protected $fillable = [
        'user_id', 'page_id', 'title', 'description', 'kind', 'starts_on', 'ends_on', 'venue', 'city', 'country',
        'website', 'keywords', 'status', 'attendees_count',
    ];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'keywords' => 'array'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }

    public function attendees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'trade_event_attendees', 'event_id', 'user_id')->withPivot('calendar_event_id')->withTimestamps();
    }

    /** Listed, and not over. */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('status', 'listed')
            ->where(fn ($q) => $q->whereDate('ends_on', '>=', now()->toDateString())
                ->orWhere(fn ($qq) => $qq->whereNull('ends_on')->whereDate('starts_on', '>=', now()->toDateString())));
    }
}
