<?php

namespace App\Services;

use App\Enums\JoinRequestStatus;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\JoinRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * People ask to join an organization's branch, as a teacher or (admin-type
 * accounts) as an administrator; that organization's admins accept, choosing
 * the role within the plan's limits, or decline. Accepting attaches the
 * person to the organization.
 */
class JoinRequestService
{
    /**
     * Submit a request. A teacher can have one pending request at a time and
     * cannot request to join while already a member somewhere.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function submit(User $teacher, Organization $organization, Branch $branch, ?string $message = null, Role $requestedRole = Role::Teacher): JoinRequest
    {
        if ($teacher->hasOrganization()) {
            throw ValidationException::withMessages([
                'organization_code' => ['You already belong to an organization.'],
            ]);
        }

        if ((int) $branch->organization_id !== (int) $organization->id) {
            throw ValidationException::withMessages([
                'branch_id' => ['The selected branch does not belong to that organization.'],
            ]);
        }

        if ($teacher->joinRequests()->pending()->exists()) {
            throw ValidationException::withMessages([
                'organization_code' => ['You already have a pending request. Cancel it before sending another one.'],
            ]);
        }

        $request = $teacher->joinRequests()->create([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'requested_role' => $requestedRole->isPlatform() ? Role::Teacher->value : $requestedRole->value,
            'status' => JoinRequestStatus::Pending,
            'message' => $message !== null && trim($message) !== '' ? trim($message) : null,
        ]);

        return $request->load(['organization', 'branch']);
    }

    /**
     * The requester withdraws a pending request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function cancel(JoinRequest $request): JoinRequest
    {
        $this->assertPending($request);

        $request->status = JoinRequestStatus::Cancelled;
        $request->decided_at = now();
        $request->save();

        return $request;
    }

    /**
     * An admin admits the person: they join the requested branch with the role
     * the admin chose (`$as`; by default what they asked for), within the
     * plan's teacher or admin limit, and any other pending request of theirs
     * is withdrawn.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function accept(JoinRequest $request, User $decider, ?Role $as = null): JoinRequest
    {
        $this->assertPending($request);

        $role = $as ?? $request->requestedRole();

        if ($role->isPlatform()) {
            throw ValidationException::withMessages(['role' => ['Pick teacher or administrator.']]);
        }

        return DB::transaction(function () use ($request, $decider, $role): JoinRequest {
            $teacher = $request->user()->lockForUpdate()->firstOrFail();
            $organization = $request->organization()->firstOrFail();

            if ($teacher->hasOrganization()) {
                throw ValidationException::withMessages([
                    'request' => ['This user already belongs to an organization.'],
                ]);
            }

            $plan = $organization->currentSubscription()->with('plan')->first()?->plan;
            $limit = $role === Role::Admin ? $plan?->max_admins : $plan?->max_teachers;
            $used = $role === Role::Admin ? $organization->admins()->count() : $organization->teachers()->count();

            if ($limit !== null && $used >= $limit) {
                throw ValidationException::withMessages([
                    'request' => [sprintf(
                        'Your plan allows up to %d %s. %s',
                        $limit,
                        $limit === 1 ? ($role === Role::Admin ? 'administrator' : 'teacher') : ($role === Role::Admin ? 'administrators' : 'teachers'),
                        $role === Role::Admin ? 'Accept them as a teacher, or contact '.config('saas.support_email').' to upgrade.' : 'Contact '.config('saas.support_email').' to upgrade.'
                    )],
                ]);
            }

            $branch = $request->branch ?? $organization->mainBranch()->first() ?? $organization->branches()->first();

            $teacher->role = $role;
            $teacher->organization_id = $organization->id;
            $teacher->branch_id = $branch?->id;
            $teacher->current_school_year_id = null;
            $teacher->save();

            $request->status = JoinRequestStatus::Accepted;
            $request->decided_by = $decider->id;
            $request->decided_at = now();
            $request->save();

            JoinRequest::query()
                ->where('user_id', $teacher->id)
                ->whereKeyNot($request->id)
                ->pending()
                ->update(['status' => JoinRequestStatus::Cancelled->value, 'decided_at' => now()]);

            return $request->load(['user.branch', 'organization', 'branch', 'decider']);
        });
    }

    /**
     * An admin turns the request down. The teacher may send a new one later.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function decline(JoinRequest $request, User $decider, ?string $reason = null): JoinRequest
    {
        $this->assertPending($request);

        $request->status = JoinRequestStatus::Declined;
        $request->decline_reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
        $request->decided_by = $decider->id;
        $request->decided_at = now();
        $request->save();

        return $request->load(['user', 'organization', 'branch', 'decider']);
    }

    /**
     * An organization's requests, newest first. `$status` narrows to one status.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\JoinRequest>
     */
    public function listFor(Organization|int|null $organization, ?string $status = null): Collection
    {
        $organizationId = $organization instanceof Organization ? $organization->getKey() : $organization;

        return JoinRequest::query()
            ->with(['user', 'organization', 'branch', 'decider'])
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->when($status !== null && $status !== '', fn ($query) => $query->where('status', $status))
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function assertPending(JoinRequest $request): void
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages([
                'request' => ['This request has already been decided.'],
            ]);
        }
    }
}
