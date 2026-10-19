<?php

namespace App\Http\Controllers\Api\Owner;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\UpdateUserRoleRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Admin\AdminUserService;

class UserController extends Controller
{
    public function __construct(private readonly AdminUserService $users)
    {
    }

    /**
     * Switch a member between the admin and teacher roles of their organization.
     */
    public function updateRole(UpdateUserRoleRequest $request, User $user): UserResource
    {
        return new UserResource($this->users->changeRole($user, Role::from($request->validated('role'))));
    }
}
