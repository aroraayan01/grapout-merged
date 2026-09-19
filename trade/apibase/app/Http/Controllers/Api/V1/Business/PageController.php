<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\V1\Business\Concerns\SerializesBusiness;
use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\Business\Enquiry;
use App\Models\Business\Page;
use App\Models\Business\Product;
use App\Models\Post;
use App\Support\BusinessImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The business page beside a person's profile.
 *
 * Opened in a minute, from a phone: a name, a line about what you do,
 * where you are. Then it is the thing people follow, ask on, and find you
 * by — and the dashboard turns into its front window.
 */
class PageController extends Controller
{
    use SerializesBusiness;

    /**
     * Where this account stands on Trade: the page if there is one, the
     * professional fields on the profile, and what the dashboard needs.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['profile', 'businessPage']);
        $page = $user->businessPage;

        return response()->json(['data' => [
            'page' => $page ? $this->pageFull($page, $user) + ['verification' => $this->verificationBlock($page)] : null,
            'membership' => $page ? ($user->actsForPage()
                ? ['id' => 0, 'role' => 'owner', 'function' => null, 'function_label' => null, 'title' => 'GrapOut team', 'status' => 'active', 'invited_email' => null, 'user' => null, 'joined_at' => null, 'requested_at' => null, 'email' => null, 'note' => null, 'is_me' => true, 'acting' => true]
                : $this->memberRow($page->members()->where('user_id', $user->id)->first(), $user)) : null,
            // The GrapOut team, wearing a page: which one, and whose it is.
            'acting_page' => $page && $user->actsForPage() ? ['uuid' => $page->uuid, 'name' => $page->name, 'slug' => $page->slug, 'owner_uuid' => $page->owner?->uuid, 'owner' => $page->owner?->name] : null,
            // A request to join somebody's team, waiting for their answer.
            'join_request' => (function () use ($user) {
                $m = \App\Models\Business\CompanyMember::with('page')->where('user_id', $user->id)->where('status', 'requested')->first();

                return $m && $m->page ? [
                    'member_id' => $m->id,
                    'company' => ['slug' => $m->page->slug, 'name' => $m->page->name, 'logo_path' => $m->page->logo_path],
                    'function_label' => $m->function ? (\App\Models\Business\CompanyMember::FUNCTION_LABELS[$m->function] ?? ucfirst($m->function)) : null,
                    'requested_at' => $m->created_at?->toDateString(),
                ] : null;
            })(),
            'invitations' => \App\Models\Business\CompanyMember::with(['page', 'inviter'])->where('user_id', $user->id)->where('status', 'invited')->get()
                ->map(fn ($m) => ['member_id' => $m->id, 'company' => ['slug' => $m->page->slug, 'name' => $m->page->name, 'logo_path' => $m->page->logo_path], 'inviter' => $m->inviter?->name, 'role' => $m->role, 'function' => $m->function])->values(),
            'profile' => [
                'headline' => $user->profile?->headline,
                'designation' => $user->profile?->designation,
                'company_name' => $user->profile?->company_name,
                'industry' => $user->profile?->industry,
                'keywords' => $user->profile?->keywords ?? [],
            ],
            'counts' => $page ? [
                'new_enquiries' => Enquiry::confirmed()->where('page_id', $page->id)->where('status', 'new')->count(),
                'followers' => $page->followers_count,
                'products' => $page->products()->count(),
                'posts' => Post::where('page_id', $page->id)->count(),
            ] : null,
            'recent_posts' => $page
                ? Post::where('page_id', $page->id)->latest()->limit(3)->get()->map(fn ($p) => $this->postRow($p, $user))
                : [],
            'is_super_admin' => $user->isSuperAdmin(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user->isStaff(), 422, 'Admin accounts do not run a company page. To see a member\'s company, sign in as them from Admin.');
        abort_if($user->businessPage()->exists(), 422, 'You already represent a company. Leave it first to open another.');
        // One company at a time: while a request to join is waiting, no page of your own.
        $waiting = \App\Models\Business\CompanyMember::with('page')->where('user_id', $user->id)->where('status', 'requested')->first();
        abort_if($waiting, 422, "Your request to join {$waiting?->page?->name} is waiting for their answer. Withdraw it first to open your own page.");

        $data = $request->validate($this->rules() + ['name' => ['required', 'string', 'max:160']]);

        $page = Page::create($data + ['user_id' => $user->id, 'slug' => Page::slugFor($data['name'])]);
        \App\Services\PageRanker::recompute($page);
        $page->members()->create(['user_id' => $user->id, 'role' => 'owner', 'function' => $user->profile?->role_function ?? 'management', 'status' => 'active', 'joined_at' => now()]);
        $user->unsetRelation('businessPage');

        return response()->json(['message' => $page->name . ' is live. Add your first product.', 'data' => $this->pageFull($page, $user)], 201);
    }

    public function update(Request $request): JsonResponse
    {
        $page = $this->mine($request);
        $data = $request->validate($this->rules() + ['name' => ['sometimes', 'string', 'max:160']]);
        // The owner's switch only works once the team has allowed it — read fresh, the team may have just said yes.
        $allowed = $page->newQuery()->whereKey($page->id)->first(['contacts_allowed', 'whatsapp_allowed']);
        if (! $allowed?->contacts_allowed) {
            unset($data['show_contacts']);
        }
        if (! $allowed?->whatsapp_allowed) {
            unset($data['show_whatsapp']);
        }
        if (isset($data['markets'])) {
            $data['markets'] = array_values(array_unique(array_map('strtoupper', $data['markets'])));
        }
        $page->update($data);
        \App\Services\PageRanker::touch($page);

        return response()->json(['message' => 'Page updated.', 'data' => $this->pageFull($page->fresh(), $request->user())]);
    }

    /**
     * Close the page. Products, pictures, followers and enquiries go with it,
     * and the account is plain GrapOut again — free to open a new page later.
     */
    public function destroy(Request $request): JsonResponse
    {
        $page = $this->mine($request);
        abort_unless($page->roleOf($request->user()) === 'owner', 403, 'Only the owner can close the company page.');
        foreach ($page->products()->with('images')->get() as $product) {
            foreach ($product->images as $image) {
                \App\Support\BusinessImage::delete($image->path, $image->thumb_path);
            }
        }
        foreach ([$page->logo_path, $page->cover_path] as $path) {
            if ($path) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($path);
            }
        }
        $page->forceDelete();

        return response()->json(['message' => 'Your page is closed.']);
    }

    private function rules(): array
    {
        return [
            'tagline' => ['nullable', 'string', 'max:200'],
            'about' => ['nullable', 'string', 'max:5000'],
            'kind' => ['nullable', Rule::in(Page::KINDS)],
            'country' => ['nullable', 'string', 'size:2'],
            'city' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:200'],
            'email' => ['nullable', 'email', 'max:200'],
            'phone' => ['nullable', 'string', 'max:40'],
            'show_contacts' => ['sometimes', 'boolean'],
            'show_whatsapp' => ['sometimes', 'boolean'],
            // The owner's own switches; the team's locks are separate and win.
            'accept_calls' => ['sometimes', 'boolean'],
            'accept_meetings' => ['sometimes', 'boolean'],
            'markets' => ['nullable', 'array', 'max:60'],
            'markets.*' => ['string', 'size:2'],
            'certifications' => ['nullable', 'array', 'max:30'],
            'certifications.*' => ['string', 'max:60'],
            'year_established' => ['nullable', 'integer', 'min:1800', 'max:2100'],
            'keywords' => ['nullable', 'array', 'max:30'],
            'keywords.*' => ['string', 'max:40'],
        ];
    }

    /** The logo, or the banner. */
    public function uploadImage(Request $request, string $which): JsonResponse
    {
        abort_unless(in_array($which, ['logo', 'cover'], true), 404);
        $page = $this->mine($request);
        $request->validate(['file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:6144']]);

        $column = $which . '_path';
        BusinessImage::delete($page->{$column}, null);
        $page->update([$column => $request->file('file')->store('business/pages/' . $page->id, 'public')]);
        \App\Services\PageRanker::touch($page);

        return response()->json(['message' => ucfirst($which) . ' updated.', 'data' => [$column => $page->{$column}]]);
    }

    /**
     * The picture across the top of the dashboard: any of the page's own
     * product photos, or none. Only the page's own — a path from anywhere
     * else is refused, so this cannot be used to point at another file.
     */
    public function setHero(Request $request): JsonResponse
    {
        $page = $this->mine($request);
        $data = $request->validate(['path' => ['nullable', 'string', 'max:255']]);

        if ($data['path']) {
            $owned = Product::where('page_id', $page->id)
                ->whereHas('images', fn ($i) => $i->where('path', $data['path'])->orWhere('thumb_path', $data['path']))
                ->exists();
            abort_unless($owned, 422, 'Pick one of your own product photos.');
        }

        $page->update(['hero_path' => $data['path'] ?: null]);
        \App\Services\PageRanker::touch($page);

        return response()->json(['message' => $data['path'] ? 'Dashboard picture set.' : 'Dashboard picture removed.', 'data' => ['hero_path' => $page->hero_path]]);
    }

    /** Anybody's page, by its address. */
    public function show(Request $request, string $slug): JsonResponse
    {
        $viewer = $request->user();
        $page = Page::where('slug', $slug)
            ->where(fn ($q) => $q->live()->orWhere('user_id', $viewer->id))
            ->firstOrFail();

        $posts = Post::where('page_id', $page->id)->latest()->limit(10)->get()
            ->map(fn ($p) => $this->postRow($p, $viewer));

        return response()->json(['data' => $this->pageFull($page, $viewer) + ['posts' => $posts]]);
    }

    public function follow(Request $request, string $slug): JsonResponse
    {
        $viewer = $request->user();
        $page = Page::live()->where('slug', $slug)->firstOrFail();
        abort_if($page->user_id === $viewer->id, 422, 'That is your own page.');

        if (! $page->isFollowedBy($viewer)) {
            $page->followers()->attach($viewer->id);
            $page->update(['followers_count' => $page->followers()->count()]);
            $page->notifyTeam(new \App\Notifications\SocialNotification(
                'business_follow',
                "{$viewer->name} started following {$page->name}.",
                ['page_slug' => $page->slug, 'user_uuid' => $viewer->uuid],
                '/business?tab=followers',
            ));
        }

        return response()->json(['message' => 'Following ' . $page->name . '.', 'data' => ['following' => true, 'followers_count' => $page->fresh()->followers_count]]);
    }

    public function unfollow(Request $request, string $slug): JsonResponse
    {
        $page = Page::where('slug', $slug)->firstOrFail();
        $page->followers()->detach($request->user()->id);
        $page->update(['followers_count' => $page->followers()->count()]);

        return response()->json(['message' => 'Unfollowed.', 'data' => ['following' => false, 'followers_count' => $page->followers_count]]);
    }

    /** Who follows my page. */
    public function followers(Request $request): JsonResponse
    {
        $page = $this->mine($request);
        $rows = $page->followers()->with(['profile', 'settings', 'appId'])->latest('business_follows.created_at')->paginate(30);
        $rows->getCollection()->transform(fn ($u) => $this->personCard($u, $request->user()));

        return response()->json($rows);
    }

    /** The page this person runs. Representatives read; owner and admins change. */
    private function mine(Request $request, bool $manage = true): Page
    {
        $page = $request->user()->businessPage;
        abort_unless($page, 404, 'You are not on a company page yet.');
        abort_if($manage && ! $page->canManage($request->user()), 403, 'Only the owner or an admin can change the company page.');

        return $page;
    }

    // --- What the company buys and sells ------------------------------------------------------

    public function tradeLines(Request $request): JsonResponse
    {
        $page = $this->mine($request, manage: false);

        return response()->json(['data' => $this->tradeLineRows($page)]);
    }

    /** The whole list at once — the editor sends what it shows. */
    public function putTradeLines(Request $request): JsonResponse
    {
        $page = $this->mine($request);
        $data = $request->validate([
            'lines' => ['present', 'array', 'max:60'],
            'lines.*.direction' => ['required', Rule::in(['buy', 'sell'])],
            'lines.*.hs_code' => ['nullable', 'string', 'max:14', 'regex:/^[\d .]*$/'],
            'lines.*.description' => ['required', 'string', 'min:2', 'max:200'],
        ]);
        $page->tradeLines()->delete();
        foreach ($data['lines'] as $i => $line) {
            $page->tradeLines()->create([
                'direction' => $line['direction'],
                'hs_code' => \App\Models\Business\TradeLine::normalizeCode($line['hs_code'] ?? null),
                'description' => trim($line['description']),
                'sort' => $i,
            ]);
        }
        \App\Services\PageRanker::touch($page);

        return response()->json(['message' => 'Saved.', 'data' => $this->tradeLineRows($page->fresh())]);
    }
}
