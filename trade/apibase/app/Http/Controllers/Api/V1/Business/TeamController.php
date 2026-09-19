<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\V1\Business\Concerns\SerializesBusiness;
use App\Http\Controllers\Controller;
use App\Mail\TeamInvite;
use App\Models\Business\CompanyMember;
use App\Models\Business\Page;
use App\Models\User;
use App\Notifications\SocialNotification;
use App\Services\AppIdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The people on a company page.
 *
 * The owner and admins run the page and the team; representatives act
 * for the company — answer enquiries, quote, list products — without
 * touching its settings. A person belongs to one company at a time.
 *
 * Two doors in: the company invites (a member, or an email for somebody
 * not here yet), or a person asks to join and an admin approves.
 */
class TeamController extends Controller
{
    use SerializesBusiness;

    private function managed(Request $request): Page
    {
        $page = $request->user()->businessPage()->first();
        abort_unless($page && $page->canManage($request->user()), 403, 'Only the owner or an admin can change the team.');

        return $page;
    }

    public function index(Request $request): JsonResponse
    {
        $page = $request->user()->businessPage()->first();
        abort_unless($page, 404, 'You are not on a company page yet.');

        return response()->json(['data' => $this->teamRows($page, $request->user()), 'my_role' => $page->roleOf($request->user())]);
    }

    /** Invite by username, App ID, email of a member, or an outside email. */
    public function invite(Request $request, AppIdService $appIds): JsonResponse
    {
        $me = $request->user();
        $page = $this->managed($request);
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'role' => ['nullable', Rule::in(['admin', 'representative'])],
            'function' => ['nullable', Rule::in(CompanyMember::FUNCTIONS)],
            'title' => ['nullable', 'string', 'max:120'],
        ]);
        $role = $data['role'] ?? 'representative';

        $user = $appIds->findVisibleUser($data['identifier'], $me)
            ?? (filter_var($data['identifier'], FILTER_VALIDATE_EMAIL) ? User::where('email', mb_strtolower($data['identifier']))->first() : null);

        if ($user) {
            abort_if($user->id === $me->id, 422, 'That is you.');
            $elsewhere = CompanyMember::where('user_id', $user->id)->where('status', 'active')->first();
            abort_if($elsewhere && $elsewhere->page_id !== $page->id, 422, "{$user->name} already represents another company. They would have to leave it first.");
            abort_if($elsewhere, 422, "{$user->name} is already on the team.");

            $member = CompanyMember::updateOrCreate(
                ['page_id' => $page->id, 'user_id' => $user->id],
                ['role' => $role, 'function' => $data['function'] ?? null, 'title' => $data['title'] ?? null, 'status' => 'invited', 'invited_by' => $me->id, 'invite_token' => Str::random(64)],
            );
            $user->notify(new SocialNotification(
                'company_invite',
                "{$me->name} invited you to join the team of {$page->name}.",
                ['page_slug' => $page->slug, 'member_id' => $member->id],
                '/business?tab=team',
                'company-invite-' . $member->id,
            ));

            return response()->json(['message' => "Invitation sent to {$user->name}.", 'data' => $this->memberRow($member, $me)], 201);
        }

        abort_unless(filter_var($data['identifier'], FILTER_VALIDATE_EMAIL), 422, 'Nobody by that name here. Use an email address to invite them.');
        $email = mb_strtolower($data['identifier']);
        $member = CompanyMember::updateOrCreate(
            ['page_id' => $page->id, 'invited_email' => $email, 'user_id' => null],
            ['role' => $role, 'function' => $data['function'] ?? null, 'title' => $data['title'] ?? null, 'status' => 'invited', 'invited_by' => $me->id, 'invite_token' => Str::random(64)],
        );
        Mail::to($email)->send(new TeamInvite($member));

        return response()->json(['message' => "Invitation emailed to {$email}.", 'data' => $this->memberRow($member, $me)], 201);
    }

    /** The invited person says yes — in the app, or arriving from the email. */
    public function accept(Request $request): JsonResponse
    {
        $me = $request->user();
        abort_if($me->isStaff(), 422, 'Admin accounts do not run a company page. To see a member\'s company, sign in as them from Admin.');
        $data = $request->validate(['token' => ['nullable', 'string', 'size:64'], 'member_id' => ['nullable', 'integer']]);
        $member = ! empty($data['token'])
            ? CompanyMember::where('invite_token', $data['token'])->where('status', 'invited')->first()
            : CompanyMember::whereKey($data['member_id'] ?? 0)->where('user_id', $me->id)->where('status', 'invited')->first();
        abort_unless($member, 404, 'This invitation is no longer open.');
        abort_if($member->user_id && $member->user_id !== $me->id, 403, 'This invitation was for somebody else.');
        abort_if($member->invited_email && ! $member->user_id && mb_strtolower((string) $me->email) !== $member->invited_email, 403, 'This invitation was sent to a different email address.');

        $elsewhere = CompanyMember::where('user_id', $me->id)->where('status', 'active')->where('id', '!=', $member->id)->first();
        abort_if($elsewhere, 422, 'You already represent another company. Leave it first.');

        $member->update(['user_id' => $me->id, 'status' => 'active', 'joined_at' => now(), 'invite_token' => null]);
        $member->page->notifyTeam(new SocialNotification('company_team', "{$me->name} joined the team of {$member->page->name}.", ['page_slug' => $member->page->slug], '/business?tab=team', 'company-team-' . $member->id), except: $me);

        return response()->json(['message' => "You are on the team of {$member->page->name}.", 'data' => $this->memberRow($member->fresh(), $me)]);
    }

    /** What the invitation link opens, before signing in. */
    public function peek(string $token): JsonResponse
    {
        $m = CompanyMember::where('invite_token', $token)->where('status', 'invited')->with(['page', 'inviter'])->firstOrFail();

        return response()->json(['data' => [
            'company' => ['slug' => $m->page->slug, 'name' => $m->page->name, 'logo_path' => $m->page->logo_path, 'country' => $m->page->country],
            'inviter' => $m->inviter?->name,
            'email' => $m->invited_email,
            'role' => $m->role,
            'function' => $m->function,
        ]]);
    }

    /** "I work here" — from a company page, awaiting an admin. */
    public function requestToJoin(Request $request, string $slug): JsonResponse
    {
        $me = $request->user();
        abort_if($me->isStaff(), 422, 'Admin accounts do not run a company page. To see a member\'s company, sign in as them from Admin.');
        $page = Page::live()->where('slug', $slug)->firstOrFail();
        abort_if(CompanyMember::where('user_id', $me->id)->where('status', 'active')->exists(), 422, 'You already represent a company. Leave it first.');
        $data = $request->validate(['function' => ['nullable', Rule::in(CompanyMember::FUNCTIONS)], 'title' => ['nullable', 'string', 'max:120']]);

        $member = CompanyMember::updateOrCreate(
            ['page_id' => $page->id, 'user_id' => $me->id],
            ['role' => 'representative', 'function' => $data['function'] ?? null, 'title' => $data['title'] ?? null, 'status' => 'requested'],
        );
        $page->notifyTeam(new SocialNotification('company_team', "{$me->name} asked to join the team of {$page->name}.", ['page_slug' => $page->slug, 'member_id' => $member->id], '/business?tab=team', 'company-request-' . $member->id), managersOnly: true);

        return response()->json(['message' => "Asked. {$page->name} will approve you.", 'data' => $this->memberRow($member, $me)], 201);
    }

    /** Approve a request, change a role or function. */
    public function update(Request $request, int $id): JsonResponse
    {
        $me = $request->user();
        $page = $this->managed($request);
        $member = CompanyMember::where('page_id', $page->id)->findOrFail($id);
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['active'])],
            'role' => ['nullable', Rule::in(['admin', 'representative'])],
            'function' => ['nullable', Rule::in(CompanyMember::FUNCTIONS)],
            'title' => ['nullable', 'string', 'max:120'],
        ]);
        abort_if($member->role === 'owner' && isset($data['role']), 422, 'The owner stays the owner. Transfer ownership from Page details.');

        if (($data['status'] ?? null) === 'active' && $member->status === 'requested') {
            $member->update(['status' => 'active', 'joined_at' => now()]);
            $member->user?->notify(new SocialNotification('company_team', "You are now on the team of {$page->name}.", ['page_slug' => $page->slug], '/business', 'company-team-' . $member->id));
            // Colleagues are contacts: the owner and the newcomer are connected, no request to accept.
            if ($member->user_id && $page->user_id && $member->user_id !== $page->user_id) {
                $pair = \App\Models\Connection::where(fn ($q) => $q->where('requester_id', $page->user_id)->where('addressee_id', $member->user_id))
                    ->orWhere(fn ($q) => $q->where('requester_id', $member->user_id)->where('addressee_id', $page->user_id))->first();
                if ($pair) {
                    $pair->update(['status' => 'accepted', 'responded_at' => now()]);
                } else {
                    \App\Models\Connection::create(['requester_id' => $page->user_id, 'addressee_id' => $member->user_id, 'status' => 'accepted', 'responded_at' => now(), 'message' => "Team of {$page->name}"]);
                }
            }
        }
        $member->update(array_filter(['role' => $data['role'] ?? null, 'function' => $data['function'] ?? null, 'title' => $data['title'] ?? null], fn ($v) => $v !== null));

        return response()->json(['message' => 'Saved.', 'data' => $this->memberRow($member->fresh(), $me)]);
    }

    /** Remove somebody, decline a request, withdraw an invitation, or leave. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $me = $request->user();
        $member = CompanyMember::with('page')->findOrFail($id);
        $leaving = $member->user_id === $me->id;
        abort_unless($leaving || $member->page->canManage($me), 403);
        abort_if($member->role === 'owner', 422, 'The owner cannot be removed. Transfer ownership first, or close the page.');
        $member->delete();

        return response()->json(['message' => $leaving ? "You left {$member->page->name}." : 'Removed.']);
    }

    /** Hand the page to another admin or representative. */
    public function transfer(Request $request): JsonResponse
    {
        $me = $request->user();
        $page = $request->user()->businessPage()->first();
        abort_unless($page && $page->roleOf($me) === 'owner', 403, 'Only the owner can hand the page over.');
        $to = CompanyMember::where('page_id', $page->id)->where('status', 'active')->findOrFail($request->validate(['member_id' => ['required', 'integer']])['member_id']);
        abort_if($to->user_id === $me->id, 422, 'That is you.');

        CompanyMember::where('page_id', $page->id)->where('user_id', $me->id)->update(['role' => 'admin']);
        $to->update(['role' => 'owner']);
        $page->update(['user_id' => $to->user_id]);
        $to->user?->notify(new SocialNotification('company_team', "{$me->name} made you the owner of {$page->name}.", ['page_slug' => $page->slug], '/business?tab=team', 'company-owner-' . $to->id));

        return response()->json(['message' => "{$to->user?->name} now owns {$page->name}."]);
    }
}
