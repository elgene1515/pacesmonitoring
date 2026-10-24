<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\JoinRequest\StoreJoinRequestRequest;
use App\Http\Resources\JoinRequestResource;
use App\Models\Branch;
use App\Models\JoinRequest;
use App\Services\JoinRequestService;
use App\Services\OrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * The joining side: a teacher (or an admin-type account that wants to join a
 * school already registered, as an administrator) asks, waits, or withdraws.
 */
class JoinRequestController extends Controller
{
    public function __construct(
        private readonly JoinRequestService $joinRequests,
        private readonly OrganizationService $organizations,
    ) {
    }

    /**
     * Ask to join an organization's branch, identified by the organization's
     * public code and the branch id from the lookup.
     */
    public function store(StoreJoinRequestRequest $request): JsonResponse
    {
        $organization = $this->organizations->lookup($request->validated('organization_code'));

        if ($organization === null) {
            throw ValidationException::withMessages([
                'organization_code' => ['No organization matches that code. Check it with your school administrator.'],
            ]);
        }

        $branch = Branch::query()->findOrFail((int) $request->validated('branch_id'));

        // Only an admin-type account may ask for the administrator role; teachers join as teachers.
        $wanted = $request->user()->isAdmin() && $request->validated('role') === Role::Admin->value ? Role::Admin : Role::Teacher;

        $joinRequest = $this->joinRequests->submit(
            $request->user(),
            $organization,
            $branch,
            $request->validated('message'),
            $wanted
        );

        return (new JoinRequestResource($joinRequest))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Withdraw one's own pending request.
     */
    public function destroy(Request $request, JoinRequest $joinRequest): Response
    {
        if ((int) $joinRequest->user_id !== (int) $request->user()->id) {
            abort(404, 'Not Found.');
        }

        $this->joinRequests->cancel($joinRequest);

        return response()->noContent();
    }
}
