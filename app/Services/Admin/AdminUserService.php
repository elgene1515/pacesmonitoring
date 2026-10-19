<?php

namespace App\Services\Admin;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminUserService
{
    /**
     * The members in scope (teachers and admins of one organization, or every
     * user for the owner), newest first.
     *
     * Supported filters: search (matches name or email), role, branch_id.
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\User>
     */
    public function list(array $filters = [], ?int $organizationId = null): Collection
    {
        $query = TeacherScope::fromFilters(['branch_id' => $filters['branch_id'] ?? null], $organizationId)
            ->members()
            ->with(['branch', 'organization'])
            ->orderByDesc('created_at');

        $role = $filters['role'] ?? null;

        if (is_string($role) && in_array($role, Role::values(), true)) {
            $query->where('role', $role);
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';

            $query->where(function (Builder $inner) use ($like): void {
                $inner->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }

        return $query->get();
    }

    /**
     * Update a user's name and email.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, array $data): User
    {
        $user->fill($data);
        $user->save();

        return $user->load(['branch', 'organization']);
    }

    /**
     * Reset a user's password without knowing their current one.
     */
    public function resetPassword(User $user, string $password): void
    {
        $user->password = Hash::make($password);
        $user->save();
    }

    /**
     * Move a member to another branch of the same organization.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function moveToBranch(User $user, Branch $branch): User
    {
        if ((int) $branch->organization_id !== (int) $user->organization_id) {
            throw ValidationException::withMessages([
                'branch_id' => ['The branch must belong to the same organization.'],
            ]);
        }

        $user->branch_id = $branch->id;
        $user->save();

        return $user->load(['branch', 'organization']);
    }

    /**
     * Remove a teacher from their organization. Their records stay theirs and
     * become reachable again once they join an organization.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function removeFromOrganization(User $user): User
    {
        if (! $user->isTeacher()) {
            throw ValidationException::withMessages([
                'user' => ['Only teachers can be removed from an organization.'],
            ]);
        }

        $user->organization_id = null;
        $user->branch_id = null;
        $user->current_school_year_id = null;
        $user->save();

        return $user;
    }

    /**
     * Owner only: switch a member between the admin and teacher roles.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function changeRole(User $user, Role $role): User
    {
        if ($user->isPlatform() || $role->isPlatform()) {
            throw ValidationException::withMessages([
                'role' => ['Platform roles (CEO, admin) are managed from the Staff page.'],
            ]);
        }

        // Promoting to school admin is held to the plan's admin limit.
        if ($role === Role::Admin && ! $user->isAdmin() && $user->organization !== null) {
            $limit = $user->organization->currentSubscription()->with('plan')->first()?->plan?->max_admins;

            if ($limit !== null && $user->organization->admins()->count() >= $limit) {
                throw ValidationException::withMessages([
                    'role' => [sprintf(
                        "The school's plan allows up to %d %s.",
                        $limit,
                        $limit === 1 ? 'administrator' : 'administrators'
                    )],
                ]);
            }
        }

        $user->role = $role;
        $user->save();

        return $user->load(['branch', 'organization']);
    }
}
