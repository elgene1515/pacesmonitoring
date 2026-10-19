<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assignment\StoreAssignmentRequest;
use App\Http\Resources\PaceAssignmentResource;
use App\Models\Student;
use App\Services\PaceAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class StudentAssignmentController extends Controller
{
    public function __construct(private readonly PaceAssignmentService $assignments)
    {
    }

    /**
     * A student's PACE records (filters: school_year, quarter, subject_id).
     */
    public function index(Request $request, Student $student): AnonymousResourceCollection
    {
        return PaceAssignmentResource::collection(
            $this->assignments->forStudent(
                $student,
                $request->only(['school_year', 'quarter', 'subject_id'])
            )
        );
    }

    /**
     * Assign one or more PACEs to the student under a subject, school year and quarter.
     */
    public function store(StoreAssignmentRequest $request, Student $student): JsonResponse
    {
        $created = $this->assignments->assignMany($student, $request->assignmentData());

        return PaceAssignmentResource::collection($created)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
