<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Subscriptions are recorded by the owner (billing happens outside the app:
 * annual invoices). A new organization starts on a trial of the default plan.
 */
class SubscriptionService
{
    /**
     * Start the trial a freshly registered organization runs on.
     */
    public function startTrial(Organization $organization, ?User $by = null): Subscription
    {
        $plan = $this->defaultPlan();
        $today = Carbon::today();

        return $organization->subscriptions()->create([
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Trial,
            'starts_at' => $today,
            'ends_at' => $today->copy()->addDays(max(1, (int) config('saas.trial_days', 30))),
            'notes' => 'Trial started at sign-up.',
            'created_by' => $by?->id,
        ]);
    }

    /**
     * Record a new subscription period (a plan assignment or renewal). It
     * becomes the organization's current subscription.
     *
     * @param  array<string, mixed>  $data  plan_id, status, starts_at, ends_at?, notes?
     */
    public function assign(Organization $organization, array $data, User $by): Subscription
    {
        $subscription = $organization->subscriptions()->create([
            'plan_id' => (int) $data['plan_id'],
            'status' => $data['status'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $by->id,
        ]);

        return $subscription->load('plan');
    }

    /**
     * Change the plan, status, dates or notes of an existing period.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Subscription $subscription, array $data): Subscription
    {
        $subscription->fill($data);
        $subscription->save();

        return $subscription->load('plan');
    }

    /**
     * The plan new organizations trial on.
     */
    public function defaultPlan(): Plan
    {
        return Plan::query()->where('slug', config('saas.default_plan', 'starter'))->first()
            ?? Plan::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->firstOrFail();
    }

    /**
     * The plan's limits against the organization's usage, for the subscription cards.
     *
     * @return array{branches_used: int, branches_limit: ?int, teachers_used: int, teachers_limit: ?int, students_used: int, students_limit: ?int}
     */
    public function usage(Organization $organization, ?Subscription $subscription): array
    {
        $plan = $subscription?->plan;

        return [
            'branches_used' => $organization->branches()->count(),
            'branches_limit' => $plan?->max_branches,
            'teachers_used' => $organization->teachers()->count(),
            'teachers_limit' => $plan?->max_teachers,
            'students_used' => $organization->studentsQuery()->count(),
            'students_limit' => $plan?->max_students,
        ];
    }

    /**
     * Refuse a new student once the organization's plan caps students and the cap is reached.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function assertCanAddStudent(?Organization $organization): void
    {
        if ($organization === null) {
            return;
        }

        $limit = $organization->currentSubscription()->with('plan')->first()?->plan?->max_students;

        if ($limit !== null && $organization->studentsQuery()->count() >= $limit) {
            throw ValidationException::withMessages([
                'students' => [sprintf(
                    "Your school's plan allows up to %d %s. Ask your administrator to upgrade the plan.",
                    $limit,
                    $limit === 1 ? 'student' : 'students'
                )],
            ]);
        }
    }
}
