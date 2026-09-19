<?php

namespace App\Models\Business;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody asking a page about a product.
 *
 * Lands in the owner's Enquiries with the product on it. From there either
 * side can connect, open a chat, or — if it is rubbish — report it, which
 * reaches the platform's moderators through the same report the rest of
 * GrapOut uses.
 */
class Enquiry extends Model
{
    use HasUuids;

    protected $table = 'business_enquiries';

    public const STATUSES = ['new', 'replied', 'closed'];

    /** Where a conversation stands, in the order a deal moves. */
    public const STAGES = ['new', 'accepted', 'declined', 'meeting', 'quoted', 'sampling', 'negotiating', 'won', 'lost'];

    /** What the team records after a meeting or a call, and the stage it moves to. */
    public const OUTCOMES = [
        'interested' => 'accepted',
        'follow_up' => 'accepted',
        'quote_requested' => 'quoted',
        'sample_requested' => 'sampling',
        'negotiating' => 'negotiating',
        'won' => 'won',
        'not_interested' => 'lost',
    ];

    protected $fillable = [
        'page_id', 'product_id', 'from_user_id', 'message', 'quantity', 'target_price', 'currency',
        'status', 'conversation_id',
        'guest_name', 'guest_company', 'guest_email', 'guest_country', 'guest_token', 'guest_code', 'guest_code_expires_at', 'confirmed_at',
        'owner_reply', 'replied_at', 'claimed_at',
        'requirement_id', 'stage', 'meeting_id', 'meeting_at', 'outcome', 'outcome_note', 'outcome_at', 'handled_by',
    ];

    /** The token is the guest's credential; it never leaves the server in a listing. */
    protected $hidden = ['guest_token'];

    protected function casts(): array
    {
        return ['target_price' => 'decimal:2', 'replied_at' => 'datetime', 'claimed_at' => 'datetime', 'meeting_at' => 'datetime', 'outcome_at' => 'datetime', 'guest_code_expires_at' => 'datetime', 'confirmed_at' => 'datetime'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(Requirement::class, 'requirement_id');
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Meeting::class, 'meeting_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /** Move forward, never back: a quote after a meeting stays at quoted. */
    public function advanceTo(string $stage): void
    {
        $order = array_flip(self::STAGES);
        $current = $order[$this->stage ?? 'new'] ?? 0;
        if (in_array($this->stage, ['declined', 'won', 'lost'], true)) {
            return;
        }
        if (($order[$stage] ?? 0) > $current) {
            $this->update(['stage' => $stage]);
        }
    }

    /** Asked by somebody with no account — until they claim it. */
    public function isGuest(): bool
    {
        return $this->from_user_id === null;
    }

    /** Asked from the public form: the reply goes by email as well as here. */
    public function viaEmail(): bool
    {
        return $this->guest_email !== null;
    }

    /** Confirmed by the sender — a member's always is; a visitor's once the code came back. */
    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->whereNotNull('confirmed_at');
    }

    /**
     * What somebody asked by email before they had an account becomes
     * theirs once that address is verified. Nothing is lost between asking
     * from the landing page and joining: the enquiry shows under Sent, and
     * the chat opens from it as usual.
     *
     * @return int how many were adopted
     */
    public static function adoptFor(User $user): int
    {
        if (! $user->email_verified_at || ! $user->email) {
            return 0;
        }

        $ids = static::whereNull('from_user_id')->where('guest_email', mb_strtolower($user->email))->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }
        // A verified address is the proof the code would have been.
        static::whereIn('id', $ids)->update(['from_user_id' => $user->id, 'claimed_at' => now(), 'confirmed_at' => now()]);
        // A quote sent while the enquiry was still a visitor's is theirs too.
        Quote::whereIn('enquiry_id', $ids)->whereNull('buyer_user_id')->update(['buyer_user_id' => $user->id]);

        return $ids->count();
    }

    /** The two people an enquiry is between. */
    public function involves(User $user): bool
    {
        return $user->id === $this->from_user_id || (bool) $this->page?->isMember($user);
    }
}
