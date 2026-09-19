<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Api\V1\Admin\ImpersonationController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What a borrowed account may not do.
 *
 * An admin signed in as a member sees and does what the member does,
 * with a short list of exceptions: nothing that keeps the account
 * (passwords, sessions, the email), nothing that spends the member's
 * money, and no borrowing from inside a borrowed seat.
 */
class ImpersonationScope
{
    protected const FORBIDDEN = [
        'api/v1/auth/change-password',
        'api/v1/auth/sessions*',
        'api/v1/auth/email/*',
        'api/v1/auth/mobile/*',
        'api/v1/subscription/checkout',
        'api/v1/subscription/cancel',
        'api/v1/payments/*/verify',
        'api/v1/admin/users/*/impersonate',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        $token = $bearer ? \Laravel\Sanctum\PersonalAccessToken::findToken($bearer) : null;

        if (! ImpersonationController::isBorrowed($token)) {
            return $next($request);
        }

        if ($request->is(...self::FORBIDDEN)) {
            abort(403, 'That cannot be done while signed in as somebody else.');
        }

        // Deleting the account is a DELETE on /me: the method is refused, not the path.
        if ($request->is('api/v1/me') && $request->isMethod('delete')) {
            abort(403, 'That cannot be done while signed in as somebody else.');
        }

        return $next($request);
    }
}
