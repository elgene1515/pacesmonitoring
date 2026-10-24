<?php

namespace App\Http\Resources;

use App\Enums\StudentChangeRequestStatus;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\StudentChangeRequest
 */
class StudentChangeRequestResource extends JsonResource
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
            'student_id' => $this->student_id,
            'organization_id' => $this->organization_id,
            'requested_by' => $this->requested_by,
            'changes' => $this->changes,
            'status' => $this->status instanceof StudentChangeRequestStatus ? $this->status->value : $this->status,
            'decline_reason' => $this->decline_reason,
            'decided_by' => $this->decided_by,
            'decided_at' => $this->decided_at?->toISOString(),
            'student' => new StudentResource($this->whenLoaded('student')),
            'requester' => $this->whenLoaded('requester', fn () => $this->requester === null ? null : [
                'id' => $this->requester->id,
                'name' => $this->requester->name,
                'email' => $this->requester->email,
                'branch_name' => $this->requester->relationLoaded('branch') ? $this->requester->branch?->name : null,
            ]),
            'decider' => $this->whenLoaded('decider', fn () => $this->decider === null ? null : [
                'id' => $this->decider->id,
                'name' => $this->decider->name,
            ]),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
