<?php

namespace App\Services;

use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Keeps the enrollment history: a row is opened when a student is enrolled
 * in a section and closed (with the next one opened) when they move. The
 * student's `section_id` stays the source of truth for where they are now;
 * the history only records where they have been.
 */
class StudentEnrollmentService
{
    /**
     * Open a stay in `$section` starting today (or `$on`).
     */
    public function open(Student $student, Section $section, ?User $by = null, ?Carbon $on = null): StudentEnrollment
    {
        $section->loadMissing(['gradeLevel.schoolYear', 'gradeLevel.branch']);
        $level = $section->gradeLevel;

        return $student->enrollments()->create([
            'section_id' => $section->id,
            'grade_level_id' => $level?->id,
            'school_year_id' => $level?->school_year_id,
            'branch_id' => $level?->branch_id,
            'section_name' => $section->name,
            'grade_level_name' => $level?->name ?? '',
            'school_year_name' => $level?->schoolYear?->name,
            'branch_name' => $level?->branch?->name,
            'started_on' => ($on ?? Carbon::today())->toDateString(),
            'moved_by' => $by?->id,
        ]);
    }

    /**
     * Close whatever stay is still open, as of today (or `$on`).
     */
    public function closeCurrent(Student $student, ?Carbon $on = null): void
    {
        $student->enrollments()->open()->update(['ended_on' => ($on ?? Carbon::today())->toDateString()]);
    }

    /**
     * The student moved to `$section`: the current stay ends and a new one begins.
     */
    public function recordMove(Student $student, Section $section, ?User $by = null): StudentEnrollment
    {
        $this->closeCurrent($student);

        return $this->open($student, $section, $by);
    }
}
