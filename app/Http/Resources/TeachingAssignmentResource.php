<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\TeachingAssignment
 */
class TeachingAssignmentResource extends JsonResource
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
            'section_id' => $this->section_id,
            'subject_id' => $this->subject_id,
            'teacher_id' => $this->teacher_id,
            'assigned_by' => $this->assigned_by,
            'section' => new SectionResource($this->whenLoaded('section')),
            'subject' => new SubjectResource($this->whenLoaded('subject')),
            'teacher' => $this->whenLoaded('teacher', fn () => $this->teacher === null ? null : [
                'id' => $this->teacher->id,
                'name' => $this->teacher->name,
                'email' => $this->teacher->email,
            ]),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
