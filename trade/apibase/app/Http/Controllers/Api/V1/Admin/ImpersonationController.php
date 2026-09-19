<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Login as": an admin steps into a member's account to see what they
 * see — their company page, enquiries, pipeline, everything — and steps
 * back out. The borrowed session is a token of its own, marked as such,
 * so the server knows whose hand is on it and keeps it away from the
 * things only the owner should do (passwords, money, other sessions).
 */
class ImpersonationController extends Controller
{
    public function start(Request $request, User $user): JsonResponse
    {
        $me = $request->user();
        abort_if($user->id === $me->id, 422, 'That is you.');
        abort_if($user->isSuperAdmin(), 403, 'Admins cannot sign in as other admins.');
        abort_if($user->status !== 'active', 422, 'That account is not active.');
        abort_if(self::isBorrowed($me->currentAccessToken()), 403, 'Return to your own account before signing in as somebody else.');

        $token = $user->createToken('impersonation:' . $me->id, ['impersonate', 'impersonation-by:' . $me->id])->plainTextToken;
        AuditLog::record($me, 'admin.impersonation.start', $user);

        return response()->json(['data' => [
            'token' => $token,
            'user' => new UserResource($user->load(['profile', 'settings', 'appId', 'roles'])),
            'impersonation' => ['level' => 'account', 'name' => $user->name],
        ]]);
    }

    /** Handing the account back: the borrowed token dies. */
    public function stop(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        if ($token && self::isBorrowed($token)) {
            $by = self::adminIdOf($token);
            AuditLog::record(User::find($by) ?? $request->user(), 'admin.impersonation.stop', $request->user());
            $token->delete();
        }

        return response()->json(['message' => 'Back in your own account.']);
    }

    public static function isBorrowed(?object $token): bool
    {
        return in_array('impersonate', self::abilitiesOf($token), true);
    }

    public static function adminIdOf(?object $token): ?int
    {
        foreach (self::abilitiesOf($token) as $ability) {
            if (str_starts_with((string) $ability, 'impersonation-by:')) {
                return (int) substr((string) $ability, strlen('impersonation-by:'));
            }
        }

        return null;
    }

    private static function abilitiesOf(?object $token): array
    {
        $abilities = $token?->abilities ?? [];

        return is_array($abilities) ? $abilities : [];
    }
}
