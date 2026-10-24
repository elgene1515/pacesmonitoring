<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * The student has to take the subject (PACEs are only assigned under the
 * subjects a student is enrolled in).
 */
class StudentTakesSubject implements ValidationRule
{
    public function __construct(private readonly ?int $studentId)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $takes = $this->studentId !== null && DB::table('student_subjects')
            ->where('student_id', $this->studentId)
            ->where('subject_id', (int) $value)
            ->exists();

        if (! $takes) {
            $fail('The student does not take that subject.');
        }
    }
}
