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
        $section = $this->relationLoaded('section') ? $this->section : null;
        $gradeLevel = $section !== null && $section->relationLoaded('gradeLevel') ? $section->gradeLevel : null;

        return [
            'id' => $this->id,
            'student_number' => $this->student_number,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'extension' => $this->extension,
            'full_name' => $this->full_name,
            'birth_date' => $this->birth_date?->format('Y-m-d'),
            'age' => $this->age,
            'sex' => $this->sex instanceof Sex ? $this->sex->value : $this->sex,
            // Contact, emergency and health details (all optional).
            'email' => $this->email,
            'mobile' => $this->mobile,
            'address' => $this->address,
            'guardian_name' => $this->guardian_name,
            'guardian_relationship' => $this->guardian_relationship,
            'guardian_phone' => $this->guardian_phone,
            'guardian_email' => $this->guardian_email,
            'blood_type' => $this->blood_type,
            'medical_notes' => $this->medical_notes,
            'section_id' => $this->section_id,
            'section' => new SectionResource($this->whenLoaded('section')),
            'grade_level_id' => $section?->grade_level_id,
            'grade_level' => $this->when($gradeLevel !== null, fn () => new GradeLevelResource($gradeLevel)),
            'school_year_id' => $this->school_year_id,
            'school_year' => new SchoolYearResource($this->whenLoaded('schoolYear')),
            // The subjects the student takes (each student has their own list).
            'subjects' => SubjectResource::collection($this->whenLoaded('subjects')),
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
            // Enrollment history (detail endpoint): every section the student has been in, latest first.
            'enrollments' => StudentEnrollmentResource::collection($this->whenLoaded('enrollments')),
            // Who registered the student (an audit detail, not an owner).
            'registered_by' => $this->whenLoaded('creator', fn () => $this->creator === null ? null : [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
                'email' => $this->creator->email,
            ]),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
