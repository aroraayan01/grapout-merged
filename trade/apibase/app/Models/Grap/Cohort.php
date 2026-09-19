<?php

namespace App\Models\Grap;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An email somebody wrote once to send to the contacts they find.
 *
 * Not the mailing system's cohort (a saved slice of a contact list) — this is
 * content: a subject and a body. The placeholders are filled per contact in
 * the Book meeting preview, where the person can still change the words
 * before it goes.
 */
class Cohort extends Model
{
    use HasUuids;

    protected $table = 'grap_cohorts';

    /** What a cohort may say, and what each one becomes. */
    public const PLACEHOLDERS = [
        'contact_name' => "The contact's name, or 'there' when there is none",
        'first_name' => "The contact's first name, or 'there'",
        'company_name' => "The contact's company",
        'sender_name' => 'Your name',
        'sender_email' => 'Your email address',
    ];

    public const MAX_PER_USER = 50;

    protected $fillable = ['user_id', 'name', 'subject', 'body'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toApi(): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'subject' => $this->subject,
            'body' => $this->body,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
