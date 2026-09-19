<?php

namespace App\Models\Business;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person on a company's team.
 *
 * The role says what they may change; the function says what they do,
 * which is what a buyer looking for "the right person" reads.
 */
class CompanyMember extends Model
{
    public const ROLES = ['owner', 'admin', 'representative'];

    public const FUNCTIONS = ['management', 'procurement', 'sales', 'marketing', 'logistics', 'finance', 'quality', 'other'];

    public const FUNCTION_LABELS = [
        'management' => 'Management', 'procurement' => 'Procurement / Sourcing', 'sales' => 'Sales / Export', 'marketing' => 'Marketing',
        'logistics' => 'Logistics / Shipping', 'finance' => 'Finance', 'quality' => 'Quality / Compliance', 'other' => 'Other',
    ];

    protected $fillable = ['page_id', 'user_id', 'role', 'function', 'title', 'status', 'invited_email', 'invite_token', 'invited_by', 'joined_at', 'is_claim', 'note'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'is_claim' => 'boolean'];
    }

    protected $hidden = ['invite_token'];


    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function canManage(): bool
    {
        return $this->status === 'active' && in_array($this->role, ['owner', 'admin'], true);
    }
}
