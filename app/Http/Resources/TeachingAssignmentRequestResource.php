<?php

namespace App\Http\Resources;

use App\Enums\TeachingAssignmentRequestStatus;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\TeachingAssignmentRequest
 */
class TeachingAssignmentRequestResource extends JsonResource
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
            'user_id' => $this->user_id,
            'organization_id' => $this->organization_id,
            'section_id' => $this->section_id,
            'subject_id' => $this->subject_id,
            'message' => $this->message,
            'status' => $this->status instanceof TeachingAssignmentRequestStatus ? $this->status->value : $this->status,
            'decline_reason' => $this->decline_reason,
            'decided_by' => $this->decided_by,
            'decided_at' => $this->decided_at?->toISOString(),
            'teacher' => $this->whenLoaded('teacher', fn () => $this->teacher === null ? null : [
                'id' => $this->teacher->id,
                'name' => $this->teacher->name,
                'email' => $this->teacher->email,
                'branch_name' => $this->teacher->relationLoaded('branch') ? $this->teacher->branch?->name : null,
            ]),
            'section' => new SectionResource($this->whenLoaded('section')),
            'subject' => new SubjectResource($this->whenLoaded('subject')),
            'decider' => $this->whenLoaded('decider', fn () => $this->decider === null ? null : [
                'id' => $this->decider->id,
                'name' => $this->decider->name,
            ]),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
