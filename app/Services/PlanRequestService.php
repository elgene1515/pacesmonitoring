<?php

namespace App\Services;

use App\Enums\PlanRequestStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * School admins ask to subscribe to / move to another plan or to cancel;
 * the owner approves (recording the subscription period) or declines. The
 * owner may also assign plans by hand at any time, without a request.
 */
class PlanRequestService
{
    public function __construct(private readonly SubscriptionService $subscriptions)
    {
    }

    /**
     * File a request. One pending request per organization at a time.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function submit(Organization $organization, ?User $by, string $action, ?Plan $plan, ?string $message = null): PlanRequest
    {
        if ($organization->planRequests()->pending()->exists()) {
            throw ValidationException::withMessages([
                'action' => ['Your organization already has a pending request. Withdraw it before sending another one.'],
            ]);
        }

        if ($action === PlanRequest::ACTION_CHANGE && $plan === null) {
            throw ValidationException::withMessages([
                'plan_id' => ['Pick the plan you want to move to.'],
            ]);
        }

        if ($action === PlanRequest::ACTION_CHANGE && ! $plan->is_active) {
            throw ValidationException::withMessages([
                'plan_id' => ['That plan is no longer available.'],
            ]);
        }

        $request = $organization->planRequests()->create([
            'requested_by' => $by?->id,
            'action' => $action,
            'plan_id' => $action === PlanRequest::ACTION_CHANGE ? $plan->id : null,
            'message' => $message !== null && trim($message) !== '' ? trim($message) : null,
            'status' => PlanRequestStatus::Pending,
        ]);

        return $request->load(['plan', 'requester', 'organization']);
    }

    /**
     * The school withdraws its pending request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function cancel(PlanRequest $request): PlanRequest
    {
        $this->assertPending($request);

        $request->status = PlanRequestStatus::Cancelled;
        $request->decided_at = now();
        $request->save();

        return $request->load(['plan', 'requester', 'organization']);
    }

    /**
     * The owner approves: a plan change records a new subscription period on
     * the requested plan; a cancellation marks the current subscription cancelled.
     *
     * @param  array<string, mixed>  $data  starts_at, ends_at?, notes? (plan changes)
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function approve(PlanRequest $request, User $decider, array $data = []): PlanRequest
    {
        $this->assertPending($request);

        return DB::transaction(function () use ($request, $decider, $data): PlanRequest {
            $organization = $request->organization()->firstOrFail();

            if ($request->isCancellation()) {
                $current = $organization->currentSubscription()->first();

                if ($current !== null) {
                    $current->status = SubscriptionStatus::Cancelled;
                    $current->notes = trim(($current->notes ?? '')."\nCancelled at the school's request.");
                    $current->save();
                    $request->subscription_id = $current->id;
                }
            } else {
                $subscription = $this->subscriptions->assign($organization, [
                    'plan_id' => $request->plan_id,
                    'status' => SubscriptionStatus::Active->value,
                    'starts_at' => $data['starts_at'],
                    'ends_at' => $data['ends_at'] ?? null,
                    'notes' => $data['notes'] ?? "Approved plan request #{$request->id}.",
                ], $decider);
                $request->subscription_id = $subscription->id;
            }

            $request->status = PlanRequestStatus::Approved;
            $request->decision_note = isset($data['decision_note']) && trim((string) $data['decision_note']) !== '' ? trim((string) $data['decision_note']) : null;
            $request->decided_by = $decider->id;
            $request->decided_at = now();
            $request->save();

            return $request->load(['plan', 'requester', 'decider', 'organization', 'subscription.plan']);
        });
    }

    /**
     * The owner turns the request down (with an optional note shown to the school).
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function decline(PlanRequest $request, User $decider, ?string $note = null): PlanRequest
    {
        $this->assertPending($request);

        $request->status = PlanRequestStatus::Declined;
        $request->decision_note = $note !== null && trim($note) !== '' ? trim($note) : null;
        $request->decided_by = $decider->id;
        $request->decided_at = now();
        $request->save();

        return $request->load(['plan', 'requester', 'decider', 'organization']);
    }

    /**
     * Requests of one organization (every organization's when null), pending first.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\PlanRequest>
     */
    public function listFor(Organization|int|null $organization, ?string $status = null): Collection
    {
        $organizationId = $organization instanceof Organization ? $organization->getKey() : $organization;

        return PlanRequest::query()
            ->with(['plan', 'requester', 'decider', 'organization', 'subscription.plan'])
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
    protected function assertPending(PlanRequest $request): void
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages([
                'request' => ['This request has already been decided.'],
            ]);
        }
    }
}
