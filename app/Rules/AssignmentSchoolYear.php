<?php

namespace App\Rules;

use App\Models\SchoolYear;
use App\Models\Subject;
use Illuminate\Contracts\Validation\Validator;

/**
 * An assignment's school-year label must match the school year the subject's
 * grade level is registered under. A PACE is not tied to a school year, so it
 * may be assigned under any subject or school year.
 */
class AssignmentSchoolYear
{
    public static function check(Validator $validator, ?int $subjectId, ?string $schoolYear): void
    {
        if ($subjectId === null) {
            return;
        }

        $subject = Subject::query()->with('gradeLevel')->find($subjectId);

        if ($subject === null) {
            return;
        }

        $yearId = $subject->gradeLevel?->school_year_id;

        if ($yearId === null) {
            $validator->errors()->add('subject_id', "The subject's grade level is not registered under a school year.");

            return;
        }

        $yearName = SchoolYear::query()->whereKey($yearId)->value('name');

        if ($schoolYear !== null && $yearName !== null && $schoolYear !== $yearName) {
            $validator->errors()->add(
                'school_year',
                sprintf('The school year must be %s, the school year of the subject\'s grade level.', $yearName)
            );
        }
    }
}
