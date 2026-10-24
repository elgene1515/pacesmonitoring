<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\StudentEnrollment
 */
class StudentEnrollmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            // Ids are null once the section / grade level / year was deleted; the names stay.
            'section_id' => $this->section_id,
            'grade_level_id' => $this->grade_level_id,
            'school_year_id' => $this->school_year_id,
            'branch_id' => $this->branch_id,
            'section_name' => $this->section_name,
            'grade_level_name' => $this->grade_level_name,
            'school_year_name' => $this->school_year_name,
            'branch_name' => $this->branch_name,
            'started_on' => $this->started_on?->format('Y-m-d'),
            'ended_on' => $this->ended_on?->format('Y-m-d'),
            'is_current' => $this->isCurrent(),
            'moved_by' => $this->whenLoaded('mover', fn () => $this->mover === null ? null : [
                'id' => $this->mover->id,
                'name' => $this->mover->name,
            ]),
        ];
    }
}
