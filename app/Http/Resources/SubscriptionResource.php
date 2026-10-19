<?php

namespace App\Http\Resources;

use App\Enums\SubscriptionStatus;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Subscription
 */
class SubscriptionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $effective = $this->effectiveStatus();
        $daysLeft = $this->daysLeft();
        $stored = $this->status instanceof SubscriptionStatus ? $this->status : SubscriptionStatus::from((string) $this->status);

        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'plan_id' => $this->plan_id,
            'plan' => new PlanResource($this->whenLoaded('plan')),
            'status' => $stored->value,
            'effective_status' => $effective->value,
            'status_label' => $effective->label(),
            'allows_writes' => $effective->allowsWrites(),
            'is_read_only' => ! $effective->allowsWrites(),
            'starts_at' => $this->starts_at?->toDateString(),
            'ends_at' => $this->ends_at?->toDateString(),
            'days_left' => $daysLeft,
            'expiring_soon' => $effective->allowsWrites()
                && $daysLeft !== null
                && $daysLeft <= (int) config('saas.expiring_soon_days', 30),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
