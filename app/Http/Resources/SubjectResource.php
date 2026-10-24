<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Subject
 */
class SubjectResource extends JsonResource
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
            'grade_level_id' => $this->grade_level_id,
            'name' => $this->name,
            'sort_order' => $this->sort_order,
            'assignments_count' => $this->whenCounted('assignments'),
            // Per-status counts, present when loaded with PaceAssignment::statusCounts().
            'tested_count' => $this->whenCounted('tested'),
            'issued_count' => $this->whenCounted('issued'),
            'assigned_count' => $this->whenCounted('assigned'),
            'grade_level' => new GradeLevelResource($this->whenLoaded('gradeLevel')),
            // Who teaches this subject, per section.
            'teaching_assignments' => TeachingAssignmentResource::collection($this->whenLoaded('teachingAssignments')),
            'assignments' => PaceAssignmentResource::collection($this->whenLoaded('assignments')),
        ];
    }
}
