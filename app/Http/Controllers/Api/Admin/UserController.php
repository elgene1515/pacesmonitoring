<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Role;
use App\Http\Controllers\Api\Concerns\ResolvesOrganizationScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MoveUserBranchRequest;
use App\Http\Requests\Admin\ResetUserPasswordRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Branch;
use App\Models\User;
use App\Services\Admin\AdminUserService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Member accounts of the organization in scope (every account, for the owner).
 */
class UserController extends Controller
{
    use ResolvesOrganizationScope;

    public function __construct(private readonly AdminUserService $users)
    {
    }

    /**
     * The members in scope (filters: search, role, branch_id).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return UserResource::collection(
            $this->users->list($request->only(['search', 'role', 'branch_id']), $this->scopeOrganizationId($request))
        );
    }

    /**
     * A single member's details (the password hash is never exposed).
     */
    public function show(Request $request, User $user): UserResource
    {
        $this->assertUserInScope($request, $user);

        return new UserResource($user->load(['branch', 'organization']));
    }

    /**
     * Update a member's name and email. Requires the admin's own password.
     */
    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $this->assertUserInScope($request, $user);

        return new UserResource($this->users->update($user, $request->userData()));
    }

    /**
     * Reset a member's password without their current one. Requires the admin's own password.
     */
    public function updatePassword(ResetUserPasswordRequest $request, User $user): Response
    {
        $this->assertUserInScope($request, $user);

        $this->users->resetPassword($user, $request->validated('password'));

        return response()->noContent();
    }

    /**
     * Move a member to another branch of their organization.
     */
    public function updateBranch(MoveUserBranchRequest $request, User $user): UserResource
    {
        $this->assertUserInScope($request, $user);

        $branch = Branch::query()->findOrFail((int) $request->validated('branch_id'));

        return new UserResource($this->users->moveToBranch($user, $branch, $request->user()));
    }

    /**
     * Make a member a school administrator or a teacher (`role`), within the
     * plan's admin limit; never your own role, never the last administrator.
     */
    public function updateRole(Request $request, User $user): UserResource
    {
        $this->assertUserInScope($request, $user);

        $data = $request->validate([
            'role' => ['required', 'string', Rule::in([Role::Admin->value, Role::Teacher->value])],
        ]);

        return new UserResource($this->users->changeRole($user, Role::from($data['role']), $request->user()));
    }

    /**
     * Remove a teacher from the organization: their teaching assignments end
     * and their booklets return to the pools; the students and records stay
     * with the school.
     */
    public function destroyMembership(Request $request, User $user): Response
    {
        $this->assertUserInScope($request, $user);

        if ((int) $user->id === (int) $request->user()->id) {
            abort(422, 'You cannot remove yourself.');
        }

        $this->users->removeFromOrganization($user, $request->user());

        return response()->noContent();
    }
}
