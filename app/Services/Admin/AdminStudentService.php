<?php

namespace App\Services\Admin;

use App\Models\SchoolYear;
use App\Models\Student;
use App\Services\PaceAssignmentService;
use App\Services\StudentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class AdminStudentService
{
    public function __construct(
        private readonly PaceAssignmentService $assignments,
        private readonly StudentService $students,
    ) {
    }

    /**
     * Every student of the teachers in scope, with their grade level, school
     * year, teacher, and (for the school year they are enrolled in) the PACE
     * record status counts and average score, like the teacher's own list.
     *
     * Supported filters: search (matches first/middle/last name), teacher_id
     * (the student's creator), branch_id, grade_level_id, school_year_id.
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Student>
     */
    public function list(array $filters = [], ?int $organizationId = null): Collection
    {
        $scope = TeacherScope::fromFilters($filters, $organizationId);

        $query = $scope->applyOwner(Student::query(), 'students.created_by')
            ->with(['gradeLevel', 'schoolYear', 'creator.branch', 'creator.organization'])
            ->withCount($this->students->enrolledStatusCounts())
            ->withSum(['assignments as score_sum' => $this->students->enrolledYearOnly()], 'score')
            ->orderBy('last_name')
            ->orderBy('first_name');

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';

            $query->where(function (Builder $inner) use ($like): void {
                $inner->where('first_name', 'like', $like)
                    ->orWhere('middle_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like);
            });
        }

        $gradeLevelId = $filters['grade_level_id'] ?? null;

        if ($gradeLevelId !== null && $gradeLevelId !== '') {
            $query->where('grade_level_id', (int) $gradeLevelId);
        }

        $schoolYearId = $filters['school_year_id'] ?? null;

        if ($schoolYearId !== null && $schoolYearId !== '') {
            $query->where('school_year_id', (int) $schoolYearId);
        }

        return $query->get();
    }

    /**
     * Load a student of a teacher in scope with everything the PACE Projection
     * view needs: grade level with subjects, school year, teacher, and every
     * assignment (with pace and subject) in display order. 404 outside the scope.
     */
    public function detail(int $studentId, ?int $organizationId = null): Student
    {
        return TeacherScope::forOrganization($organizationId)
            ->applyOwner(Student::query(), 'students.created_by')
            ->with([
                'creator.branch',
                'creator.organization',
                'gradeLevel.subjects',
                'gradeLevel.schoolYear',
                'schoolYear',
                'assignments' => function ($query): void {
                    $query->with(['pace', 'subject']);
                    $this->assignments->applyDefaultOrder($query);
                },
            ])
            ->findOrFail($studentId);
    }

    /**
     * Every school year of the student's organization (for the school-year
     * filter), newest first.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\SchoolYear>
     */
    public function schoolYearsFor(Student $student): Collection
    {
        return SchoolYear::query()
            ->forOrganization($student->creator?->organization_id)
            ->orderByDesc('name')
            ->get();
    }
}
