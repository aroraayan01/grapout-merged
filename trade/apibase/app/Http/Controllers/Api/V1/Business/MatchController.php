<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\V1\Business\Concerns\SerializesBusiness;
use App\Http\Controllers\Controller;
use App\Models\Business\Requirement;
use App\Services\TradeMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Matches: who should talk to whom, and why.
 */
class MatchController extends Controller
{
    use SerializesBusiness;

    /**
     * For my company: opportunities to answer, buyers to approach, suppliers to try.
     *
     * Without a company page there is still something to match on: the
     * opportunities this person posted. A buyer who only ever said "I am
     * looking for stainless door handles" gets the suppliers that fit.
     */
    public function mine(Request $request, TradeMatcher $matcher): JsonResponse
    {
        $me = $request->user();
        $page = $me->businessPage;

        $pageCard = fn ($p, $v) => $this->pageCard($p, $v);
        $personCard = fn ($u, $v, $withPage) => $this->personCard($u, $v, $withPage);

        if (! $page) {
            $mine = Requirement::live()->whereNull('page_id')->where('user_id', $me->id)->get();
            $buyers = collect();
            $suppliers = collect();
            foreach ($mine as $r) {
                $found = $matcher->companiesForIntent($r);
                if (($r->kind ?? 'buy') === 'sell') {
                    $buyers = $buyers->merge($found);
                } else {
                    $suppliers = $suppliers->merge($found);
                }
            }
            // The same company can fit two of my opportunities; it is listed once, by its best fit.
            $once = fn ($c) => $c->sortByDesc(fn ($m) => array_sum(array_column($m['reasons'], 'weight')))->unique(fn ($m) => $m['page']->id)->values();

            return response()->json(['data' => [
                'opportunities' => [],
                'buyers' => $once($buyers)->map(fn ($m) => $matcher->serializeCompany($m, $me, $pageCard, $personCard))->values(),
                'suppliers' => $once($suppliers)->map(fn ($m) => $matcher->serializeCompany($m, $me, $pageCard, $personCard))->values(),
                'has_lines' => false,
                'has_page' => false,
                'has_intents' => $mine->isNotEmpty(),
            ]]);
        }

        $intents = $matcher->intentsForPage($page)->map(fn ($m) => [
            'intent' => $this->requirementRow($m['intent'], $me),
            'reasons' => $m['reasons'],
            'person' => $m['person'] && $m['person']->user ? [
                'member_id' => $m['person']->id, 'function' => $m['person']->function,
                'function_label' => $m['person']->function ? (\App\Models\Business\CompanyMember::FUNCTION_LABELS[$m['person']->function] ?? ucfirst($m['person']->function)) : null,
                'title' => $m['person']->title, 'user' => $this->personCard($m['person']->user, $me, false),
            ] : null,
        ]);
        $companies = $matcher->companiesForPage($page);

        return response()->json(['data' => [
            'opportunities' => $intents->values(),
            'buyers' => $companies['buyers']->map(fn ($m) => $matcher->serializeCompany($m, $me, $pageCard, $personCard))->values(),
            'suppliers' => $companies['suppliers']->map(fn ($m) => $matcher->serializeCompany($m, $me, $pageCard, $personCard))->values(),
            'has_lines' => $page->tradeLines()->exists(),
            'has_page' => true,
            'has_intents' => Requirement::live()->where('page_id', $page->id)->exists(),
        ]]);
    }

    /** For one opportunity: the companies that fit it. The poster's view. */
    public function forIntent(Request $request, TradeMatcher $matcher, string $uuid): JsonResponse
    {
        $me = $request->user();
        $r = Requirement::with('page')->where('uuid', $uuid)->firstOrFail();
        $mine = $r->user_id === $me->id || ($r->page && $r->page->isMember($me));
        abort_unless($mine, 403, 'Only the poster sees who fits their opportunity.');

        $pageCard = fn ($p, $v) => $this->pageCard($p, $v);
        $personCard = fn ($u, $v, $withPage) => $this->personCard($u, $v, $withPage);

        return response()->json(['data' => $matcher->companiesForIntent($r)->map(fn ($m) => $matcher->serializeCompany($m, $me, $pageCard, $personCard))->values()]);
    }
}
