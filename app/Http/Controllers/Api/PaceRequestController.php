<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaceRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaceRequest\StorePaceRequestRequest;
use App\Http\Requests\PaceRequest\UpdatePaceRequestRequest;
use App\Http\Resources\PaceRequestResource;
use App\Models\PaceRequest;
use App\Services\PaceRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * The teacher's side of asking the school admin for PACE booklets.
 */
class PaceRequestController extends Controller
{
    public function __construct(private readonly PaceRequestService $paceRequests)
    {
    }

    /**
     * The signed-in teacher's requests, pending first (`?status=` narrows).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(PaceRequestStatus::values())],
        ]);

        return PaceRequestResource::collection(
            $this->paceRequests->listForTeacher($request->user(), $filters['status'] ?? null)
        );
    }

    /**
     * Ask for booklets of one of the teacher's PACEs, or of a PACE named by number and name.
     */
    public function store(StorePaceRequestRequest $request): JsonResponse
    {
        $paceRequest = $this->paceRequests->submit($request->user(), $request->requestData());

        return (new PaceRequestResource($paceRequest))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Correct one's own pending request.
     */
    public function update(UpdatePaceRequestRequest $request, PaceRequest $paceRequest): PaceRequestResource
    {
        $this->assertOwn($request, $paceRequest);

        return new PaceRequestResource($this->paceRequests->update($paceRequest, $request->requestData()));
    }

    /**
     * Withdraw one's own pending request.
     */
    public function destroy(Request $request, PaceRequest $paceRequest): Response
    {
        $this->assertOwn($request, $paceRequest);

        $this->paceRequests->cancel($paceRequest);

        return response()->noContent();
    }

    /**
     * Another teacher's request answers 404, so ids cannot be guessed.
     */
    protected function assertOwn(Request $request, PaceRequest $paceRequest): void
    {
        if ((int) $paceRequest->user_id !== (int) $request->user()->id) {
            abort(404, 'Not Found.');
        }
    }
}
