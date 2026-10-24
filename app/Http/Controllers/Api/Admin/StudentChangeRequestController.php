<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\StudentChangeRequestStatus;
use App\Http\Controllers\Api\Concerns\ResolvesOrganizationScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeclineRequestRequest;
use App\Http\Resources\StudentChangeRequestResource;
use App\Models\StudentChangeRequest;
use App\Services\StudentChangeRequestService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Teachers' proposals to change a student's details, decided by the admin.
 */
class StudentChangeRequestController extends Controller
{
    use ResolvesOrganizationScope;

    public function __construct(private readonly StudentChangeRequestService $changes)
    {
    }

    /**
     * The proposals in scope, pending first (`?status=`).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $organizationId = $this->scopeOrganizationId($request);

        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(StudentChangeRequestStatus::values())],
        ]);

        return StudentChangeRequestResource::collection($this->changes->listForOrganization($organizationId, $filters));
    }

    /**
     * Accept: the change is applied to the student.
     */
    public function accept(Request $request, StudentChangeRequest $studentChangeRequest): StudentChangeRequestResource
    {
        $this->assertInScope($request, $studentChangeRequest->organization_id);

        return new StudentChangeRequestResource($this->changes->accept($studentChangeRequest, $request->user()));
    }

    /**
     * Decline, with an optional reason.
     */
    public function decline(DeclineRequestRequest $request, StudentChangeRequest $studentChangeRequest): StudentChangeRequestResource
    {
        $this->assertInScope($request, $studentChangeRequest->organization_id);

        return new StudentChangeRequestResource(
            $this->changes->decline($studentChangeRequest, $request->user(), $request->reason())
        );
    }
}
