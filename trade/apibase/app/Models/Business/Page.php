<?php

namespace App\Models\Business;

use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Business\Quote;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A person's business page — the company face on an ordinary account.
 *
 * One per person, and the person is the page: buyers who follow it, ask on
 * it, or connect through it end up talking to this one account, with its
 * existing chat, calls and privacy settings.
 */
class Page extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'business_pages';

    public const KINDS = ['exporter', 'importer', 'manufacturer', 'merchant_exporter', 'trader', 'service', 'other'];

    protected $fillable = [
        'user_id', 'slug', 'name', 'tagline', 'about', 'kind', 'country', 'city', 'address', 'show_contacts', 'show_whatsapp', 'contacts_allowed', 'whatsapp_allowed', 'calls_disabled', 'meetings_disabled', 'accept_calls', 'accept_meetings', 'outreach_enabled', 'outreach_daily_limit', 'outreach_bulk_allowed', 'outreach_mailbox_id', 'outreach_mailbox_group_id',
        'verification_status', 'verification_kind', 'verification_number', 'verification_document_path',
        'verification_note', 'verification_requested_at', 'verified_at', 'response_rate', 'response_hours',
        'website', 'email', 'phone', 'logo_path', 'cover_path', 'hero_path', 'keywords', 'status',
        'followers_count', 'rank_boost', 'activity_score', 'rank_score', 'last_activity_at', 'markets', 'certifications', 'year_established',
        'seeded_source', 'seeded_ref', 'claimed_at',
    ];

    protected function casts(): array
    {
        return ['keywords' => 'array', 'markets' => 'array', 'certifications' => 'array', 'show_contacts' => 'boolean', 'show_whatsapp' => 'boolean', 'contacts_allowed' => 'boolean', 'whatsapp_allowed' => 'boolean', 'calls_disabled' => 'boolean', 'meetings_disabled' => 'boolean', 'accept_calls' => 'boolean', 'accept_meetings' => 'boolean', 'outreach_enabled' => 'boolean', 'outreach_bulk_allowed' => 'boolean', 'verification_requested_at' => 'datetime', 'verified_at' => 'datetime', 'claimed_at' => 'datetime'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'page_id')->orderBy('sort')->orderByDesc('id');
    }

    public function enquiries(): HasMany
    {
        return $this->hasMany(Enquiry::class, 'page_id');
    }

    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'business_follows', 'page_id', 'user_id')->withTimestamps();
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'page_id');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(Job::class, 'page_id');
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('business_pages.status', 'active');
    }

    // --- Seeded pages --------------------------------------------------------------------------

    /** Put here by the research, not by the company. */
    public function isSeeded(): bool
    {
        return $this->seeded_source !== null;
    }

    /** Seeded and nobody from the company has taken it yet. */
    public function isUnclaimed(): bool
    {
        return $this->isSeeded() && $this->claimed_at === null;
    }

    /** Hand a seeded page to its first owner. */
    public function grantTo(User $user, ?string $function = null, ?string $title = null): void
    {
        $this->members()->where('is_claim', true)->where('user_id', '!=', $user->id)->delete();
        $this->members()->updateOrCreate(
            ['user_id' => $user->id],
            ['role' => 'owner', 'status' => 'active', 'is_claim' => false, 'function' => $function ?? 'management', 'title' => $title, 'joined_at' => now(), 'invite_token' => null],
        );
        $this->forceFill(['user_id' => $user->id, 'claimed_at' => now()])->save();
        $user->unsetRelation('businessPage');
    }

    // --- The team -------------------------------------------------------------------------

    public function members(): HasMany
    {
        return $this->hasMany(CompanyMember::class, 'page_id');
    }

    /** Email and phone on the public page: the owner said yes, and the team allowed it. */
    public function contactsPublic(): bool
    {
        return (bool) $this->show_contacts && (bool) $this->contacts_allowed;
    }

    /** The WhatsApp share button: same two yeses. */
    public function whatsappPublic(): bool
    {
        return (bool) $this->show_whatsapp && (bool) $this->whatsapp_allowed;
    }

    /** The company's own email list. */
    public function outreachContacts(): HasMany
    {
        return $this->hasMany(\App\Models\Outreach\Contact::class, 'page_id');
    }

    /** Everything sent to that list from here. */
    public function outreachSends(): HasMany
    {
        return $this->hasMany(\App\Models\Outreach\Send::class, 'page_id');
    }

    /** The folder behind the verified badge. */
    public function documents(): HasMany
    {
        return $this->hasMany(CompanyDocument::class, 'page_id');
    }

    public function team(): HasMany
    {
        return $this->members()->where('status', 'active')->orderByRaw("CASE role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END")->orderBy('joined_at');
    }

    public function tradeLines(): HasMany
    {
        return $this->hasMany(TradeLine::class, 'page_id')->orderBy('sort')->orderBy('id');
    }

    /** The group of mailboxes this page's campaigns rotate across, when the team set one. */
    public function mailboxGroup(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Outreach\MailboxGroup::class, 'outreach_mailbox_group_id');
    }

    /** Is this person on the team? Reads the relation the user already carries. */
    public function isMember(?User $user): bool
    {
        return $user !== null && $user->businessPage?->id === $this->id;
    }

    public function roleOf(?User $user): ?string
    {
        if (! $this->isMember($user)) {
            return null;
        }
        // The GrapOut team, acting for this page, runs it as its owner would.
        if ($user->actsForPage()) {
            return 'owner';
        }

        return $this->members()->where('user_id', $user->id)->where('status', 'active')->value('role');
    }

    /** Owner and admins run the page; representatives act for it. */
    public function canManage(?User $user): bool
    {
        return in_array($this->roleOf($user), ['owner', 'admin'], true);
    }

    /**
     * Tell the people who run the page — or the whole team — something.
     * A notification to a company is a notification to its people.
     */
    public function notifyTeam($notification, bool $managersOnly = false, ?User $except = null): void
    {
        $this->team()->with('user')->get()
            ->filter(fn ($m) => $m->user && (! $managersOnly || $m->canManage()) && (! $except || $m->user_id !== $except->id))
            ->each(fn ($m) => $m->user->notify($notification));
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class, 'page_id');
    }

    /**
     * How this page answers, from the last ninety days of enquiries.
     *
     * The rate is enquiries answered — a reply, a quote, or the chat opened
     * — over enquiries received. The hours are the median wait for that
     * first answer. Recomputed whenever the page answers, and stored, so
     * a card costs nothing to draw.
     */
    public function refreshResponseStats(): void
    {
        $since = now()->subDays(90);
        $rows = $this->enquiries()->where('created_at', '>=', $since)->get(['created_at', 'replied_at']);
        if ($rows->count() < 1) {
            $this->forceFill(['response_rate' => null, 'response_hours' => null])->save();

            return;
        }
        $answered = $rows->filter(fn ($e) => $e->replied_at !== null);
        $waits = $answered->map(fn ($e) => max(0, $e->created_at->diffInMinutes($e->replied_at) / 60))->sort()->values();
        $median = $waits->isEmpty() ? null : ($waits->count() % 2
            ? $waits[intdiv($waits->count(), 2)]
            : ($waits[$waits->count() / 2 - 1] + $waits[$waits->count() / 2]) / 2);

        $this->forceFill([
            'response_rate' => (int) round($answered->count() / $rows->count() * 100),
            'response_hours' => $median === null ? null : round($median, 1),
        ])->save();
    }

    public function isFollowedBy(User $user): bool
    {
        return $this->followers()->where('users.id', $user->id)->exists();
    }

    /** Somebody at their desk — for a page that is one person, the person. */
    public function isOnline(): bool
    {
        if ($this->isUnclaimed()) {
            return false;
        }
        return $this->owner?->presenceState() === 'online';
    }

    public static function slugFor(string $name): string
    {
        $base = Str::limit(Str::slug($name) ?: 'page', 120, '');

        do {
            $slug = $base . '-' . Str::lower(Str::random(4));
        } while (self::withTrashed()->where('slug', $slug)->exists());

        return $slug;
    }
}
