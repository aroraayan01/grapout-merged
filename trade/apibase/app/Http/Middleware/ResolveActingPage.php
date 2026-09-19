<?php

namespace App\Http\Middleware;

use App\Models\Business\Page;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;

/**
 * The admin's page picker. A staff member's request that names a page
 * (X-Acting-Page) is served as that page's owner for everything under
 * Trade. Resolved once here, kept on the User model for the request,
 * and cleared at the start of the next one.
 */
class ResolveActingPage
{
    public function handle(Request $request, Closure $next)
    {
        User::$acting = null;
        $uuid = trim((string) $request->header('X-Acting-Page'));
        if ($uuid !== '') {
            $user = $request->user();
            if ($user instanceof User && $user->isStaff() && ($id = Page::where('uuid', $uuid)->value('id'))) {
                User::$acting = ['page_id' => (int) $id, 'user_id' => $user->id];
            }
        }

        return $next($request);
    }
}
