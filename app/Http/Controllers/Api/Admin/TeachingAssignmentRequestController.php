<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\TeachingAssignmentRequestStatus;
use App\Http\Controllers\Api\Concerns\ResolvesOrganizationScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeclineRequestRequest;
use App\Http\Resources\TeachingAssignmentRequestResource;
use App\Http\Resources\TeachingAssignmentResource;
use App\Models\TeachingAssignmentRequest;
use App\Services\TeachingAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Teachers' requests for open section + subject slots, decided by the admin.
 */
class TeachingAssignmentRequestController extends Controller
{
    use ResolvesOrganizationScope;

    public function __construct(private readonly TeachingAssignmentService $teaching)
    {
    }

    /**
     * The requests in scope, pending first (`?status=`, `?branch_id=`).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $organizationId = $this->scopeOrganizationId($request);

        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(TeachingAssignmentRequestStatus::values())],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
        ]);

        return TeachingAssignmentRequestResource::collection(
            $this->teaching->requestsForOrganization($organizationId, $filters)
        );
    }

    /**
     * Accept: the teacher gets the slot.
     */
    public function accept(Request $request, TeachingAssignmentRequest $teachingAssignmentRequest): JsonResponse
    {
        $this->assertInScope($request, $teachingAssignmentRequest->organization_id);

        // Always 200: the slot is set, whether the row was created or replaced.
        return (new TeachingAssignmentResource($this->teaching->accept($teachingAssignmentRequest, $request->user())))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /**
     * Decline, with an optional reason.
     */
    public function decline(DeclineRequestRequest $request, TeachingAssignmentRequest $teachingAssignmentRequest): TeachingAssignmentRequestResource
    {
        $this->assertInScope($request, $teachingAssignmentRequest->organization_id);

        return new TeachingAssignmentRequestResource(
            $this->teaching->decline($teachingAssignmentRequest, $request->user(), $request->reason())
        );
    }
}
