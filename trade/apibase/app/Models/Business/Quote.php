<?php

namespace App\Models\Business;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A structured offer.
 *
 * Price on its terms, minimum, lead time, how long it stands. Sent by a
 * page to a requirement or in answer to an enquiry; accepted or declined
 * by the buyer. Offers with the same shape can be compared, which a chat
 * never can.
 */
class Quote extends Model
{
    use HasUuids;

    protected $table = 'business_quotes';

    public const STATUSES = ['sent', 'accepted', 'declined', 'withdrawn'];

    protected $fillable = [
        'page_id', 'user_id', 'requirement_id', 'enquiry_id', 'buyer_user_id', 'price', 'currency', 'price_unit',
        'moq', 'moq_unit', 'lead_time_days', 'valid_days', 'terms', 'notes', 'status', 'responded_at',
    ];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'moq' => 'decimal:2', 'responded_at' => 'datetime'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(Requirement::class, 'requirement_id');
    }

    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(Enquiry::class, 'enquiry_id');
    }

    public function expiresAt(): ?\Carbon\CarbonInterface
    {
        return $this->created_at?->copy()->addDays($this->valid_days);
    }

    public function isExpired(): bool
    {
        return $this->status === 'sent' && $this->expiresAt()?->isPast() === true;
    }
}
