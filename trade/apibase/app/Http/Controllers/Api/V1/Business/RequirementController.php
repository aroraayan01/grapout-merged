<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\V1\Business\Concerns\SerializesBusiness;
use App\Http\Controllers\Controller;
use App\Models\Business\Product;
use App\Models\Business\Requirement;
use App\Notifications\SocialNotification;
use App\Support\BooleanQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The other direction: a buyer says what they need.
 *
 * Posted once, matched against every live page's keywords and products,
 * and each matching supplier is told. Suppliers answer with quotes; the
 * buyer compares them on the requirement itself.
 */
class RequirementController extends Controller
{
    use SerializesBusiness;

    private function rules(): array
    {
        return [
            'kind' => ['nullable', Rule::in(Requirement::KINDS)],
            'hs_code' => ['nullable', 'string', 'max:14', 'regex:/^[\d .]*$/'],
            'frequency' => ['nullable', Rule::in(Requirement::FREQUENCIES)],
            'origin_countries' => ['nullable', 'array', 'max:20'],
            'origin_countries.*' => ['string', 'size:2'],
            'target_markets' => ['nullable', 'array', 'max:20'],
            'target_markets.*' => ['string', 'size:2'],
            'payment_terms' => ['nullable', 'string', 'max:80'],
            'partner_type' => ['nullable', Rule::in(Requirement::PARTNER_TYPES)],
            'title' => ['required', 'string', 'min:4', 'max:160'],
            'description' => ['required', 'string', 'min:10', 'max:4000'],
            'category' => ['nullable', Rule::in(\App\Support\TradeCategories::LIST)],
            'keywords' => ['required', 'array', 'min:1', 'max:20'],
            'keywords.*' => ['string', 'max:40'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'quantity_unit' => ['nullable', 'string', 'max:24'],
            'target_price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'terms' => ['nullable', 'array'],
            'terms.*' => [Rule::in(Product::TERMS)],
            'destination_country' => ['nullable', 'string', 'size:2'],
            'destination_port' => ['nullable', 'string', 'max:80'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }

    /** Open requirements, newest first; `q` searches, `mine` narrows to yours. */
    public function index(Request $request): JsonResponse
    {
        $me = $request->user();
        $q = trim((string) $request->query('q'));
        $mine = $request->boolean('mine');

        $myPage = $me->businessPage()->value('business_pages.id');
        // "Mine" is the page's as much as the person's; the GrapOut team with no page picked sees every page's.
        $everything = $mine && ! $myPage && $me->isStaff();
        $rows = Requirement::with(['buyer.profile', 'page'])
            ->when($mine && ! $everything, fn ($b) => $b->where(fn ($w) => $w->where('user_id', $me->id)->when($myPage, fn ($x) => $x->orWhere('page_id', $myPage)))->orderByDesc('created_at'))
            ->when($everything, fn ($b) => $b->whereNotNull('page_id')->orderByDesc('created_at'))
            ->when(! $mine, fn ($b) => $b->live()->orderByDesc('created_at'))
            ->when(mb_strlen($q) >= 2, fn ($b) => BooleanQuery::apply($b, BooleanQuery::parse($q), fn (Builder $w, string $t) => $this->requirementMatches($w, $t)))
            ->when($request->query('country'), fn ($b, $cc) => $b->where('destination_country', strtoupper($cc)))
            ->when($request->query('kind'), fn ($b, $k) => $b->where('kind', $k))
            ->when($request->query('hs'), fn ($b, $hs) => $b->where('hs_code', 'like', preg_replace('/\D/', '', $hs) . '%'))
            ->paginate(20);

        $rows->getCollection()->transform(fn (Requirement $r) => $this->requirementRow($r, $me));

        return response()->json($rows);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();
        $r = Requirement::with(['buyer.profile', 'quotes.page', 'quotes.supplier'])->where('uuid', $uuid)->firstOrFail();
        $row = $this->requirementRow($r, $me);

        // The buyer sees every quote, laid out to compare. A supplier sees
        // their own. Anybody else sees how many there are.
        $mine = $r->user_id === $me->id;
        $myPage = $me->businessPage;
        $quotes = $r->quotes
            ->filter(fn ($qt) => $mine || ($myPage && $qt->page_id === $myPage->id))
            ->sortBy([['status', 'asc'], ['price', 'asc']])
            ->values()
            ->map(fn ($qt) => $this->quoteRow($qt, $me));
        $row['quotes'] = $quotes;
        $row['can_quote'] = ! $mine && $myPage !== null && $r->status === 'open';

        return response()->json(['data' => $row]);
    }

    public function store(Request $request): JsonResponse
    {
        $me = $request->user();
        $data = $request->validate($this->rules());
        $data['keywords'] = array_values(array_unique(array_filter(array_map(fn ($k) => trim(mb_strtolower($k)), $data['keywords']))));
        $data['currency'] = strtoupper($data['currency'] ?? 'USD');
        $data['destination_country'] = isset($data['destination_country']) ? strtoupper($data['destination_country']) : null;
        $data = $this->normalise($data);

        $r = Requirement::create($data + ['user_id' => $me->id, 'kind' => $data['kind'] ?? 'buy', 'page_id' => $me->businessPage()->value('business_pages.id')]);

        // Tell the suppliers it fits.
        $pages = $r->matchingPages();
        foreach ($pages as $page) {
            $page->notifyTeam(new SocialNotification(
                'business_requirement',
                "{$me->name} is looking for: {$r->title}" . ($r->destination_country ? " ({$r->destination_country})" : '') . '. Send a quote.',
                ['requirement_uuid' => $r->uuid],
                '/requirements/' . $r->uuid,
                'business-requirement-' . $r->uuid,
            ));
        }
        $r->update(['matched_count' => $pages->count()]);

        return response()->json([
            'message' => $pages->count() > 0
                ? "Posted. {$pages->count()} matching supplier" . ($pages->count() === 1 ? '' : 's') . ' have been told.'
                : 'Posted. Suppliers will find it in Requirements.',
            'data' => $this->requirementRow($r->fresh('buyer.profile'), $me),
        ], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();
        $r = Requirement::with('page')->where('uuid', $uuid)->firstOrFail();
        abort_unless($r->user_id === $me->id || ($r->page && $r->page->canManage($me)) || $me->isStaff(), 403);
        $data = $request->validate(array_map(fn ($rules) => array_merge(['sometimes'], $rules), $this->rules()) + [
            'status' => ['sometimes', Rule::in(Requirement::STATUSES)],
        ]);
        if (isset($data['keywords'])) {
            $data['keywords'] = array_values(array_unique(array_filter(array_map(fn ($k) => trim(mb_strtolower($k)), $data['keywords']))));
        }
        $r->update($this->normalise($data));

        return response()->json(['message' => 'Saved.', 'data' => $this->requirementRow($r->fresh('buyer.profile'), $me)]);
    }

    /** Codes as digits, countries upper-case. */
    private function normalise(array $data): array
    {
        if (array_key_exists('hs_code', $data)) {
            $data['hs_code'] = \App\Models\Business\TradeLine::normalizeCode($data['hs_code']);
        }
        foreach (['origin_countries', 'target_markets'] as $k) {
            if (isset($data[$k])) {
                $data[$k] = array_values(array_unique(array_map('strtoupper', $data[$k])));
            }
        }

        return $data;
    }

    private function requirementMatches(Builder $w, string $term): void
    {
        $like = '%' . mb_strtolower($term) . '%';
        $digits = preg_replace('/\D/', '', $term);
        $w->when(strlen($digits) >= 2 && $digits === $term, fn ($q) => $q->orWhere('hs_code', 'like', $digits . '%'))
            ->orWhereRaw('LOWER(title) LIKE ?', [$like])
            ->orWhereRaw('LOWER(description) LIKE ?', [$like])
            ->orWhereRaw('LOWER(keywords) LIKE ?', [$like])
            ->orWhereRaw('LOWER(category) LIKE ?', [$like])
            ->orWhereRaw('LOWER(destination_port) LIKE ?', [$like]);
    }
}
