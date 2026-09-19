<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Controller;
use App\Models\Business\Enquiry;
use App\Models\Business\Page;
use App\Models\Business\Product;
use App\Models\Business\Requirement;
use App\Models\Post;
use App\Models\User;
use App\Notifications\SocialNotification;
use App\Support\BooleanQuery;
use App\Support\Catalogue;
use App\Support\SignupGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * GrapOut Trade as the world sees it, with no account.
 *
 * Reading never needs a login: a page, its products, the search, the
 * directory. Acting does — connect, follow, message — except for asking,
 * which a stranger may do once with a name and an email. That enquiry is
 * how a buyer arrives; the reply is how they become a member.
 *
 * A signed-in person who lands here through a shared link is recognised
 * from their token, so the page shows their follow state and connection
 * exactly as it would inside the app.
 */
class PublicController extends Controller
{
    use Concerns\SerializesBusiness;

    /** The viewer, if the request happens to carry a session. */
    private function viewer(Request $request): ?User
    {
        $bearer = $request->bearerToken();
        if (! $bearer) {
            return null;
        }
        $token = PersonalAccessToken::findToken($bearer);
        $user = $token?->tokenable;

        return $user instanceof User && $user->status === 'active' ? $user : null;
    }

    // --- Reading ------------------------------------------------------------------

    public function page(Request $request, string $slug): JsonResponse
    {
        $page = Page::live()->where('slug', $slug)->with('owner')->firstOrFail();
        $viewer = $this->viewer($request);
        $data = $this->pageFull($page, $viewer);

        // The owner decides whether strangers get the phone and the email.
        // The website and the city were always for strangers.
        $public = $page->contactsPublic() || ($viewer && $viewer->id === $page->user_id);
        $data['email'] = $public ? $data['email'] : null;
        $data['phone'] = $public ? $data['phone'] : null;
        $data['show_contacts'] = (bool) $page->show_contacts;

        return response()->json(['data' => $data]);
    }

    public function product(Request $request, string $uuid): JsonResponse
    {
        $product = Product::live()->where('uuid', $uuid)->with(['images', 'page.owner'])->firstOrFail();

        return response()->json(['data' => $this->productFull($product, $this->viewer($request))]);
    }

    /**
     * Pages and products, by what they sell.
     *
     * The people search inside the app walks past nobody's who_can_find_me;
     * this one does not touch people at all. A business page is public by
     * its nature, and the person behind it is shown only as the page's
     * owner, with whatever their own settings allow.
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));
        $country = strtoupper((string) $request->query('country'));
        $category = trim((string) $request->query('category'));
        $kind = in_array($request->query('kind'), Page::KINDS, true) ? $request->query('kind') : '';
        abort_if(mb_strlen($q) < 2 && $country === '' && $category === '' && $kind === '', 422, 'Type at least two characters.');
        $viewer = $this->viewer($request);
        $alternatives = mb_strlen($q) >= 2 ? BooleanQuery::parse($q) : [];

        $pages = Page::live()->with('owner')
            ->when($alternatives, fn ($b) => BooleanQuery::apply($b, $alternatives, fn (Builder $w, string $term) => $this->pageMatches($w, $term)))
            ->when($country !== '', fn ($b) => $b->where('country', $country))
            ->when($kind !== '', fn ($b) => $b->where('kind', $kind))
            ->when($category !== '', fn ($b) => $b->whereHas('products', fn ($x) => $x->where('status', 'active')->whereRaw('LOWER(category) = ?', [mb_strtolower($category)])))
            // Plan tier, then the team's boost, then days worked on — see PageRanker.
            ->orderByDesc('rank_score')->orderByDesc('followers_count')->orderBy('name')
            ->limit(24)->get()
            ->map(fn (Page $p) => $this->pageCard($p, $viewer) + ['owner' => $this->personCard($p->owner, $viewer, false), 'city' => $p->city]);

        $products = Product::live()->with(['images', 'page'])
            ->when($alternatives, fn ($b) => BooleanQuery::apply($b, $alternatives, fn (Builder $w, string $term) => $this->productMatches($w, $term)))
            ->when($country !== '', fn ($b) => $b->whereHas('page', fn ($p) => $p->where('country', $country)))
            ->when($kind !== '', fn ($b) => $b->whereHas('page', fn ($p) => $p->where('kind', $kind)))
            ->when($category !== '', fn ($b) => $b->whereRaw('LOWER(category) = ?', [mb_strtolower($category)]))
            ->orderByDesc(Page::select('rank_score')->whereColumn('business_pages.id', 'business_products.page_id'))
            ->orderByDesc('interested_count')->orderByDesc('updated_at')
            ->limit(48)->get()
            ->map(fn (Product $x) => $this->productCard($x, $viewer) + ['page' => ['slug' => $x->page->slug, 'name' => $x->page->name, 'country' => $x->page->country, 'logo_path' => $x->page->logo_path, 'featured' => $this->featured($x->page)]]);

        $intents = Requirement::live()->with(['buyer.profile', 'page'])
            ->when($alternatives, fn ($b) => BooleanQuery::apply($b, $alternatives, fn (Builder $w, string $term) => $this->intentMatches($w, $term)))
            ->when($country !== '', fn ($b) => $b->where(fn ($q) => $q->where('destination_country', $country)->orWhereRaw('LOWER(target_markets) LIKE ?', ['%' . strtolower($country) . '%'])->orWhereRaw('LOWER(origin_countries) LIKE ?', ['%' . strtolower($country) . '%'])))
            ->when($kind !== '', fn ($b) => $b->whereHas('page', fn ($p) => $p->where('kind', $kind)))
            ->orderByDesc('created_at')->limit(24)->get()
            ->map(fn (Requirement $r) => $this->publicIntent($r, $viewer));

        return response()->json(['data' => ['pages' => $pages, 'products' => $products, 'intents' => $intents], 'q' => $q]);
    }

    /** The front page: what is on offer, by category and by country. */
    public function directory(Request $request): JsonResponse
    {
        $viewer = $this->viewer($request);
        $live = Product::live();

        // The standard list, each with how many products sit on that shelf; a
        // category nobody has filled yet still shows, so browsing reads the same
        // everywhere. Whatever free text older products carry counts by name.
        $byCategory = (clone $live)->whereNotNull('category')->where('category', '!=', '')
            ->selectRaw('LOWER(category) as c, COUNT(*) as n')->groupBy('c')->pluck('n', 'c');
        $categories = collect(\App\Support\TradeCategories::LIST)
            ->map(fn ($name) => ['name' => $name, 'count' => (int) ($byCategory[mb_strtolower($name)] ?? 0)])
            ->sortByDesc('count')->values();

        $byKind = Page::live()->selectRaw('kind, COUNT(*) as n')->groupBy('kind')->pluck('n', 'kind');
        $kinds = collect(['exporter', 'importer', 'manufacturer', 'merchant_exporter'])
            ->map(fn ($k) => ['kind' => $k, 'count' => (int) ($byKind[$k] ?? 0)])->values();

        $countries = Page::live()->whereNotNull('country')
            ->selectRaw('country, COUNT(*) as n')->groupBy('country')->orderByDesc('n')->limit(24)->get()
            ->map(fn ($r) => ['iso' => $r->country, 'count' => (int) $r->n]);

        $recent = (clone $live)->with(['images', 'page'])->orderByDesc('created_at')->limit(12)->get()
            ->map(fn (Product $x) => $this->productCard($x, $viewer) + ['page' => ['slug' => $x->page->slug, 'name' => $x->page->name, 'country' => $x->page->country, 'logo_path' => $x->page->logo_path]]);

        $pages = Page::live()->with('owner')->orderByDesc('rank_score')->orderByDesc('followers_count')->orderByDesc('created_at')->limit(8)->get()
            ->map(fn (Page $p) => $this->pageCard($p, $viewer) + ['city' => $p->city]);

        return response()->json(['data' => [
            'categories' => $categories,
            'kinds' => $kinds,
            'countries' => $countries,
            'recent' => $recent,
            'pages' => $pages,
            'opportunities' => Requirement::live()->with(['buyer.profile', 'page'])->orderByDesc('created_at')->limit(12)->get()->map(fn (Requirement $r) => $this->publicIntent($r, $viewer)),
            'counts' => ['pages' => Page::live()->count(), 'products' => (clone $live)->count(), 'opportunities' => Requirement::live()->count()],
        ]]);
    }

    /** What the page has posted, for anybody who opens it. */
    public function posts(Request $request, string $slug): JsonResponse
    {
        $page = Page::live()->where('slug', $slug)->firstOrFail();
        $viewer = $this->viewer($request);
        $rows = Post::where('page_id', $page->id)->orderByDesc('created_at')->paginate(15);
        $rows->getCollection()->transform(fn (Post $p) => $this->postRow($p, $viewer));

        return response()->json($rows);
    }

    /** The page as a PDF, for WhatsApp and for the desk drawer. */
    public function catalogue(Request $request, string $slug)
    {
        $page = Page::live()->where('slug', $slug)->firstOrFail();
        $link = rtrim((string) config('mypa.frontend_url'), '/') . '/c/' . $page->slug;
        // ?prices=hide: the same catalogue with every price replaced by "on enquiry".
        $prices = $request->query('prices') !== 'hide';
        $file = \Illuminate\Support\Str::slug($page->name) . '-catalogue' . ($prices ? '' : '-no-prices') . '.pdf';

        return response(Catalogue::pdf($page, $link, $prices), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $file . '"',
        ]);
    }

    /** What buyers are asking for, readable by any supplier — quoting needs a page. */
    public function requirements(Request $request): JsonResponse
    {
        $viewer = $this->viewer($request);
        $q = trim((string) $request->query('q'));
        $rows = Requirement::live()->with('buyer.profile')
            ->when(mb_strlen($q) >= 2, fn ($b) => BooleanQuery::apply($b, BooleanQuery::parse($q), function (Builder $w, string $t) {
                $like = '%' . mb_strtolower($t) . '%';
                $w->whereRaw('LOWER(title) LIKE ?', [$like])->orWhereRaw('LOWER(description) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(keywords) LIKE ?', [$like])->orWhereRaw('LOWER(category) LIKE ?', [$like]);
            }))
            ->when($request->query('country'), fn ($b, $cc) => $b->where('destination_country', strtoupper($cc)))
            ->when($request->query('kind'), fn ($b, $k) => $b->where('kind', $k))
            ->orderByDesc('created_at')
            ->paginate(20);
        $rows->getCollection()->transform(fn (Requirement $r) => $this->publicIntent($r, $viewer));

        return response()->json($rows);
    }

    private function pageMatches(Builder $w, string $term): void
    {
        $like = '%' . mb_strtolower($term) . '%';
        $w->whereRaw('LOWER(business_pages.name) LIKE ?', [$like])
            ->orWhereRaw('LOWER(business_pages.tagline) LIKE ?', [$like])
            ->orWhereRaw('LOWER(business_pages.about) LIKE ?', [$like])
            ->orWhereRaw('LOWER(business_pages.keywords) LIKE ?', [$like])
            ->orWhereRaw('LOWER(business_pages.city) LIKE ?', [$like])
            ->orWhereHas('tradeLines', fn ($t) => $t->where('hs_code', 'like', (preg_replace('/\D/', '', $term) ?: '~') . '%')->orWhereRaw('LOWER(description) LIKE ?', [$like]))
            ->orWhereHas('products', fn ($x) => $x->where('business_products.status', 'active')->where(fn ($xx) => $xx
                ->whereRaw('LOWER(business_products.name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(business_products.keywords) LIKE ?', [$like])
                ->orWhereRaw('LOWER(business_products.category) LIKE ?', [$like])));
    }

    /** An intent as a stranger may see it: the poster is a name and a flag, not somebody to look up. */
    private function publicIntent(Requirement $r, ?User $viewer): array
    {
        $row = $this->requirementRow($r, $viewer);
        if (! $viewer && $row['buyer']) {
            $row['buyer'] = ['name' => $row['buyer']['name'], 'country' => $row['buyer']['country'], 'company_name' => $row['buyer']['company_name']];
        }

        return $row;
    }

    private function intentMatches(Builder $w, string $term): void
    {
        $like = '%' . mb_strtolower($term) . '%';
        $digits = preg_replace('/\D/', '', $term);
        $w->when(strlen($digits) >= 2 && $digits === $term, fn ($q) => $q->orWhere('hs_code', 'like', $digits . '%'))
            ->orWhereRaw('LOWER(title) LIKE ?', [$like])->orWhereRaw('LOWER(description) LIKE ?', [$like])
            ->orWhereRaw('LOWER(keywords) LIKE ?', [$like])->orWhereRaw('LOWER(category) LIKE ?', [$like]);
    }

    private function productMatches(Builder $w, string $term): void
    {
        $like = '%' . mb_strtolower($term) . '%';
        $digits = preg_replace('/\D/', '', $term);
        $w->when(strlen($digits) >= 2 && $digits === $term, fn ($q) => $q->orWhere('business_products.hs_code', 'like', $digits . '%'))
            ->orWhereRaw('LOWER(business_products.name) LIKE ?', [$like])
            ->orWhereRaw('LOWER(business_products.summary) LIKE ?', [$like])
            ->orWhereRaw('LOWER(business_products.keywords) LIKE ?', [$like])
            ->orWhereRaw('LOWER(business_products.category) LIKE ?', [$like])
            ->orWhereHas('page', fn ($p) => $p->whereRaw('LOWER(business_pages.name) LIKE ?', [$like]));
    }

    // --- Asking without an account ----------------------------------------------

    public function enquire(Request $request): JsonResponse
    {
        // A public form is a door for scripts. Same three layers as sign-up.
        SignupGuard::assertHuman($request, 'email');

        $data = $request->validate([
            'product' => ['nullable', 'string'],
            'page' => ['nullable', 'string'],
            'name' => ['required', 'string', 'max:120'],
            'company' => ['nullable', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:255'],
            'country' => ['nullable', 'string', 'size:2'],
            'message' => ['required', 'string', 'min:5', 'max:3000'],
            'quantity' => ['nullable', 'string', 'max:80'],
            'target_price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'company_website' => ['nullable', 'string', 'max:255'],
            'form_started_at' => ['nullable', 'numeric'],
            'turnstile_token' => ['nullable', 'string', 'max:2048'],
        ]);

        $product = null;
        if (! empty($data['product'])) {
            $product = Product::live()->where('uuid', $data['product'])->with('page.owner')->firstOrFail();
            $page = $product->page;
        } else {
            abort_if(empty($data['page']), 422, 'Say which product or page this is about.');
            $page = Page::live()->where('slug', $data['page'])->with('owner')->firstOrFail();
        }

        // A member who forgot to sign in still gets a member's enquiry.
        $viewer = $this->viewer($request);
        abort_if($viewer && $viewer->id === $page->user_id, 422, 'That is your own page.');

        $enquiry = Enquiry::create([
            'page_id' => $page->id,
            'product_id' => $product?->id,
            'from_user_id' => $viewer?->id,
            'guest_name' => $viewer ? null : $data['name'],
            'guest_company' => $viewer ? null : ($data['company'] ?? null),
            'guest_email' => $viewer ? null : mb_strtolower($data['email']),
            'guest_country' => $viewer ? null : (isset($data['country']) ? strtoupper($data['country']) : null),
            'guest_token' => $viewer ? null : Str::random(64),
            // A visitor proves the address first; a member has already.
            'guest_code' => $viewer ? null : self::code(),
            'guest_code_expires_at' => $viewer ? null : now()->addMinutes((int) config('trade.enquiry_code_minutes', 30)),
            'confirmed_at' => $viewer ? now() : null,
            'message' => $data['message'],
            'quantity' => $data['quantity'] ?? null,
            'target_price' => $data['target_price'] ?? null,
            'currency' => isset($data['currency']) ? strtoupper($data['currency']) : $product?->currency,
        ]);
        if ($product) {
            Product::whereKey($product->id)->increment('enquiry_count');
        }

        if (! $viewer) {
            \Illuminate\Support\Facades\Mail::to($enquiry->guest_email, $enquiry->guest_name)->send(new \App\Mail\EnquiryCode($enquiry, $enquiry->guest_code));

            return response()->json([
                'pending' => true,
                'uuid' => $enquiry->uuid,
                'message' => "One step left: type the code we just emailed to {$data['email']} and your enquiry goes to {$page->name}.",
            ], 201);
        }

        $this->deliver($enquiry, $viewer);

        return response()->json(['message' => "Sent to {$page->name}. You will hear back in your GrapOut enquiries."], 201);
    }

    /**
     * The code came back: the enquiry goes to the company, and the visitor
     * gets the account it will be answered in — signed in, on the code as
     * a temporary password. An address that already has an account keeps
     * it; the enquiry becomes theirs and they sign in as usual.
     */
    public function confirm(Request $request, string $uuid, \App\Services\VisitorAccounts $accounts): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:16']]);
        $enquiry = Enquiry::whereNull('confirmed_at')->whereNotNull('guest_code')->where('uuid', $uuid)->with(['page.owner', 'product'])->firstOrFail();
        $given = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $data['code']));
        abort_if($enquiry->guest_code_expires_at && $enquiry->guest_code_expires_at->isPast(), 422, 'That code has expired. Send the enquiry again for a fresh one.');
        abort_unless(hash_equals($enquiry->guest_code, $given), 422, 'That is not the code we emailed. Check the email and try again.');

        $existing = User::whereRaw('LOWER(email) = ?', [mb_strtolower($enquiry->guest_email)])->first();
        $user = $existing ?? $accounts->createFromEnquiry($enquiry, $enquiry->guest_code);

        $enquiry->update([
            'confirmed_at' => now(),
            'from_user_id' => $user->id,
            'claimed_at' => now(),
            'guest_code' => null,
            'guest_code_expires_at' => null,
        ]);
        \App\Models\Business\Quote::where('enquiry_id', $enquiry->id)->whereNull('buyer_user_id')->update(['buyer_user_id' => $user->id]);
        $this->deliver($enquiry->fresh(['page', 'product']), $user);

        $page = $enquiry->page;
        if ($existing) {
            return response()->json([
                'message' => "Sent to {$page->name}. You already have a GrapOut account with this email — sign in to follow the reply under Enquiries.",
                'existing' => true,
            ]);
        }

        return response()->json([
            'message' => "Sent to {$page->name}. Your GrapOut account is open and you are signed in; the code is your password until you change it in Settings.",
            'existing' => false,
            'token' => $user->createToken('web')->plainTextToken,
            'data' => new \App\Http\Resources\UserResource($user->load(['profile', 'settings', 'appId', 'roles'])),
        ]);
    }

    /** The company hears about it, and a seeded page gets its email. */
    private function deliver(Enquiry $enquiry, ?User $sender): void
    {
        $page = $enquiry->page;
        $product = $enquiry->product;
        $who = $sender?->name ?? trim((string) $enquiry->guest_name . ($enquiry->guest_company ? " ({$enquiry->guest_company})" : ''));
        $page->notifyTeam(new SocialNotification(
            'business_enquiry',
            "{$who} asked about " . ($product?->name ?? $page->name) . ($enquiry->viaEmail() ? ' — from the public page; the reply also goes to their email.' : '.'),
            ['enquiry_uuid' => $enquiry->uuid, 'product_uuid' => $product?->uuid, 'guest' => $enquiry->viaEmail()],
            '/business/enquiries',
            'business-enquiry-' . $enquiry->uuid,
        ));

        if ($page->isUnclaimed() && $page->email) {
            \Illuminate\Support\Facades\Mail::to($page->email)->send(new \App\Mail\SeededEnquiry($enquiry));
        }
    }

    /** Eight characters a person can read back from a phone: no 0/O, no 1/I. */
    private static function code(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 8; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }

    /**
     * What the link in the reply email opens.
     *
     * The exchange so far, and whether it has already been carried into
     * an account — so the page can offer "sign in" or "open the chat".
     */
    public function thread(string $token): JsonResponse
    {
        $e = Enquiry::where('guest_token', $token)->with(['page', 'product.images', 'sender'])->firstOrFail();

        return response()->json(['data' => [
            'uuid' => $e->uuid,
            'guest' => ['name' => $e->guest_name, 'email' => $e->guest_email, 'company' => $e->guest_company, 'country' => $e->guest_country],
            'page' => ['slug' => $e->page->slug, 'name' => $e->page->name, 'logo_path' => $e->page->logo_path],
            'product' => $e->product ? ['uuid' => $e->product->uuid, 'name' => $e->product->name, 'cover' => $e->product->cover()?->displayPath()] : null,
            'message' => $e->message,
            'owner_reply' => $e->owner_reply,
            'replied_at' => $e->replied_at?->toDateTimeString(),
            'claimed' => $e->claimed_at !== null,
            'claimed_by_uuid' => $e->sender?->uuid,
            'created_at' => $e->created_at?->toDateTimeString(),
        ]]);
    }
}
