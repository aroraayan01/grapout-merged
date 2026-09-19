<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\V1\Business\Concerns\SerializesBusiness;
use App\Http\Controllers\Controller;
use App\Models\Business\CompanyMember;
use App\Models\Business\Page;
use App\Models\User;
use App\Notifications\SocialNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "This is my company."
 *
 * A seeded page waits for its people. Somebody whose verified email is on
 * the company's own domain gets it at once — the domain is the proof.
 * Anybody else asks, and a super admin decides with the two of them side
 * by side.
 */
class ClaimController extends Controller
{
    use SerializesBusiness;

    /** Mailboxes anyone can have: no proof of anything. */
    private const PUBLIC_MAIL = ['gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.in', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'icloud.com', 'me.com', 'protonmail.com', 'proton.me', 'rediffmail.com', 'yandex.com', 'mail.com', 'aol.com', 'gmx.com', 'zoho.com', 'qq.com', '163.com', '126.com'];

    public function claim(Request $request, string $slug): JsonResponse
    {
        $me = $request->user();
        $page = Page::live()->where('slug', $slug)->firstOrFail();
        abort_unless($page->isUnclaimed(), 422, 'This page is already run by its company. Ask to join its team instead.');
        abort_if(CompanyMember::where('user_id', $me->id)->where('status', 'active')->exists(), 422, 'You already represent a company. Leave it first.');
        abort_unless($me->email && $me->email_verified_at, 422, 'Verify your email address first — it is how a claim is checked.');
        $data = $request->validate([
            'function' => ['nullable', Rule::in(CompanyMember::FUNCTIONS)],
            'title' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($this->domainMatches($me, $page)) {
            $page->grantTo($me, $data['function'] ?? 'management', $data['title'] ?? null);

            return response()->json(['message' => "{$page->name} is yours. Your email is on the company's domain, so no wait.", 'data' => ['status' => 'claimed']]);
        }

        $claim = CompanyMember::updateOrCreate(
            ['page_id' => $page->id, 'user_id' => $me->id],
            ['role' => 'owner', 'status' => 'requested', 'is_claim' => true, 'function' => $data['function'] ?? null, 'title' => $data['title'] ?? null, 'note' => $data['note'] ?? null],
        );
        foreach (User::whereHas('roles', fn ($r) => $r->whereIn('slug', ['super_admin', 'admin']))->get() as $admin) {
            $admin->notify(new SocialNotification('company_claim', "{$me->name} claims {$page->name} ({$page->country}). Decide in Admin → Trade.", ['page_slug' => $page->slug, 'claim_id' => $claim->id], '/admin?tab=trade', 'company-claim-' . $claim->id));
        }

        return response()->json(['message' => 'Claim sent. The GrapOut team checks it and you will be told — usually within a day.', 'data' => ['status' => 'requested']], 201);
    }

    /** The claimant's verified email lives on the company's own domain. */
    private function domainMatches(User $user, Page $page): bool
    {
        $mine = strtolower(substr(strrchr((string) $user->email, '@') ?: '', 1));
        if ($mine === '' || in_array($mine, self::PUBLIC_MAIL, true)) {
            return false;
        }
        $theirs = array_filter([
            $page->website ? strtolower((string) parse_url(str_starts_with($page->website, 'http') ? $page->website : 'https://' . $page->website, PHP_URL_HOST)) : null,
            $page->email ? strtolower(substr(strrchr($page->email, '@') ?: '', 1)) : null,
        ]);
        foreach ($theirs as $d) {
            $d = preg_replace('/^www\./', '', $d);
            if ($d === '' || in_array($d, self::PUBLIC_MAIL, true)) {
                continue;
            }
            if ($mine === $d || str_ends_with($mine, '.' . $d) || str_ends_with($d, '.' . $mine)) {
                return true;
            }
        }

        return false;
    }
}
