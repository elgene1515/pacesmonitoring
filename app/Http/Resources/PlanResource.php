<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Plan
 */
class PlanResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'max_branches' => $this->max_branches,
            'max_admins' => $this->max_admins,
            'max_teachers' => $this->max_teachers,
            'max_students' => $this->max_students,
            'price_per_year' => (float) $this->price_per_year,
            'currency' => $this->currency,
            'is_active' => (bool) $this->is_active,
            'sort_order' => $this->sort_order,
            // The plan a new school's free trial runs on (its limits apply until a paid plan is approved).
            'is_trial_default' => $this->slug === config('saas.default_plan', 'starter'),
            'subscriptions_count' => $this->whenCounted('subscriptions'),
            'plan_requests_count' => $this->whenCounted('planRequests'),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
