<?php

namespace App\Http\Controllers\Api\V1\Grap;

use App\Http\Controllers\Controller;
use App\Models\Grap\Lead;
use App\Models\Grap\Reveal;
use App\Models\Grap\SearchLog;
use App\Services\SubscriptionEntitlementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Hot Leads: GrapOut's own buyers and suppliers.
 *
 * Search is one endpoint for both kinds, because they are one table and
 * one screen with a different word at the top. It returns three things
 * together — the page of results, the facet counts beside them, and what
 * is left of the day's allowance — since a screen that has to ask three
 * times to draw itself once shows its results in three flickers.
 */
class GrapController extends Controller
{
    public function __construct(private SubscriptionEntitlementService $plans) {}

    /** The old site's three choices, kept. */
    private const PER_PAGE = [20, 40, 60];

    /**
     * A page of leads, its facets, and the allowance.
     *
     * The old site ran this as six ORed LIKEs against one text box and
     * counted the whole result set with a second identical query whose
     * only difference was `select id`. Here the count comes from the
     * paginator, so the database reads the rows once.
     */
    public function search(Request $request): JsonResponse
    {
        $me = $request->user();
        $data = $this->validated($request);

        abort_if(! $this->plans->hasFeature($me, 'grap_leads'), 403, 'Hot Leads is not on your plan.');

        /*
         * Opening the screen is not a search.
         *
         * With no text and no facet this is the unfiltered list — what the
         * tab shows before anybody has asked anything. Charging for it means
         * a Free plan with ten searches a day is locked out by opening Hot
         * Leads ten times, or by switching between the buyer and supplier
         * tabs five times, having learned nothing. The contacts are what the
         * plan is for; looking at the shape of the data is not.
         */
        $isSearch = filled($data['q']) || $data['facets'] !== [];

        /*
         * A search already asked today — its page 2, a refresh, the back
         * button — is the same search and is neither charged nor refused.
         * Identified by signature rather than by page number, because
         * exempting `page=2` outright would make it a way to read the
         * database for nothing.
         */
        $signature = SearchLog::signature($data['kind'], $data['q'], $data['facets']);
        $asked = $isSearch && SearchLog::where('user_id', $me->id)
            ->where('signature', $signature)
            ->where('created_at', '>=', now()->startOfDay())
            ->exists();

        abort_if($isSearch && ! $asked && ! $this->plans->canSearchGrap($me), 429, "That is today's searches used up. The allowance resets at midnight.");

        $base = fn () => Lead::query()->where('kind', $data['kind'])->reachable()->matching($data['q'] ?? null);

        $rows = $base()->faceted($data['facets'])->bestFirst()
            ->paginate($data['per_page'], ['*'], 'page', $data['page']);

        // Logged after the query, with the real hit count, so an allowance is
        // never spent on a search that errored.
        if ($isSearch && ! $asked) {
            SearchLog::create([
                'user_id' => $me->id,
                'kind' => $data['kind'],
                'q' => $data['q'] ?? null,
                'filters' => $data['facets'] ?: null,
                'signature' => $signature,
                'hits' => $rows->total(),
            ]);
        }

        $revealed = $this->revealedIds($request, $rows->getCollection()->pluck('id')->all());

        return response()->json([
            'data' => $rows->getCollection()->map(fn (Lead $l) => $l->forViewer($me, isset($revealed[$l->id]))),
            // `current_page` rather than `page`: this is the shape the shared
            // <Pager> reads, and a bespoke one here would mean a bespoke pager.
            'meta' => [
                'current_page' => $rows->currentPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
                'last_page' => $rows->lastPage(),
            ],
            'facets' => $this->facets($base, $data['facets']),
            'allowance' => $this->allowanceFor($request),
        ]);
    }

    /**
     * Facet counts, each computed with every filter but its own.
     *
     * Counting a facet against a query that already includes that facet's
     * own selection is the classic mistake: tick "India" and every other
     * country reads zero, so the list you are choosing from disappears the
     * moment you use it. The old site sidestepped this by not counting at
     * all on the buyer screen — `ajaxbyrleftpaneldropdown.php` is 26 lines
     * and returns nothing.
     *
     * @param  callable(): Builder  $base
     * @param  array<string, list<string>>  $chosen
     */
    private function facets(callable $base, array $chosen): array
    {
        $out = [];
        foreach (Lead::FACETS as $key => $column) {
            $others = $chosen;
            unset($others[$key]);

            $out[$key] = $base()->faceted($others)
                ->whereNotNull($column)->where($column, '<>', '')
                ->selectRaw("{$column} as value, COUNT(*) as total")
                ->groupBy($column)
                ->orderByDesc('total')
                ->limit(25)
                ->get()
                ->map(fn ($r) => [
                    'value' => $r->value,
                    'total' => (int) $r->total,
                    'chosen' => in_array($r->value, $chosen[$key] ?? [], true),
                ]);
        }

        return $out;
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();
        $lead = Lead::where('uuid', $uuid)->firstOrFail();
        $revealed = Reveal::where('user_id', $me->id)->where('grap_lead_id', $lead->id)->exists();

        return response()->json(['data' => $lead->forViewer($me, $revealed)]);
    }

    /**
     * Unlock a contact.
     *
     * Already unlocked costs nothing and is not refused when the allowance
     * is gone — you are re-reading something you have already bought.
     */
    public function reveal(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();
        $lead = Lead::where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'channels' => ['nullable', Rule::in(['email', 'phone', 'both'])],
        ]);

        $already = Reveal::where('user_id', $me->id)->where('grap_lead_id', $lead->id)->first();
        if (! $already) {
            abort_if(! $this->plans->hasFeature($me, 'grap_leads'), 403, 'Hot Leads is not on your plan.');
            abort_if(! $this->plans->canRevealGrap($me), 429, 'That is your contacts for now. The daily allowance resets at midnight.');

            Reveal::create([
                'user_id' => $me->id,
                'grap_lead_id' => $lead->id,
                'channels' => $data['channels'] ?? 'both',
            ]);
            $this->plans->forget($me);
        }

        return response()->json([
            'data' => $lead->forViewer($me, true),
            'allowance' => $this->allowanceFor($request),
        ]);
    }

    /** What is left, for the strip at the top of the screen. */
    public function allowance(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->allowanceFor($request)]);
    }

    private function allowanceFor(Request $request): array
    {
        $me = $request->user();
        $plan = $this->plans->planFor($me);

        $left = function (?int $limit, int $used): ?int {
            return $limit === null ? null : max(0, $limit - $used);
        };

        return [
            'plan' => $plan->name,
            'enabled' => $this->plans->hasFeature($me, 'grap_leads'),
            'can_export' => $this->plans->hasFeature($me, 'grap_export'),
            // Through grapLimit, not the plan: an admin's grant lifts them all.
            'searches_left_today' => $left($this->plans->grapLimit($me, 'grap_searches_per_day'), $this->plans->grapSearchesToday($me)),
            'reveals_left_today' => $left($this->plans->grapLimit($me, 'grap_reveals_per_day'), $this->plans->grapRevealsToday($me)),
            'reveals_left_month' => $left($this->plans->grapLimit($me, 'grap_reveals_per_month'), $this->plans->grapRevealsThisMonth($me)),
        ];
    }

    /** @return array<int, true> lead id => true */
    private function revealedIds(Request $request, array $leadIds): array
    {
        if ($leadIds === []) {
            return [];
        }

        return Reveal::where('user_id', $request->user()->id)
            ->whereIn('grap_lead_id', $leadIds)
            ->pluck('grap_lead_id')
            ->flip()->map(fn () => true)->all();
    }

    /**
     * @return array{kind: string, q: ?string, page: int, per_page: int, facets: array<string, list<string>>}
     */
    private function validated(Request $request): array
    {
        $rules = [
            'kind' => ['required', Rule::in(Lead::KINDS)],
            'q' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1', 'max:500'],
            'per_page' => ['nullable', Rule::in(self::PER_PAGE)],
        ];
        foreach (array_keys(Lead::FACETS) as $key) {
            $rules[$key] = ['nullable', 'array', 'max:20'];
            $rules["{$key}.*"] = ['string', 'max:255'];
        }
        $data = $request->validate($rules);

        $facets = [];
        foreach (array_keys(Lead::FACETS) as $key) {
            if (! empty($data[$key])) {
                $facets[$key] = array_values(array_unique($data[$key]));
            }
        }

        return [
            'kind' => $data['kind'],
            'q' => $data['q'] ?? null,
            'page' => (int) ($data['page'] ?? 1),
            'per_page' => (int) ($data['per_page'] ?? self::PER_PAGE[0]),
            'facets' => $facets,
        ];
    }
}
