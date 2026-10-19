<?php

namespace App\Services\Owner;

use App\Enums\JoinRequestStatus;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Models\JoinRequest;
use App\Models\Organization;
use App\Models\PaceAssignment;
use App\Models\PlanRequest;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Platform-wide figures for the owner's home page: accounts, organizations
 * and the state of every subscription.
 */
class OwnerDashboardService
{
    public const RECENT_LIMIT = 5;

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $organizations = Organization::query()
            ->with('currentSubscription.plan')
            ->withCount(['teachers', 'admins', 'branches'])
            ->get();

        $byStatus = array_fill_keys([...SubscriptionStatus::values(), 'none'], 0);
        $expiring = [];
        $soonDays = (int) config('saas.expiring_soon_days', 30);

        foreach ($organizations as $organization) {
            $subscription = $organization->currentSubscription;
            $status = $subscription?->effectiveStatus()->value ?? 'none';
            $byStatus[$status]++;

            $daysLeft = $subscription?->daysLeft();

            if ($subscription !== null && $subscription->allowsWrites() && $daysLeft !== null && $daysLeft <= $soonDays) {
                $expiring[] = [
                    'organization_id' => $organization->id,
                    'organization_name' => $organization->name,
                    'plan_name' => $subscription->plan?->name ?? '',
                    'status' => $status,
                    'ends_at' => $subscription->ends_at?->toDateString(),
                    'days_left' => $daysLeft,
                ];
            }
        }

        usort($expiring, static fn (array $a, array $b): int => $a['days_left'] <=> $b['days_left']);

        return [
            'totals' => [
                'organizations' => $organizations->count(),
                'deactivated_organizations' => Organization::onlyTrashed()->count(),
                'branches' => (int) $organizations->sum('branches_count'),
                'owners' => User::query()->role(Role::Owner)->count(),
                'vice_admins' => User::query()->role(Role::ViceAdmin)->count(),
                'admins' => User::query()->role(Role::Admin)->count(),
                'teachers' => User::query()->role(Role::Teacher)->count(),
                'unassigned_users' => User::query()->whereNull('organization_id')->whereNotIn('role', Role::platform())->count(),
                'students' => Student::query()->count(),
                'assignments' => PaceAssignment::query()->count(),
                'pending_join_requests' => JoinRequest::query()->pending()->count(),
                'pending_plan_requests' => PlanRequest::query()->pending()->count(),
            ],
            'subscriptions_by_status' => $byStatus,
            'expiring_soon' => $expiring,
            'expiring_soon_days' => $soonDays,
        ];
    }

    /**
     * The most recently registered organizations.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Organization>
     */
    public function recentOrganizations(): Collection
    {
        return Organization::query()
            ->with(['currentSubscription.plan', 'creator'])
            ->withCount(['teachers', 'admins', 'branches'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_LIMIT)
            ->get();
    }

    /**
     * The most recently registered users (any role).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\User>
     */
    public function recentUsers(): Collection
    {
        return User::query()
            ->with(['organization', 'branch'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_LIMIT)
            ->get();
    }

    /**
     * Pending join requests across every organization, oldest first.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\JoinRequest>
     */
    public function pendingJoinRequests(): Collection
    {
        return JoinRequest::query()
            ->with(['user', 'organization', 'branch'])
            ->where('status', JoinRequestStatus::Pending->value)
            ->orderBy('created_at')
            ->limit(10)
            ->get();
    }

    /**
     * Pending plan requests across every organization, oldest first.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\PlanRequest>
     */
    public function pendingPlanRequests(): Collection
    {
        return PlanRequest::query()
            ->with(['organization.currentSubscription.plan', 'plan', 'requester'])
            ->pending()
            ->orderBy('created_at')
            ->limit(10)
            ->get();
    }

    /**
     * Today, for "as of" labels.
     */
    public function today(): string
    {
        return Carbon::today()->toDateString();
    }
}
