<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Organization
 */
class OrganizationResource extends JsonResource
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
            'code' => $this->code,
            // The school's address is always its main branch's (a legacy value on the school is only a fallback).
            'address' => $this->mainBranchAddress() ?: $this->address,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'branches' => BranchResource::collection($this->whenLoaded('branches')),
            'subscription' => new SubscriptionResource($this->whenLoaded('currentSubscription')),
            'subscriptions' => SubscriptionResource::collection($this->whenLoaded('subscriptions')),
            'branches_count' => $this->whenCounted('branches'),
            'teachers_count' => $this->whenCounted('teachers'),
            'admins_count' => $this->whenCounted('admins'),
            'pending_join_requests_count' => $this->whenCounted('pendingJoinRequests'),
            'pending_plan_requests_count' => $this->whenCounted('pendingPlanRequests'),
            'pending_pace_requests_count' => $this->whenCounted('pendingPaceRequests'),
            'plan_requests' => PlanRequestResource::collection($this->whenLoaded('planRequests')),
            'deleted_at' => $this->deleted_at?->toISOString(),
            'is_deleted' => $this->trashed(),
            'students_count' => $this->when(
                $this->resource->getAttribute('students_count') !== null,
                fn () => (int) $this->resource->getAttribute('students_count')
            ),
            'assignments_count' => $this->when(
                $this->resource->getAttribute('assignments_count') !== null,
                fn () => (int) $this->resource->getAttribute('assignments_count')
            ),
            'creator' => $this->whenLoaded('creator', fn () => $this->creator === null ? null : [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
                'email' => $this->creator->email,
            ]),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
