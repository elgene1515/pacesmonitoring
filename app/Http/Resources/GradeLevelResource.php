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
            'branch_id' => $this->branch_id,
            'school_year_id' => $this->school_year_id,
            'created_by' => $this->created_by,
            'name' => $this->name,
            'sort_order' => $this->sort_order,
            'school_year' => new SchoolYearResource($this->whenLoaded('schoolYear')),
            'branch' => $this->whenLoaded('branch', fn () => $this->branch === null ? null : [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'code' => $this->branch->code,
                // The school the branch belongs to, when the query loaded it (the section page names it).
                'organization' => $this->branch->relationLoaded('organization') && $this->branch->organization !== null ? [
                    'id' => $this->branch->organization->id,
                    'name' => $this->branch->organization->name,
                ] : null,
            ]),
            'students_count' => $this->whenCounted('students'),
            'subjects_count' => $this->whenCounted('subjects'),
            'sections_count' => $this->whenCounted('sections'),
            'subjects' => SubjectResource::collection($this->whenLoaded('subjects')),
            'sections' => SectionResource::collection($this->whenLoaded('sections')),
            // Records under every subject; counts present when loaded with PaceAssignment::statusCounts().
            'assignments_count' => $this->whenCounted('assignments'),
            'tested_count' => $this->whenCounted('tested'),
            'issued_count' => $this->whenCounted('issued'),
            'assigned_count' => $this->whenCounted('assigned'),
            'assignments' => PaceAssignmentResource::collection($this->whenLoaded('assignments')),
        ];
    }
}
