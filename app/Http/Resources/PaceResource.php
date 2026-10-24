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
        $attributes = $this->resource->getAttributes();

        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'name' => $this->name,
            'number' => $this->number,
            // Booklets in the branch's pool (what the admin has on hand).
            'pool_stock' => (int) $this->pool_stock,
            // The signed-in teacher's own booklets, when the query added it.
            'my_stock' => $this->when(array_key_exists('my_stock', $attributes), fn () => (int) $this->my_stock),
            // Booklets held by teachers altogether, when the query added it.
            'holdings_total' => $this->when(array_key_exists('holdings_total', $attributes), fn () => (int) $this->holdings_total),
            'holdings' => $this->whenLoaded('holdings', fn () => $this->holdings
                ->filter(fn ($holding) => (int) $holding->quantity > 0 || true)
                ->map(fn ($holding) => [
                    'teacher_id' => $holding->user_id,
                    'quantity' => (int) $holding->quantity,
                    'teacher' => $holding->relationLoaded('teacher') && $holding->teacher !== null ? [
                        'id' => $holding->teacher->id,
                        'name' => $holding->teacher->name,
                        'email' => $holding->teacher->email,
                    ] : null,
                ])
                ->values()
                ->all()),
            'branch' => $this->whenLoaded('branch', fn () => $this->branch === null ? null : [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'code' => $this->branch->code,
                // The school the branch belongs to, when the query loaded it (the owner's list).
                'organization' => $this->branch->relationLoaded('organization') && $this->branch->organization !== null ? [
                    'id' => $this->branch->organization->id,
                    'name' => $this->branch->organization->name,
                ] : null,
            ]),
            'assignments_count' => $this->whenCounted('assignments'),
            // Per-status counts, present when loaded with PaceAssignment::statusCounts().
            'tested_count' => $this->whenCounted('tested'),
            'issued_count' => $this->whenCounted('issued'),
            'assigned_count' => $this->whenCounted('assigned'),
            // Stock requests still waiting for the admin, present when loaded with Pace::counts().
            'pending_requests_count' => $this->whenCounted('pendingPaceRequests'),
            'assignments' => PaceAssignmentResource::collection($this->whenLoaded('assignments')),
            'movements' => PaceStockMovementResource::collection($this->whenLoaded('movements')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
