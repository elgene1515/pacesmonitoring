<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ResolvesOrganizationScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\PlanRequest\StorePlanRequestRequest;
use App\Http\Resources\PlanRequestResource;
use App\Models\Plan;
use App\Models\PlanRequest;
use App\Services\PlanRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The school's side of changing its plan: ask, see the answer, withdraw.
 */
class PlanRequestController extends Controller
{
    use ResolvesOrganizationScope;

    public function __construct(private readonly PlanRequestService $planRequests)
    {
    }

    /**
     * The organization's plan requests, pending first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return PlanRequestResource::collection(
            $this->planRequests->listFor($this->scopeOrganizationId($request))
        );
    }

    /**
     * Ask to change plan or to cancel.
     */
    public function store(StorePlanRequestRequest $request): JsonResponse
    {
        $organization = $this->scopeOrganization($request);
        $plan = $request->filled('plan_id') ? Plan::query()->find((int) $request->validated('plan_id')) : null;

        $planRequest = $this->planRequests->submit(
            $organization,
            $request->user(),
            $request->validated('action'),
            $plan,
            $request->validated('message')
        );

        return (new PlanRequestResource($planRequest))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Withdraw the pending request.
     */
    public function destroy(Request $request, PlanRequest $planRequest): Response
    {
        $this->assertInScope($request, $planRequest->organization_id);

        $this->planRequests->cancel($planRequest);

        return response()->noContent();
    }
}
