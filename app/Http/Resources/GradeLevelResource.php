<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\GradeLevel
 */
class GradeLevelResource extends JsonResource
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
            'name' => $this->name,
            'sort_order' => $this->sort_order,
            'school_year_id' => $this->school_year_id,
            'school_year' => new SchoolYearResource($this->whenLoaded('schoolYear')),
            // The teacher who owns the grade level; only loaded for the admin pages.
            'teacher' => $this->whenLoaded('user', fn () => $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'branch_name' => $this->user->relationLoaded('branch') ? $this->user->branch?->name : null,
            ]),
            'students_count' => $this->whenCounted('students'),
            'subjects_count' => $this->whenCounted('subjects'),
            'subjects' => SubjectResource::collection($this->whenLoaded('subjects')),
            // Records under every subject; counts present when loaded with PaceAssignment::statusCounts().
            'assignments_count' => $this->whenCounted('assignments'),
            'tested_count' => $this->whenCounted('tested'),
            'issued_count' => $this->whenCounted('issued'),
            'assigned_count' => $this->whenCounted('assigned'),
            'assignments' => PaceAssignmentResource::collection($this->whenLoaded('assignments')),
        ];
    }
}
