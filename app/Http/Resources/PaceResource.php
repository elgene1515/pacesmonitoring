<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Pace
 */
class PaceResource extends JsonResource
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
            'number' => $this->number,
            // Booklets on hand; issuing a PACE to a student takes one.
            'stock' => (int) $this->stock,
            'assignments_count' => $this->whenCounted('assignments'),
            // Per-status counts, present when loaded with PaceAssignment::statusCounts().
            'tested_count' => $this->whenCounted('tested'),
            'issued_count' => $this->whenCounted('issued'),
            'assigned_count' => $this->whenCounted('assigned'),
            // Stock requests still waiting for the admin, present when loaded with Pace::counts().
            'pending_requests_count' => $this->whenCounted('pendingPaceRequests'),
            'assignments' => PaceAssignmentResource::collection($this->whenLoaded('assignments')),
            // The teacher who registered the PACE; only loaded for the admin pages.
            'teacher' => $this->whenLoaded('creator', fn () => $this->creator === null ? null : [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
                'email' => $this->creator->email,
                'branch_name' => $this->creator->relationLoaded('branch') ? $this->creator->branch?->name : null,
            ]),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
