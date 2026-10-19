<?php

namespace App\Services\Admin;

use App\Models\GradeLevel;
use App\Models\PaceAssignment;
use App\Models\Subject;
use App\Services\GradeLevelService;
use Illuminate\Database\Eloquent\Collection;

class AdminGradeLevelService
{
    public function __construct(private readonly GradeLevelService $gradeLevels)
    {
    }

    /**
     * One teacher's grade levels with subjects and counts, newest school year
     * first. `$schoolYearId` narrows the list to just that school year (used by
     * the student masterlist's teacher -> school year -> grade level filters).
     * A teacher outside the organization in scope yields an empty list.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\GradeLevel>
     */
    public function listFor(int $teacherId, ?int $schoolYearId = null, ?int $organizationId = null): Collection
    {
        $query = GradeLevel::query()
            ->select('grade_levels.*')
            ->leftJoin('school_years', 'school_years.id', '=', 'grade_levels.school_year_id')
            ->where('grade_levels.user_id', $teacherId)
            ->with(['schoolYear', 'subjects' => fn ($subjects) => $subjects->withCount(PaceAssignment::statusCounts())])
            ->withCount(['students', 'subjects'])
            ->orderByDesc('school_years.name')
            ->orderBy('grade_levels.sort_order')
            ->orderBy('grade_levels.name');

        TeacherScope::forOrganization($organizationId)->applyOwner($query, 'grade_levels.user_id');

        if ($schoolYearId !== null) {
            $query->where('grade_levels.school_year_id', $schoolYearId);
        }

        return $query->get();
    }

    /**
     * A grade level of a teacher in scope with its subjects and every record
     * under them (the same payload the teacher's own page uses). 404 outside
     * the scope.
     */
    public function detail(int $gradeLevelId, ?int $organizationId = null): GradeLevel
    {
        $gradeLevel = TeacherScope::forOrganization($organizationId)
            ->applyOwner(GradeLevel::query(), 'grade_levels.user_id')
            ->findOrFail($gradeLevelId);

        return $this->gradeLevels->detail($gradeLevel);
    }

    /**
     * A subject of a teacher in scope with its grade level (and the teacher who
     * owns it, with their branch) and every record filed under it. 404 outside
     * the scope.
     */
    public function subjectDetail(int $subjectId, ?int $organizationId = null): Subject
    {
        $subject = Subject::query()
            ->whereIn('grade_level_id', TeacherScope::forOrganization($organizationId)->applyOwner(GradeLevel::query(), 'grade_levels.user_id')->select('grade_levels.id'))
            ->findOrFail($subjectId);

        // loadMissing keeps the grade level (and its school year) the shared detail already loaded.
        return $this->gradeLevels->subjectDetail($subject)->loadMissing('gradeLevel.user.branch');
    }
}
