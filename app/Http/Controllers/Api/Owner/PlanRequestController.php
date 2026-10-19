<?php

namespace App\Http\Controllers\Api\Owner;

use App\Enums\PlanRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\ApprovePlanRequestRequest;
use App\Http\Resources\PlanRequestResource;
use App\Models\PlanRequest;
use App\Services\PlanRequestService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The owner decides the schools' plan requests.
 */
class PlanRequestController extends Controller
{
    public function __construct(private readonly PlanRequestService $planRequests)
    {
    }

    /**
     * Every organization's requests (`?status=`, `?organization_id=`), pending first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(PlanRequestStatus::values())],
            'organization_id' => ['nullable', 'integer'],
        ]);

        return PlanRequestResource::collection(
            $this->planRequests->listFor(
                isset($filters['organization_id']) ? (int) $filters['organization_id'] : null,
                $filters['status'] ?? null
            )
        );
    }

    /**
     * Approve: record the subscription period (plan change) or cancel the current one.
     */
    public function approve(ApprovePlanRequestRequest $request, PlanRequest $planRequest): PlanRequestResource
    {
        return new PlanRequestResource(
            $this->planRequests->approve($planRequest, $request->user(), $request->validated())
        );
    }

    /**
     * Decline, with an optional note shown to the school.
     */
    public function decline(Request $request, PlanRequest $planRequest): PlanRequestResource
    {
        $data = $request->validate([
            'decision_note' => ['nullable', 'string', 'max:255'],
        ]);

        return new PlanRequestResource(
            $this->planRequests->decline($planRequest, $request->user(), $data['decision_note'] ?? null)
        );
    }
}
