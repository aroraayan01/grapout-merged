<?php

namespace App\Models\Outreach;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Several mailboxes that send as one: a campaign pointed at a group is
 * spread across its mailboxes, each within its own daily allowance, so
 * no single address carries the whole load.
 */
class MailboxGroup extends Model
{
    use HasUuids;

    protected $table = 'outreach_mailbox_groups';

    protected $fillable = ['name', 'description', 'active', 'created_by'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function mailboxes(): HasMany
    {
        return $this->hasMany(Mailbox::class, 'group_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The mailboxes that can still send today, most room first. */
    public function boxesWithRoom()
    {
        return $this->mailboxes()->where('active', true)->get()
            ->map(fn (Mailbox $m) => ['box' => $m, 'room' => max(0, (int) $m->daily_limit - $m->sentToday())])
            ->filter(fn ($r) => $r['room'] > 0)
            ->sortByDesc('room')->values();
    }
}
