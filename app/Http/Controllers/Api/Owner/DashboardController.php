<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use App\Http\Resources\JoinRequestResource;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\PlanRequestResource;
use App\Http\Resources\UserResource;
use App\Services\Owner\OwnerDashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(private readonly OwnerDashboardService $dashboard)
    {
    }

    /**
     * Platform-wide figures for the owner's home page: account and
     * organization totals, subscriptions by status, the ones expiring soon,
     * and the latest organizations, users and pending join requests.
     */
    public function summary(): JsonResponse
    {
        $summary = $this->dashboard->summary();

        return response()->json([
            'data' => $summary + [
                'as_of' => $this->dashboard->today(),
                'recent_organizations' => OrganizationResource::collection($this->dashboard->recentOrganizations())->resolve(),
                'recent_users' => UserResource::collection($this->dashboard->recentUsers())->resolve(),
                'pending_join_requests' => JoinRequestResource::collection($this->dashboard->pendingJoinRequests())->resolve(),
                'pending_plan_requests' => PlanRequestResource::collection($this->dashboard->pendingPlanRequests())->resolve(),
            ],
        ]);
    }
}
