<?php

namespace App\Http\Controllers\Api\V1\Grap;

use App\Http\Controllers\Controller;
use App\Models\Grap\Reveal;
use App\Services\GrapUp\Pipeline\CompanySearch;
use App\Services\GrapUp\Roles;
use App\Services\GrapUp\Serper;
use App\Services\GrapUp\Verifier\Verifier;
use App\Services\SubscriptionEntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Grap Company: name a company, get the people at it.
 *
 * Not a directory search. Grap Buyer and Grap Supplier read rows GrapOut's
 * research already gathered; this goes out to the web on demand, finds the
 * company's domain, sources its staff, works out its email convention and
 * pays a vendor to confirm the addresses.
 *
 * Which is why it is metered before it runs rather than after. Everything
 * downstream is careful about spending — a free DNS lookup ahead of every paid
 * check, a cache that never pays twice — but the one thing none of it can do
 * is refuse a search the plan does not cover. That decision is here.
 */
class GrapCompanyController extends Controller
{
    public function __construct(
        private SubscriptionEntitlementService $plans,
        private CompanySearch $search,
    ) {}

    /** The department labels the UI offers, so it does not hard-code them. */
    public function roles(): JsonResponse
    {
        return response()->json(['data' => Roles::known()]);
    }

    /**
     * Whether this is worth showing at all, and what it would cost.
     *
     * Asked before the form is drawn, so somebody with no allowance left is
     * told so rather than finding out by pressing the button.
     */
    public function status(Request $request): JsonResponse
    {
        $me = $request->user();

        return response()->json(['data' => [
            'enabled' => $this->plans->hasFeature($me, 'grap_leads'),
            'configured' => app(Serper::class)->isConfigured() && app(Verifier::class)->isConfigured(),
            /*
             * Which tiers are live, e.g. "inboxx -> clearout". Worth
             * surfacing: a free tier silently switched off is the difference
             * between a cheap month and an expensive one, and nothing else
             * would show it.
             */
            'verifier' => app(Verifier::class)->describe(),
            'contacts_left_today' => $this->left($me, 'grap_reveals_per_day', $this->plans->grapRevealsToday($me)),
            'contacts_left_month' => $this->left($me, 'grap_reveals_per_month', $this->plans->grapRevealsThisMonth($me)),
            'roles' => Roles::known(),
        ]]);
    }

    public function search(Request $request): JsonResponse
    {
        $me = $request->user();

        $data = $request->validate([
            'company_name' => ['required', 'string', 'min:2', 'max:191'],
            'country' => ['nullable', 'string', 'max:96'],
            'target_role' => ['nullable', 'string', 'max:96'],
            // Pay for a fresh answer rather than reading the cached one.
            'refresh' => ['nullable', 'boolean'],
            // Cap how many people to return. Empty or 0 = the whole team.
            'max_contacts' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        abort_if(! $this->plans->hasFeature($me, 'grap_leads'), 403, 'Hot Leads is not on your plan.');

        /*
         * Checked before the search, not after.
         *
         * A search that finds five people and then discovers the allowance was
         * spent has already paid the vendor for all five — the money is gone
         * whether or not the answer is shown. Refusing up front is the only
         * point at which refusing is free.
         */
        abort_if(
            ! $this->plans->canRevealGrap($me),
            429,
            'That is your contacts for now. The daily allowance resets at midnight.',
        );

        abort_if(
            ! app(Serper::class)->isConfigured() || ! app(Verifier::class)->isConfigured(),
            503,
            'Grap Company is not configured yet — it needs a search key and a verification key.',
        );

        $result = $this->search->run(
            companyName: $data['company_name'],
            country: $data['country'] ?? '',
            targetRole: $data['target_role'] ?? '',
            user: $me,
            refresh: (bool) ($data['refresh'] ?? false),
            maxContacts: isset($data['max_contacts']) ? (int) $data['max_contacts'] : null,
        );

        /*
         * Charge for what was delivered, not for what was asked.
         *
         * One search can return five usable addresses or none, and the vendor
         * bill differs by more than an order of magnitude between them. The
         * person's allowance counts contacts, so that is what is recorded:
         * one receipt per address they can actually write to.
         *
         * A cached answer costs nothing and is recorded as nothing. It has
         * already been paid for, by whoever asked first.
         */
        if (! ($result['cached'] ?? false)) {
            $this->recordUnlocked($me, $result);
            $this->plans->forget($me);
        }

        return response()->json([
            'data' => $result,
            'allowance' => [
                'contacts_left_today' => $this->left($me, 'grap_reveals_per_day', $this->plans->grapRevealsToday($me)),
                'contacts_left_month' => $this->left($me, 'grap_reveals_per_month', $this->plans->grapRevealsThisMonth($me)),
            ],
        ]);
    }

    /** One receipt per address the person can actually write to. */
    private function recordUnlocked($user, array $result): void
    {
        $company = $result['company']['name'] ?? null;

        foreach ($result['contacts'] ?? [] as $contact) {
            // `locked` and `unknown` carry no address; nobody should be
            // charged for a person the walk could not answer for.
            if (($contact['email'] ?? null) === null) {
                continue;
            }

            Reveal::firstOrCreate(
                ['user_id' => $user->id, 'source' => Reveal::SOURCE_COMPANY, 'email' => $contact['email']],
                ['company_name' => $company, 'channels' => 'email', 'credits' => 1],
            );
        }
    }

    private function left($user, string $key, int $used): ?int
    {
        $limit = $this->plans->grapLimit($user, $key);

        return $limit === null ? null : max(0, $limit - $used);
    }
}
