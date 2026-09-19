<?php

namespace App\Http\Controllers\Api\V1\Business\Concerns;

use App\Models\Business\Enquiry;
use App\Models\Business\Job;
use App\Models\Business\Page;
use App\Models\Business\Product;
use App\Models\Business\Quote;
use App\Models\Business\Requirement;
use App\Models\Connection;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;

/**
 * One shape for each thing, wherever it is shown.
 *
 * A person is the same person in a search result, on a page, on a post and
 * on an enquiry; a product is the same product in a grid and in its sheet.
 * Each shape is written once, and every screen reads the same fields.
 */
trait SerializesBusiness
{
    // --- People ------------------------------------------------------------------

    /**
     * How $viewer stands with $other. The connect button is drawn from this.
     */
    protected function connectionState(?User $viewer, User $other): string
    {
        if (! $viewer) {
            return 'none';
        }
        if ($viewer->id === $other->id) {
            return 'self';
        }
        $row = Connection::where(fn ($q) => $q->where('requester_id', $viewer->id)->where('addressee_id', $other->id))
            ->orWhere(fn ($q) => $q->where('requester_id', $other->id)->where('addressee_id', $viewer->id))
            ->first();
        if (! $row) {
            return 'none';
        }
        if ($row->status === 'accepted') {
            return 'connected';
        }
        if ($row->status === 'pending') {
            return $row->requester_id === $viewer->id ? 'pending_sent' : 'pending_received';
        }

        return 'none';
    }

    /** Whether $viewer may see $other's photo, under $other's own setting. */
    protected function photoVisible(?User $viewer, User $other, string $state): bool
    {
        $pref = $other->settings?->privacyValue('profile_photo_visibility') ?? 'everyone';

        return $pref === 'everyone' || $state === 'self' || ($pref === 'connections' && $state === 'connected');
    }

    /** A person, as a card: who they are and what they do. */
    protected function personCard(User $u, ?User $viewer, bool $withPage = true): array
    {
        $u->loadMissing(['profile', 'settings', 'appId']);
        $state = $this->connectionState($viewer, $u);
        $photo = $this->photoVisible($viewer, $u, $state);

        return [
            'uuid' => $u->uuid,
            'name' => $u->name,
            'username' => $viewer ? $u->username : null,
            'app_id' => $viewer ? $u->appId?->app_id : null,
            'photo_path' => $photo ? $u->profile?->photo_path : null,
            'avatar' => $photo ? $u->profile?->avatar : null,
            'headline' => $u->profile?->headline,
            'designation' => $u->profile?->designation,
            'company_name' => $u->profile?->company_name,
            'industry' => $u->profile?->industry,
            'function' => $u->profile?->role_function,
            'country' => $u->profile?->country,
            'presence' => $u->presenceFor($viewer),
            'connection' => $state,
            'page' => $withPage && ($page = $u->businessPage()->live()->first())
                ? $this->pageCard($page, $viewer)
                : null,
        ];
    }

    // --- Pages ---------------------------------------------------------------------

    protected function pageCard(Page $p, ?User $viewer = null): array
    {
        return [
            'uuid' => $p->uuid,
            'slug' => $p->slug,
            'name' => $p->name,
            'tagline' => $p->tagline,
            'kind' => $p->kind,
            'country' => $p->country,
            'city' => $p->city,
            'logo_path' => $p->logo_path,
            'cover_path' => $p->cover_path,
            'hero_path' => $p->hero_path,
            'keywords' => $p->keywords ?? [],
            'markets' => $p->markets ?? [],
            'certifications' => $p->certifications ?? [],
            'year_established' => $p->year_established,
            'buys' => $this->tradeLineRows($p, 'buy'),
            'sells' => $this->tradeLineRows($p, 'sell'),
            'followers_count' => $p->followers_count,
            'product_count' => $p->products()->where('status', 'active')->count(),
            'status' => $p->status,
            'is_following' => $viewer ? $p->isFollowedBy($viewer) : false,
            'show_whatsapp' => $p->whatsappPublic(),
            'is_mine' => $p->isMember($viewer),
            'is_online' => $p->isOnline(),
            'trust' => $this->trustBlock($p),
            'featured' => $this->featured($p),
            'seeded' => $p->isSeeded(),
            'claimed' => ! $p->isUnclaimed(),
        ];
    }

    protected function pageFull(Page $p, ?User $viewer): array
    {
        $p->loadMissing(['owner.profile', 'owner.settings', 'owner.appId']);
        $mine = $p->isMember($viewer);

        $products = $p->products()->with('images')
            ->when(! $mine, fn ($q) => $q->where('status', 'active'))
            ->get()
            ->map(fn ($x) => $this->productCard($x, $viewer));

        return $this->pageCard($p, $viewer) + [
            'about' => $p->about,
            'address' => $p->address,
            'website' => $p->website,
            'email' => $p->email,
            'phone' => $p->phone,
            'show_contacts' => (bool) $p->show_contacts,
            'contacts_allowed' => (bool) $p->contacts_allowed,
            'whatsapp_allowed' => (bool) $p->whatsapp_allowed,
            'accept_calls' => (bool) ($p->accept_calls ?? true),
            'accept_meetings' => (bool) ($p->accept_meetings ?? true),
            'calls_disabled' => (bool) $p->calls_disabled,
            'meetings_disabled' => (bool) $p->meetings_disabled,
            'owner' => $this->personCard($p->owner, $viewer, false),
            'products' => $products,
            'team' => $this->teamRows($p, $viewer),
            'my_role' => $p->roleOf($viewer),
            'created_at' => $p->created_at?->toDateTimeString(),
        ];
    }

    // --- Products ------------------------------------------------------------------

    protected function productCard(Product $x, ?User $viewer = null): array
    {
        $x->loadMissing('images');
        $cover = $x->cover();

        return [
            'uuid' => $x->uuid,
            'slug' => $x->slug,
            'kind' => $x->kind,
            'name' => $x->name,
            'summary' => $x->summary,
            'price_type' => $x->price_type,
            'price_min' => $x->price_min,
            'price_max' => $x->price_max,
            'currency' => $x->currency,
            'price_unit' => $x->price_unit,
            'moq' => $x->moq,
            'moq_unit' => $x->moq_unit,
            'terms' => $x->terms ?? [],
            'category' => $x->category,
            'keywords' => $x->keywords ?? [],
            'hs_code' => $x->hs_code,
            'capacity' => $x->capacity,
            'certifications' => $x->certifications ?? [],
            'export_markets' => $x->export_markets ?? [],
            'cover' => $cover?->displayPath(),
            'image_count' => $x->images->count(),
            'status' => $x->status,
            'interested_count' => $x->interested_count,
            'enquiry_count' => $x->enquiry_count,
            'is_interested' => $viewer ? $x->interestedUsers()->where('users.id', $viewer->id)->exists() : false,
            'updated_at' => $x->updated_at?->toDateTimeString(),
        ];
    }

    protected function productFull(Product $x, ?User $viewer): array
    {
        $x->loadMissing(['images', 'page.owner.profile', 'page.owner.settings', 'page.owner.appId']);

        return $this->productCard($x, $viewer) + [
            'description' => $x->description,
            'specifications' => $x->specifications ?? [],
            'images' => $x->images->map(fn ($i) => ['id' => $i->id, 'path' => $i->path, 'display' => $i->displayPath()])->values(),
            'page' => $this->pageCard($x->page, $viewer),
            'owner' => $this->personCard($x->page->owner, $viewer, false),
        ];
    }

    // --- Enquiries -----------------------------------------------------------------

    protected function enquiryRow(Enquiry $e, User $viewer): array
    {
        $e->loadMissing(['product.images', 'page.owner', 'sender.profile', 'sender.settings', 'sender.appId', 'conversation', 'requirement', 'meeting', 'handler']);

        return [
            'uuid' => $e->uuid,
            'status' => $e->status,
            'direction' => $e->from_user_id === $viewer->id ? 'sent' : 'received',
            'message' => $e->message,
            'quantity' => $e->quantity,
            'target_price' => $e->target_price,
            'currency' => $e->currency,
            'product' => $e->product ? [
                'uuid' => $e->product->uuid,
                'name' => $e->product->name,
                'cover' => $e->product->cover()?->displayPath(),
                'price_label' => $this->priceLabel($e->product),
            ] : null,
            'page' => $e->page ? [
                'slug' => $e->page->slug,
                'name' => $e->page->name,
                'logo_path' => $e->page->logo_path,
                'owner' => $e->page->owner ? $this->personCard($e->page->owner, $viewer, false) : null,
            ] : null,
            'from' => $e->sender ? $this->personCard($e->sender, $viewer, true) : null,
            'is_guest' => $e->isGuest(),
            'via_email' => $e->viaEmail(),
            'confirmed' => $e->confirmed_at !== null,
            'guest' => $e->viaEmail() ? [
                'name' => $e->guest_name,
                'company' => $e->guest_company,
                'email' => $e->guest_email,
                'country' => $e->guest_country,
            ] : null,
            'owner_reply' => $e->owner_reply,
            'replied_at' => $e->replied_at?->toDateTimeString(),
            'quotes' => Quote::with(['page', 'supplier.profile'])->where('enquiry_id', $e->id)->orderByDesc('created_at')->get()->map(fn ($q) => $this->quoteRow($q, $viewer))->values(),
            'conversation_uuid' => $e->conversation?->uuid,
            'stage' => $e->stage ?? 'new',
            'requirement' => $e->requirement ? ['uuid' => $e->requirement->uuid, 'title' => $e->requirement->title, 'kind' => $e->requirement->kind] : null,
            'meeting' => $e->meeting ? ['code' => $e->meeting->code, 'title' => $e->meeting->title, 'scheduled_at' => $e->meeting_at?->toDateTimeString(), 'join_path' => '/meetings/room/' . $e->meeting->code, 'status' => $e->meeting->status] : null,
            'outcome' => $e->outcome,
            'outcome_note' => $e->outcome_note,
            'outcome_at' => $e->outcome_at?->toDateTimeString(),
            'handled_by' => $e->handler?->name,
            'created_at' => $e->created_at?->toDateTimeString(),
        ];
    }

    /** "USD 10–12 / kg", or "Ask for price". Kept beside the data it reads. */
    protected function priceLabel(Product $x): string
    {
        $unit = $x->price_unit ? ' / ' . $x->price_unit : '';
        if ($x->price_type === 'on_request' || ($x->price_min === null && $x->price_max === null)) {
            return 'Ask for price';
        }
        $n = fn ($v) => number_format((float) $v, 2);
        if ($x->price_type === 'fixed' || $x->price_max === null || $x->price_min == $x->price_max) {
            return $x->currency . ' ' . $n($x->price_min) . $unit;
        }

        return $x->currency . ' ' . $n($x->price_min) . '–' . $n($x->price_max) . $unit;
    }

    // --- Posts ---------------------------------------------------------------------

    protected function postRow(Post $post, ?User $viewer, bool $nest = true): array
    {
        $post->loadMissing(['author.profile', 'author.settings', 'author.appId', 'page']);

        $author = $post->author;
        $state = $this->connectionState($viewer, $author);
        $photo = $this->photoVisible($viewer, $author, $state);

        return [
            'uuid' => $post->uuid,
            'body' => $post->body,
            'images' => $post->images ?? [],
            'author' => [
                'uuid' => $author->uuid,
                'name' => $author->name,
                'username' => $viewer ? $author->username : null,
                'app_id' => $viewer ? $author->appId?->app_id : null,
                'photo_path' => $photo ? $author->profile?->photo_path : null,
                'avatar' => $photo ? $author->profile?->avatar : null,
                'headline' => $author->profile?->headline,
                'connection' => $state,
            ],
            'page' => $post->page ? ['slug' => $post->page->slug, 'name' => $post->page->name, 'logo_path' => $post->page->logo_path] : null,
            'repost_of' => $nest && $post->repost_of_id
                ? (($orig = Post::find($post->repost_of_id)) ? $this->postRow($orig, $viewer, false) : null)
                : null,
            'likes_count' => $post->likes_count,
            'comments_count' => $post->comments_count,
            'reposts_count' => $post->reposts_count,
            'liked' => $viewer ? $post->likers()->where('users.id', $viewer->id)->exists() : false,
            'is_mine' => $viewer ? $post->user_id === $viewer->id : false,
            'created_at' => $post->created_at?->toDateTimeString(),
        ];
    }

    protected function commentRow(PostComment $c, User $viewer): array
    {
        $c->loadMissing(['author.profile']);

        return [
            'uuid' => $c->uuid,
            'body' => $c->body,
            'author' => [
                'uuid' => $c->author->uuid,
                'name' => $c->author->name,
                'photo_path' => $c->author->profile?->photo_path,
                'avatar' => $c->author->profile?->avatar,
            ],
            'is_mine' => $c->user_id === $viewer->id,
            'created_at' => $c->created_at?->toDateTimeString(),
        ];
    }

    // --- Jobs ----------------------------------------------------------------------

    protected function jobRow(Job $job, User $viewer): array
    {
        $job->loadMissing('page');

        return [
            'uuid' => $job->uuid,
            'title' => $job->title,
            'description' => $job->description,
            'location' => $job->location,
            'work_type' => $job->work_type,
            'remote' => $job->remote,
            'salary_text' => $job->salary_text,
            'keywords' => $job->keywords ?? [],
            'status' => $job->status,
            'applications_count' => $job->applications_count,
            'page' => $job->page ? [
                'slug' => $job->page->slug, 'name' => $job->page->name, 'logo_path' => $job->page->logo_path,
                'city' => $job->page->city, 'country' => $job->page->country,
            ] : null,
            'applied' => $job->applications()->where('user_id', $viewer->id)->exists(),
            'is_mine' => $job->user_id === $viewer->id,
            'created_at' => $job->created_at?->toDateTimeString(),
        ];
    }

    // --- Helpers -------------------------------------------------------------------

    /** Ids of everyone $user is connected to. */
    protected function connectedIds(User $user): array
    {
        return Connection::where('status', 'accepted')
            ->where(fn ($q) => $q->where('requester_id', $user->id)->orWhere('addressee_id', $user->id))
            ->get(['requester_id', 'addressee_id'])
            ->map(fn ($c) => $c->requester_id === $user->id ? $c->addressee_id : $c->requester_id)
            ->unique()->values()->all();
    }

    // --- Trust ----------------------------------------------------------------------------

    /**
     * The reasons to believe a page: what the platform checked, how long
     * it has been here, and how it answers.
     */
    protected function trustBlock(Page $p): array
    {
        $hours = $p->response_hours;

        return [
            'verified' => $p->verification_status === 'verified',
            'verified_kind' => $p->verification_status === 'verified' ? $p->verification_kind : null,
            'verified_at' => $p->verified_at?->toDateString(),
            'member_since' => ($p->owner?->created_at ?? $p->created_at)?->toDateString(),
            'years' => max(0, (int) (($p->owner?->created_at ?? $p->created_at)?->diffInYears(now()) ?? 0)),
            'response_rate' => $p->response_rate,
            'response_hours' => $hours !== null ? (float) $hours : null,
            'response_label' => $hours === null ? null : ($hours < 1 ? 'within an hour' : ($hours < 24 ? 'within ' . (int) ceil($hours) . ' h' : ($hours < 72 ? 'within ' . (int) ceil($hours / 24) . ' days' : 'slow to respond'))),
        ];
    }

    /** A paid plan puts the page first. */
    protected function featured(Page $p): bool
    {
        $p->loadMissing('owner');

        return $p->owner ? app(\App\Services\SubscriptionEntitlementService::class)->tradePriority($p->owner) : false;
    }

    /** What the owner (and the admin) see about a verification. */
    protected function verificationBlock(Page $p): array
    {
        return [
            'status' => $p->verification_status,
            'kind' => $p->verification_kind,
            'number' => $p->verification_number,
            'note' => $p->verification_note,
            'requested_at' => $p->verification_requested_at?->toDateTimeString(),
            'verified_at' => $p->verified_at?->toDateTimeString(),
            'documents_count' => $p->relationLoaded('documents') ? $p->documents->count() : $p->documents()->count(),
            'min_documents' => \App\Http\Controllers\Api\V1\Business\VerificationController::MIN_DOCUMENTS,
        ];
    }

    // --- Requirements and quotes ------------------------------------------------------------

    protected function requirementRow(Requirement $r, ?User $viewer): array
    {
        $r->loadMissing('buyer.profile');

        $r->loadMissing('page');

        return [
            'uuid' => $r->uuid,
            'kind' => $r->kind ?? 'buy',
            'hs_code' => $r->hs_code,
            'frequency' => $r->frequency,
            'origin_countries' => $r->origin_countries ?? [],
            'target_markets' => $r->target_markets ?? [],
            'payment_terms' => $r->payment_terms,
            'partner_type' => $r->partner_type,
            'page' => $r->page ? ['slug' => $r->page->slug, 'name' => $r->page->name, 'logo_path' => $r->page->logo_path, 'country' => $r->page->country, 'trust' => $this->trustBlock($r->page)] : null,
            'title' => $r->title,
            'description' => $r->description,
            'category' => $r->category,
            'keywords' => $r->keywords ?? [],
            'quantity' => $r->quantity,
            'quantity_unit' => $r->quantity_unit,
            'target_price' => $r->target_price,
            'currency' => $r->currency,
            'terms' => $r->terms ?? [],
            'destination_country' => $r->destination_country,
            'destination_port' => $r->destination_port,
            'valid_until' => $r->valid_until?->toDateString(),
            'status' => $r->status,
            'quotes_count' => $r->quotes_count,
            'matched_count' => $r->matched_count,
            'is_mine' => $viewer ? $r->user_id === $viewer->id : false,
            'buyer' => $r->buyer ? $this->personCard($r->buyer, $viewer, true) : null,
            'created_at' => $r->created_at?->toDateTimeString(),
        ];
    }

    protected function quoteRow(Quote $q, ?User $viewer): array
    {
        $q->loadMissing(['page', 'supplier.profile']);

        return [
            'uuid' => $q->uuid,
            'price' => $q->price,
            'currency' => $q->currency,
            'price_unit' => $q->price_unit,
            'price_label' => "{$q->currency} " . number_format((float) $q->price, 2) . ($q->price_unit ? " / {$q->price_unit}" : ''),
            'moq' => $q->moq,
            'moq_unit' => $q->moq_unit,
            'lead_time_days' => $q->lead_time_days,
            'valid_days' => $q->valid_days,
            'expires_at' => $q->expiresAt()?->toDateString(),
            'expired' => $q->isExpired(),
            'terms' => $q->terms,
            'notes' => $q->notes,
            'status' => $q->status,
            'is_mine' => $viewer ? $q->user_id === $viewer->id : false,
            'is_for_me' => $viewer ? $q->buyer_user_id === $viewer->id : false,
            'page' => $q->page ? ['slug' => $q->page->slug, 'name' => $q->page->name, 'logo_path' => $q->page->logo_path, 'country' => $q->page->country, 'trust' => $this->trustBlock($q->page)] : null,
            'supplier' => $q->supplier ? $this->personCard($q->supplier, $viewer, false) : null,
            'responded_at' => $q->responded_at?->toDateTimeString(),
            'created_at' => $q->created_at?->toDateTimeString(),
        ];
    }

    // --- The team and the trade lines ------------------------------------------------------------

    protected function memberRow(?\App\Models\Business\CompanyMember $m, ?User $viewer): ?array
    {
        if (! $m) {
            return null;
        }
        $m->loadMissing('user.profile');

        return [
            'id' => $m->id,
            'role' => $m->role,
            'function' => $m->function,
            'function_label' => $m->function ? (\App\Models\Business\CompanyMember::FUNCTION_LABELS[$m->function] ?? ucfirst($m->function)) : null,
            'title' => $m->title,
            'status' => $m->status,
            'invited_email' => $m->user ? null : $m->invited_email,
            'user' => $m->user ? $this->personCard($m->user, $viewer, false) : null,
            'joined_at' => $m->joined_at?->toDateString(),
            'requested_at' => $m->status === 'requested' ? $m->created_at?->toDateString() : null,
            // For the owner and admins only: enough to know who is asking.
            'email' => $viewer && $m->user && $m->page?->canManage($viewer) ? $m->user->email : null,
            'note' => $viewer && $m->page?->canManage($viewer) ? $m->note : null,
            'is_me' => $viewer && $m->user_id === $viewer->id,
        ];
    }

    /** Active members for everybody; invitations and requests for those who run the page. */
    protected function teamRows(Page $p, ?User $viewer): array
    {
        $manage = $p->canManage($viewer);
        $rows = $p->members()->with('user.profile')
            ->when(! $manage, fn ($q) => $q->where('status', 'active'))
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'requested' THEN 1 ELSE 2 END")
            ->orderByRaw("CASE role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END")
            ->orderBy('joined_at')->get();

        return $rows->map(fn ($m) => $this->memberRow($m, $viewer))->values()->all();
    }

    protected function tradeLineRows(Page $p, ?string $direction = null): array
    {
        return $p->tradeLines()
            ->when($direction, fn ($q) => $q->where('direction', $direction))
            ->get()
            ->map(fn ($l) => ['id' => $l->id, 'direction' => $l->direction, 'hs_code' => $l->hs_code, 'description' => $l->description])
            ->values()->all();
    }
}
