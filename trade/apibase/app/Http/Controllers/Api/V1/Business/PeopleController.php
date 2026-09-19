<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\V1\Business\Concerns\SerializesBusiness;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AppIdService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Finding people by what they do, not only what they are called.
 *
 * "import handicraft" should find the person whose headline says "Importer
 * of brass & handicrafts", and their page beside them. The search reads a
 * name, a username, the professional fields on a profile, a business page
 * and the products on it — and understands the shape a searcher actually
 * types: several words that must all match, "a quoted phrase", NOT this,
 * this OR that.
 */
class PeopleController extends Controller
{
    use SerializesBusiness;

    public function search(Request $request, AppIdService $appIds): JsonResponse
    {
        $me = $request->user();
        $q = trim((string) $request->query('q'));
        abort_if(mb_strlen($q) < 2, 422, 'Type at least two characters.');

        $alternatives = \App\Support\BooleanQuery::parse($q);

        $query = User::with(['profile', 'settings', 'appId'])
            ->where('status', 'active')
            ->whereKeyNot($me->id)
            ->where(function (Builder $outer) use ($alternatives) {
                foreach ($alternatives as $group) {
                    $outer->orWhere(function (Builder $alt) use ($group) {
                        foreach ($group['must'] as $term) {
                            $alt->where(fn (Builder $w) => $this->matches($w, $term));
                        }
                        foreach ($group['not'] as $term) {
                            $alt->whereNot(fn (Builder $w) => $this->matches($w, $term));
                        }
                    });
                }
            })
            ->when($request->query('country'), fn ($b, $cc) => $b->whereHas('profile', fn ($p) => $p->where('country', $cc)))
            ->orderBy('name')
            ->limit(90)
            ->get()
            // The same door as looking somebody up by App ID: who_can_find_me
            // is theirs to set, and a keyword search must not walk past it.
            ->filter(fn (User $u) => $appIds->findVisibleUser($u->username ?: ($u->appId?->app_id ?? ''), $me) !== null)
            ->take(30)
            ->values()
            ->map(fn (User $u) => $this->personCard($u, $me));

        return response()->json(['data' => $query, 'q' => $q]);
    }

    /**
     * One search term against everything a person can be found by: their
     * name, handle or exact email; their headline and company; their page's
     * name, kind, keywords, trade lines and products.
     */
    private function matches(Builder $w, string $term): void
    {
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($term)) . '%';
        // "merchant exporter" and "importer" find pages of that kind.
        $kindKey = str_replace([' ', '-'], '_', mb_strtolower(trim($term)));
        $kind = in_array($kindKey, \App\Models\Business\Page::KINDS, true) ? $kindKey : null;

        $w->whereRaw('LOWER(users.name) LIKE ?', [$like])
            ->orWhereRaw('LOWER(users.username) LIKE ?', [$like])
            // An address is matched whole: knowing it already is the key, guessing it is not.
            ->orWhereRaw('LOWER(users.email) = ?', [mb_strtolower(trim($term))])
            ->when($kind, fn ($q) => $q->orWhereHas('businessPage', fn ($p) => $p->where('business_pages.status', 'active')->where('business_pages.kind', $kind)))
            ->orWhereHas('profile', fn ($p) => $p->where(fn ($pp) => $pp
                ->whereRaw('LOWER(headline) LIKE ?', [$like])
                ->orWhereRaw('LOWER(designation) LIKE ?', [$like])
                ->orWhereRaw('LOWER(company_name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(industry) LIKE ?', [$like])
                ->orWhereRaw('LOWER(keywords) LIKE ?', [$like])))
            ->orWhereHas('businessPage', fn ($p) => $p->where('business_pages.status', 'active')->where(fn ($pp) => $pp
                ->whereRaw('LOWER(name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(tagline) LIKE ?', [$like])
                ->orWhereRaw('LOWER(keywords) LIKE ?', [$like])
                ->orWhereRaw('LOWER(city) LIKE ?', [$like])
                ->orWhereHas('tradeLines', fn ($t) => $t->where('hs_code', 'like', preg_replace('/\D/', '', $term) ?: '~')->orWhereRaw('LOWER(description) LIKE ?', [$like]))))
            ->orWhereHas('businessPage.products', fn ($x) => $x->where('business_products.status', 'active')->where(fn ($xx) => $xx
                ->whereRaw('LOWER(business_products.name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(business_products.keywords) LIKE ?', [$like])
                ->orWhereRaw('LOWER(business_products.category) LIKE ?', [$like])));
    }
}
