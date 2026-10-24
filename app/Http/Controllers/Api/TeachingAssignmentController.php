<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TeachingAssignment\StoreTeachingAssignmentRequestRequest;
use App\Http\Resources\TeachingAssignmentRequestResource;
use App\Http\Resources\TeachingAssignmentResource;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TeachingAssignmentRequest;
use App\Services\TeachingAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * A teacher's teaching assignments and their requests for open slots.
 */
class TeachingAssignmentController extends Controller
{
    public function __construct(private readonly TeachingAssignmentService $teaching)
    {
    }

    /**
     * The section + subject pairs the teacher is in charge of.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return TeachingAssignmentResource::collection($this->teaching->listFor($request->user()));
    }

    /**
     * The teacher's requests, pending first.
     */
    public function requests(Request $request): AnonymousResourceCollection
    {
        return TeachingAssignmentRequestResource::collection($this->teaching->requestsFor($request->user()));
    }

    /**
     * Ask to teach a subject to a section of the teacher's branch.
     */
    public function storeRequest(StoreTeachingAssignmentRequestRequest $request): JsonResponse
    {
        $created = $this->teaching->request(
            $request->user(),
            Section::query()->findOrFail((int) $request->validated('section_id')),
            Subject::query()->findOrFail((int) $request->validated('subject_id')),
            $request->validated('message')
        );

        return (new TeachingAssignmentRequestResource($created))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Withdraw a pending request.
     */
    public function destroyRequest(Request $request, TeachingAssignmentRequest $teachingAssignmentRequest): TeachingAssignmentRequestResource
    {
        abort_unless((int) $teachingAssignmentRequest->user_id === (int) $request->user()->id, 404, 'Not Found.');

        return new TeachingAssignmentRequestResource($this->teaching->cancel($teachingAssignmentRequest));
    }
}
