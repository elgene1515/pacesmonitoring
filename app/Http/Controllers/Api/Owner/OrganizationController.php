<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\ConfirmPasswordRequest;
use App\Http\Requests\Owner\UpdateOrganizationRequest;
use App\Http\Resources\JoinRequestResource;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\PlanRequestResource;
use App\Http\Resources\UserResource;
use App\Models\Organization;
use App\Models\PaceAssignment;
use App\Services\JoinRequestService;
use App\Services\OrganizationService;
use App\Services\PlanRequestService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

/**
 * Every registered organization, for the platform staff.
 */
class OrganizationController extends Controller
{
    public function __construct(
        private readonly OrganizationService $organizations,
        private readonly JoinRequestService $joinRequests,
        private readonly PlanRequestService $planRequests,
    ) {
    }

    /**
     * Every organization with its current subscription and member counts
     * (filters: search by name/code, status = effective subscription status,
     * trashed=1 to include deactivated organizations).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', '');
        $trashed = $request->boolean('trashed');

        $organizations = Organization::query()
            ->when($trashed, fn (Builder $query) => $query->withTrashed())
            ->with(['currentSubscription.plan', 'creator'])
            ->withCount(['branches', 'teachers', 'admins', 'pendingJoinRequests', 'pendingPlanRequests', 'pendingPaceRequests'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.addcslashes($search, '\\%_').'%';

                $query->where(function (Builder $inner) use ($like): void {
                    $inner->where('name', 'like', $like)->orWhere('code', 'like', $like);
                });
            })
            ->orderBy('name')
            ->get();

        if ($status !== '') {
            $organizations = $organizations
                ->filter(fn (Organization $organization) => ($organization->currentSubscription?->effectiveStatus()->value ?? 'none') === $status)
                ->values();
        }

        return OrganizationResource::collection($organizations);
    }

    /**
     * One organization in full: details, branches, subscription history,
     * members, recent join requests, plan requests and record counts.
     */
    public function show(Organization $organization): JsonResource
    {
        $organization = $this->organizations->detail($organization)->load(['subscriptions.plan', 'creator']);

        $organization->setAttribute('students_count', $organization->studentsQuery()->count());
        $organization->setAttribute(
            'assignments_count',
            PaceAssignment::query()->whereIn('student_id', $organization->studentsQuery()->select('id'))->count()
        );

        $members = $organization->users()
            ->with('branch')
            ->orderByRaw("case when role = 'admin' then 0 else 1 end")
            ->orderBy('name')
            ->get();

        return (new OrganizationResource($organization))->additional([
            'members' => UserResource::collection($members),
            'join_requests' => JoinRequestResource::collection($this->joinRequests->listFor($organization)->take(20)),
            'plan_requests' => PlanRequestResource::collection($this->planRequests->listFor($organization)->take(20)),
        ]);
    }

    /**
     * Update an organization's name and contact details (owner password required).
     */
    public function update(UpdateOrganizationRequest $request, Organization $organization): OrganizationResource
    {
        return new OrganizationResource($this->organizations->update($organization, $request->organizationData()));
    }

    /**
     * Deactivate (soft delete) an organization. Owner only, password required.
     */
    public function destroy(ConfirmPasswordRequest $request, Organization $organization): Response
    {
        if ($organization->trashed()) {
            abort(422, 'This organization is already deactivated.');
        }

        $this->organizations->deactivate($organization);

        return response()->noContent();
    }

    /**
     * Restore a deactivated organization. Owner only.
     */
    public function restore(Organization $organization): OrganizationResource
    {
        if (! $organization->trashed()) {
            abort(422, 'This organization is not deactivated.');
        }

        return new OrganizationResource($this->organizations->restore($organization));
    }
}
