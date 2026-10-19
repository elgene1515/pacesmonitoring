<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\ConfirmPasswordRequest;
use App\Http\Resources\UnassignedUserResource;
use App\Models\User;
use App\Services\Owner\UnassignedUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Accounts that belong to no organization: review them and delete the strays.
 */
class UnassignedUserController extends Controller
{
    public function __construct(private readonly UnassignedUserService $unassigned)
    {
    }

    /**
     * The accounts without an organization, longest without a school first
     * (`?search=`, `?role=`, `?from=` and `?to=` narrow by name / email, by role and by the day the account was created).
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', Rule::in(UnassignedUserService::roles())],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $result = $this->unassigned->list($filters);

        return response()->json([
            'data' => UnassignedUserResource::collection($result['users'])->resolve(),
            'meta' => [
                'total' => $result['total'],
                'overall' => $result['overall'],
                'limit' => UnassignedUserService::LIMIT,
            ],
        ]);
    }

    /**
     * Delete an account that has no organization (and owns no records). Password required.
     */
    public function destroy(ConfirmPasswordRequest $request, User $user): Response
    {
        $this->unassigned->delete($user);

        return response()->noContent();
    }
}
