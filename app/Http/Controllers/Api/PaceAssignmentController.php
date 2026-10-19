<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assignment\UpdateAssignmentRequest;
use App\Http\Resources\PaceAssignmentResource;
use App\Models\PaceAssignment;
use App\Services\PaceAssignmentService;
use Illuminate\Http\Response;

class PaceAssignmentController extends Controller
{
    public function __construct(private readonly PaceAssignmentService $assignments)
    {
    }

    /**
     * Edit an assignment: pace, subject, period, issued/tested dates, score, remarks.
     */
    public function update(UpdateAssignmentRequest $request, PaceAssignment $assignment): PaceAssignmentResource
    {
        return new PaceAssignmentResource(
            $this->assignments->update($assignment, $request->assignmentData())
        );
    }

    /**
     * Remove an assignment record.
     */
    public function destroy(PaceAssignment $assignment): Response
    {
        $this->assignments->delete($assignment);

        return response()->noContent();
    }
}
