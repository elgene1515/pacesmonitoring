<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\JoinRequestStatus;
use App\Http\Controllers\Api\Concerns\ResolvesOrganizationScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\JoinRequest\DeclineJoinRequestRequest;
use App\Http\Resources\JoinRequestResource;
use App\Models\JoinRequest;
use App\Services\JoinRequestService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The admin's side of admitting teachers.
 */
class JoinRequestController extends Controller
{
    use ResolvesOrganizationScope;

    public function __construct(private readonly JoinRequestService $joinRequests)
    {
    }

    /**
     * The organization's join requests, pending first (`?status=` narrows).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(JoinRequestStatus::values())],
        ]);

        return JoinRequestResource::collection(
            $this->joinRequests->listFor($this->scopeOrganizationId($request), $filters['status'] ?? null)
        );
    }

    /**
     * Admit the teacher into the requested branch.
     */
    public function accept(Request $request, JoinRequest $joinRequest): JoinRequestResource
    {
        $this->assertInScope($request, $joinRequest->organization_id);

        return new JoinRequestResource($this->joinRequests->accept($joinRequest, $request->user()));
    }

    /**
     * Turn the request down (with an optional reason shown to the teacher).
     */
    public function decline(DeclineJoinRequestRequest $request, JoinRequest $joinRequest): JoinRequestResource
    {
        $this->assertInScope($request, $joinRequest->organization_id);

        return new JoinRequestResource(
            $this->joinRequests->decline($joinRequest, $request->user(), $request->validated('reason'))
        );
    }
}
