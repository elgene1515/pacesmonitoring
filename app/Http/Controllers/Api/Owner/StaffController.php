<?php

namespace App\Http\Controllers\Api\Owner;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\StoreStaffRequest;
use App\Http\Requests\Owner\UpdateStaffRoleRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Platform staff accounts (owners and vice admins). Owner only.
 */
class StaffController extends Controller
{
    /**
     * Every platform account, owners first.
     */
    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection(
            User::query()->platform()->orderByRaw("case when role = 'owner' then 0 else 1 end")->orderBy('name')->get()
        );
    }

    /**
     * Add a platform account.
     */
    public function store(StoreStaffRequest $request): JsonResponse
    {
        $user = new User([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => Hash::make($request->validated('password')),
        ]);
        $user->role = Role::from($request->validated('role'));
        $user->save();

        return (new UserResource($user))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Switch a staff account between owner and vice admin. The last owner
     * cannot be demoted.
     */
    public function updateRole(UpdateStaffRoleRequest $request, User $user): UserResource
    {
        $this->assertStaff($user);

        $role = Role::from($request->validated('role'));

        if ($user->isOwner() && $role !== Role::Owner && User::query()->role(Role::Owner)->count() <= 1) {
            throw ValidationException::withMessages([
                'role' => ['There has to be at least one owner.'],
            ]);
        }

        $user->role = $role;
        $user->save();

        return new UserResource($user);
    }

    /**
     * Remove a staff account (not your own, never the last owner).
     */
    public function destroy(Request $request, User $user): Response
    {
        $this->assertStaff($user);

        if ((int) $user->id === (int) $request->user()->id) {
            throw ValidationException::withMessages([
                'user' => ['You cannot remove your own account.'],
            ]);
        }

        if ($user->isOwner() && User::query()->role(Role::Owner)->count() <= 1) {
            throw ValidationException::withMessages([
                'user' => ['There has to be at least one owner.'],
            ]);
        }

        $user->delete();

        return response()->noContent();
    }

    protected function assertStaff(User $user): void
    {
        if (! $user->isPlatform()) {
            abort(404, 'Not Found.');
        }
    }
}
