<?php

namespace App\Models\Outreach;

use App\Models\Business\Page;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A named part of a page's contacts: "Germany buyers", "Delhi fair 2026". A contact can be on several. */
class ContactList extends Model
{
    use HasUuids;

    protected $table = 'outreach_lists';

    protected $fillable = ['page_id', 'name', 'description', 'created_by'];

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

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'outreach_contact_list', 'list_id', 'contact_id');
    }
}
