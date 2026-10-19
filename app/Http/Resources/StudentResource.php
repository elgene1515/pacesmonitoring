<?php

namespace App\Http\Resources;

use App\Enums\Sex;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Student
 */
class StudentResource extends JsonResource
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
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'extension' => $this->extension,
            'full_name' => $this->full_name,
            'birth_date' => $this->birth_date?->format('Y-m-d'),
            'age' => $this->age,
            'sex' => $this->sex instanceof Sex ? $this->sex->value : $this->sex,
            'grade_level_id' => $this->grade_level_id,
            'grade_level' => new GradeLevelResource($this->whenLoaded('gradeLevel')),
            'school_year_id' => $this->school_year_id,
            'school_year' => new SchoolYearResource($this->whenLoaded('schoolYear')),
            'assignments_count' => $this->whenCounted('assignments'),
            // Per-status counts, present when loaded with PaceAssignment::statusCounts().
            'tested_count' => $this->whenCounted('tested'),
            'issued_count' => $this->whenCounted('issued'),
            'assigned_count' => $this->whenCounted('assigned'),
            // Average score across every record filed in the student's enrolled school year
            // (unscored PACEs count as 0); null without records in that year. Present when the
            // query added `score_sum` next to the enrolled-year `assignments_count` (see StudentService).
            'average_score' => $this->when(
                $this->resource->getAttribute('assignments_count') !== null
                    && array_key_exists('score_sum', $this->resource->getAttributes()),
                fn () => (int) $this->resource->getAttribute('assignments_count') > 0
                    ? round((float) $this->resource->getAttribute('score_sum') / (int) $this->resource->getAttribute('assignments_count'), 2)
                    : null
            ),
            'assignments' => PaceAssignmentResource::collection($this->whenLoaded('assignments')),
            'creator' => $this->whenLoaded('creator', fn () => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
                'email' => $this->creator->email,
                'organization_name' => $this->creator->relationLoaded('organization') ? $this->creator->organization?->name : null,
                'branch_name' => $this->creator->relationLoaded('branch') ? $this->creator->branch?->name : null,
            ]),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
