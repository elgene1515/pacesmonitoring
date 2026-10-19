<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\PaceRequestStatus;
use App\Http\Controllers\Api\Concerns\ResolvesOrganizationScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GivePaceRequestRequest;
use App\Http\Requests\Admin\UpdatePaceRequestRequest;
use App\Http\Resources\PaceRequestResource;
use App\Models\PaceRequest;
use App\Services\Admin\TeacherScope;
use App\Services\PaceRequestService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The school admin's side of the teachers' PACE stock requests: see them,
 * adjust them, hand the booklets over or decline.
 */
class PaceRequestController extends Controller
{
    use ResolvesOrganizationScope;

    public function __construct(private readonly PaceRequestService $paceRequests)
    {
    }

    /**
     * The organization's requests, pending first (`?status=`, `?teacher_id=`, `?branch_id=` narrow;
     * `?search=` matches the teacher or the PACE; `?needed_from=` / `?needed_to=` bound the needed-by date).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(PaceRequestStatus::values())],
            'teacher_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'needed_from' => ['nullable', 'date_format:Y-m-d'],
            'needed_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return PaceRequestResource::collection(
            $this->paceRequests->listFor(
                TeacherScope::fromFilters($filters, $this->scopeOrganizationId($request)),
                $filters['status'] ?? null,
                $filters
            )
        );
    }

    /**
     * Adjust a pending request (PACE, quantity, needed-by date).
     */
    public function update(UpdatePaceRequestRequest $request, PaceRequest $paceRequest): PaceRequestResource
    {
        $this->assertInScope($request, $paceRequest->organization_id);

        return new PaceRequestResource($this->paceRequests->update($paceRequest, $request->requestData()));
    }

    /**
     * The booklets were handed over: close the request and top up the linked PACE's stock.
     */
    public function give(GivePaceRequestRequest $request, PaceRequest $paceRequest): PaceRequestResource
    {
        $this->assertInScope($request, $paceRequest->organization_id);

        return new PaceRequestResource(
            $this->paceRequests->give($paceRequest, $request->user(), $request->validated())
        );
    }

    /**
     * Turn the request down, with an optional note shown to the teacher.
     */
    public function decline(Request $request, PaceRequest $paceRequest): PaceRequestResource
    {
        $this->assertInScope($request, $paceRequest->organization_id);

        $data = $request->validate([
            'decision_note' => ['nullable', 'string', 'max:255'],
        ]);

        return new PaceRequestResource(
            $this->paceRequests->decline($paceRequest, $request->user(), $data['decision_note'] ?? null)
        );
    }
}
