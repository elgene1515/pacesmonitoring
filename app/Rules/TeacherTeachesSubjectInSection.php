<?php

namespace App\Rules;

use App\Models\TeachingAssignment;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The subject must be one the teacher is assigned to teach in the given
 * section (which also makes it a subject of the section's grade level).
 */
class TeacherTeachesSubjectInSection implements ValidationRule
{
    public function __construct(private readonly ?int $sectionId, private readonly ?int $teacherId)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if ($this->sectionId === null || $this->teacherId === null) {
            $fail('The selected subject is invalid.');

            return;
        }

        $assigned = TeachingAssignment::query()
            ->where('section_id', $this->sectionId)
            ->where('subject_id', (int) $value)
            ->where('teacher_id', $this->teacherId)
            ->exists();

        if (! $assigned) {
            $fail("You are not assigned to teach that subject in the student's section.");
        }
    }
}
