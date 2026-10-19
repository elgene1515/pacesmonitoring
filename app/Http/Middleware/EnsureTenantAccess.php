<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * `tenant` — the request must come from a member of an organization, and
 * changes (anything but GET/HEAD) require that organization's subscription to
 * be in good standing. A lapsed organization keeps read access so nothing is
 * lost; it just cannot add or edit records until the owner renews it. An
 * organization the owner has deactivated (soft-deleted) is closed entirely.
 *
 * Platform staff (owner, vice admins) are not tenants and pass through untouched.
 */
class EnsureTenantAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user === null) {
            abort(401, 'Unauthenticated.');
        }

        if ($user->isPlatform()) {
            return $next($request);
        }

        if (! $user->hasOrganization()) {
            return response()->json([
                'message' => 'You are not a member of an organization yet.',
                'code' => 'no_organization',
            ], 403);
        }

        // The relation excludes soft-deleted organizations, so null means deactivated.
        $organization = $user->organization;

        if ($organization === null) {
            return response()->json([
                'message' => sprintf('Your organization has been deactivated. Contact %s.', config('saas.support_email')),
                'code' => 'organization_inactive',
            ], 403);
        }

        if (! $request->isMethodSafe() && ! $organization->allowsWrites()) {
            return response()->json([
                'message' => sprintf(
                    "Your organization's subscription is not active, so records can be viewed but not changed. Contact %s to renew.",
                    config('saas.support_email')
                ),
                'code' => 'subscription_inactive',
            ], 403);
        }

        return $next($request);
    }
}
