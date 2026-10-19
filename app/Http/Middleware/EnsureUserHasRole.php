<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * `role:admin,owner` — allow the request through only when the authenticated
 * user has one of the listed roles.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->user();

        if ($user === null || ! $user->hasRole(...$roles)) {
            abort(403, 'This action is unauthorized.');
        }

        return $next($request);
    }
}
