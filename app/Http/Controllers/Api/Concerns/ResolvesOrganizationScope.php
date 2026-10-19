<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Which organization an admin-side request works on.
 *
 * An organization admin is always pinned to their own organization. The
 * owner may pass `?organization_id=` to look at one organization, or leave it
 * out to see every organization at once (null scope).
 */
trait ResolvesOrganizationScope
{
    /**
     * The organization id in scope, or null for "every organization" (owner only).
     */
    protected function scopeOrganizationId(Request $request): ?int
    {
        $user = $request->user();

        if (! $user->isPlatform()) {
            // 0 matches nothing, so an admin without an organization never sees other data.
            return (int) ($user->organization_id ?? 0);
        }

        $filters = $request->validate([
            'organization_id' => ['nullable', 'integer', Rule::exists('organizations', 'id')],
        ]);

        return isset($filters['organization_id']) ? (int) $filters['organization_id'] : null;
    }

    /**
     * The organization in scope as a model; 404 when the owner has not picked one.
     */
    protected function scopeOrganization(Request $request): Organization
    {
        $id = $this->scopeOrganizationId($request);

        if ($id === null) {
            abort(404, 'Select an organization first.');
        }

        // Deactivated (soft-deleted) organizations stay reachable for platform staff.
        return Organization::withTrashed()->findOrFail($id);
    }

    /**
     * Abort with 404 unless the record's organization is the one in scope.
     */
    protected function assertInScope(Request $request, ?int $organizationId): void
    {
        $scope = $this->scopeOrganizationId($request);

        if ($scope !== null && (int) $organizationId !== $scope) {
            abort(404, 'Not Found.');
        }
    }

    /**
     * Abort with 404 unless the user is a member of the organization in scope.
     */
    protected function assertUserInScope(Request $request, User $user): void
    {
        $viewer = $request->user();

        if ($viewer->isPlatform()) {
            $this->assertInScope($request, $user->organization_id);

            return;
        }

        if ($user->organization_id === null || (int) $user->organization_id !== (int) $viewer->organization_id) {
            abort(404, 'Not Found.');
        }
    }
}
