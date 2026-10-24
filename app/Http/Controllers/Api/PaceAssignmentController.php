<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assignment\UpdateAssignmentRequest;
use App\Http\Resources\PaceAssignmentResource;
use App\Models\PaceAssignment;
use App\Services\PaceAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A single PACE record. Route binding only resolves records whose subject
 * the teacher teaches in the student's section.
 */
class PaceAssignmentController extends Controller
{
    public function __construct(private readonly PaceAssignmentService $assignments)
    {
    }

    /**
     * Edit a record: pace, subject, period, issued/tested dates, score, remarks.
     */
    public function update(UpdateAssignmentRequest $request, PaceAssignment $assignment): PaceAssignmentResource
    {
        return new PaceAssignmentResource(
            $this->assignments->update($assignment, $request->assignmentData(), $request->user())
        );
    }

    /**
     * Remove a record (its booklet goes back to the teacher when it was issued).
     */
    public function destroy(Request $request, PaceAssignment $assignment): Response
    {
        $this->assignments->delete($assignment, $request->user());

        return response()->noContent();
    }
}
