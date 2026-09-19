<?php

namespace App\Models\Grap;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A named pile of leads — the old My Contacts folder.
 *
 * Not called `List`: that is a language construct in PHP and a class of
 * that name cannot be referred to without ceremony.
 *
 * A list holds buyers and suppliers together. The old folders were tied to
 * one contact type, so a trip to Dubai needed one folder of buyers and
 * another of suppliers, kept in step by hand.
 */
class LeadList extends Model
{
    use HasUuids;

    protected $table = 'grap_lists';

    protected $fillable = ['user_id', 'name', 'note'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function leads(): BelongsToMany
    {
        return $this->belongsToMany(Lead::class, 'grap_list_lead', 'grap_list_id', 'grap_lead_id')
            ->withPivot('note')->withTimestamps();
    }
}
