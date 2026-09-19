<?php

namespace App\Models\Grap;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The receipt for a contact somebody unlocked, from either tab.
 *
 * A directory unlock points at a `grap_lead` and is unique per person per
 * lead: asking twice is not charged twice. On the old site every look at the
 * same buyer spent another credit, so people learned to screenshot rather than
 * come back, and the figures counted views instead of leads.
 *
 * A Grap Company unlock has no lead to point at — the person did not exist in
 * any table before the search found them — so it carries the address and the
 * company instead. Both count against the same allowance, because from where
 * the user sits they are the same thing: a verified address they did not have
 * before.
 */
class Reveal extends Model
{
    protected $table = 'grap_reveals';

    public const SOURCE_DIRECTORY = 'directory';

    public const SOURCE_COMPANY = 'company';

    protected $fillable = [
        'user_id', 'grap_lead_id', 'source', 'email', 'company_name', 'channels', 'credits',
    ];

    protected function casts(): array
    {
        return ['credits' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'grap_lead_id');
    }
}
