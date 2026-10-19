<?php

namespace App\Http\Resources;

use App\Enums\PlanRequestStatus;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PlanRequest
 */
class PlanRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $status = $this->status instanceof PlanRequestStatus ? $this->status->value : (string) $this->status;

        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'action' => $this->action,
            'status' => $status,
            'message' => $this->message,
            'decision_note' => $this->decision_note,
            'plan_id' => $this->plan_id,
            'plan' => new PlanResource($this->whenLoaded('plan')),
            'organization' => $this->whenLoaded('organization', fn () => $this->organization === null ? null : [
                'id' => $this->organization->id,
                'name' => $this->organization->name,
                'code' => $this->organization->code,
                'current_plan_name' => $this->organization->relationLoaded('currentSubscription')
                    ? ($this->organization->currentSubscription?->plan?->name)
                    : null,
            ]),
            'requested_by' => $this->whenLoaded('requester', fn () => $this->requester === null ? null : [
                'id' => $this->requester->id,
                'name' => $this->requester->name,
                'email' => $this->requester->email,
            ]),
            'decided_by' => $this->whenLoaded('decider', fn () => $this->decider === null ? null : [
                'id' => $this->decider->id,
                'name' => $this->decider->name,
            ]),
            'subscription' => new SubscriptionResource($this->whenLoaded('subscription')),
            'decided_at' => $this->decided_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
