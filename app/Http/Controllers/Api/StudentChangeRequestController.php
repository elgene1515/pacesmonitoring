<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StudentChangeRequestResource;
use App\Models\StudentChangeRequest;
use App\Services\StudentChangeRequestService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A teacher's own proposals to change a student's details.
 */
class StudentChangeRequestController extends Controller
{
    public function __construct(private readonly StudentChangeRequestService $changes)
    {
    }

    /**
     * The teacher's proposals, pending first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return StudentChangeRequestResource::collection($this->changes->listFor($request->user()));
    }

    /**
     * Withdraw a pending proposal.
     */
    public function destroy(Request $request, StudentChangeRequest $studentChangeRequest): StudentChangeRequestResource
    {
        abort_unless((int) $studentChangeRequest->requested_by === (int) $request->user()->id, 404, 'Not Found.');

        return new StudentChangeRequestResource($this->changes->cancel($studentChangeRequest));
    }
}
