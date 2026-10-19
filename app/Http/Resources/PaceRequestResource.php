<?php

namespace App\Http\Resources;

use App\Enums\PaceRequestStatus;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PaceRequest
 */
class PaceRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $status = $this->status instanceof PaceRequestStatus ? $this->status->value : (string) $this->status;

        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'user_id' => $this->user_id,
            'pace_id' => $this->pace_id,
            'pace_number' => $this->pace_number,
            'pace_name' => $this->pace_name,
            'quantity' => (int) $this->quantity,
            'needed_by' => $this->needed_by?->format('Y-m-d'),
            'message' => $this->message,
            'status' => $status,
            'decision_note' => $this->decision_note,
            'teacher' => $this->whenLoaded('teacher', fn () => $this->teacher === null ? null : [
                'id' => $this->teacher->id,
                'name' => $this->teacher->name,
                'email' => $this->teacher->email,
                'branch' => $this->teacher->relationLoaded('branch') && $this->teacher->branch !== null ? [
                    'id' => $this->teacher->branch->id,
                    'name' => $this->teacher->branch->name,
                ] : null,
            ]),
            'pace' => new PaceResource($this->whenLoaded('pace')),
            'organization' => $this->whenLoaded('organization', fn () => $this->organization === null ? null : [
                'id' => $this->organization->id,
                'name' => $this->organization->name,
                'code' => $this->organization->code,
            ]),
            'decided_by' => $this->whenLoaded('decider', fn () => $this->decider === null ? null : [
                'id' => $this->decider->id,
                'name' => $this->decider->name,
            ]),
            'decided_at' => $this->decided_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
