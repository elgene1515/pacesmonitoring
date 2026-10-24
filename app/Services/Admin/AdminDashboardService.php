<?php

namespace App\Services\Admin;

use App\Enums\Role;
use App\Models\GradeLevel;
use App\Models\Pace;
use App\Models\PaceAssignment;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only analytics for the admin Home page: every record in scope
 * combined, optionally narrowed to one branch, teacher, school year, grade
 * level and subject. An organization admin's scope is their own
 * organization; the owner sees everything or one organization. The
 * per-quarter / per-subject / per-grade-level and score-band breakdowns are
 * the same ones the teacher Home page uses.
 */
class AdminDashboardService
{
    /**
     * Number of rows shown in each "recent" list.
     */
    public const RECENT_LIMIT = 5;

    /**
     * Number of school years shown in the per-school-year breakdown.
     */
    public const SCHOOL_YEAR_LIMIT = 10;

    /**
     * Number of teachers shown in the activity ranking.
     */
    public const TEACHER_ACTIVITY_LIMIT = 10;

    /**
     * Number of records shown in the "fails and retakes" list.
     */
    public const FAILED_LIMIT = 15;

    /**
     * Number of students shown in the "top performers" ranking.
     */
    public const TOP_PERFORMER_LIMIT = 10;

    /**
     * Minimum number of scored PACEs a student needs before they're ranked: roughly
     * one quarter's worth (PACES paces ~12 PACEs/subject/year, ~3 per quarter), so a
     * single lucky score can't put a student at the top of the list.
     */
    public const TOP_PERFORMER_MIN_SCORED = 3;

    /**
     * Minimum number of scored attempts a PACE needs before its fail rate counts,
     * so a single fluke result can't make a PACE look unusually hard.
     */
    public const HARDEST_PACE_MIN_SCORED = 3;

    public function __construct(private readonly DashboardService $breakdowns)
    {
    }

    /**
     * Everything the admin Home page shows, narrowed by the optional filters:
     *
     *  - branch_id: one branch's grade levels, students and catalog
     *  - teacher_id: the sections that teacher teaches in, and the records of their subjects
     *  - school_year_id: one school year (it belongs to an organization, so for the owner it implies that organization)
     *  - grade_level_id: that grade level's sections and students, and the records under its subjects
     *  - section_id: that section's students and their records
     *  - subject_id: the records filed under that subject, the students who take it and the sections that offer it
     *    (with a teacher: the sections they teach it in)
     *  - pace_id: the records of that PACE, the students who have it and their sections (and just that PACE in the PACE count)
     *
     * Every one of these narrows EVERY figure and list: the counts (teachers, grade levels, sections, students,
     * PACEs), the breakdowns, the rankings, the most active teachers and the recent students. With a school year,
     * class or subject filter the teachers counted are those teaching in the remaining sections, and with a class or
     * subject filter the PACE count is the PACEs used in the matching records.
     *
     * `totals` holds the unfiltered counts of the organization in scope (or of
     * the whole platform for the owner).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters = [], ?int $organizationId = null): array
    {
        $schoolYear = ($id = TeacherScope::id($filters['school_year_id'] ?? null)) !== null ? SchoolYear::query()->find($id) : null;
        $gradeLevelId = TeacherScope::id($filters['grade_level_id'] ?? null);
        $sectionId = TeacherScope::id($filters['section_id'] ?? null);
        $subjectId = TeacherScope::id($filters['subject_id'] ?? null);
        $paceId = TeacherScope::id($filters['pace_id'] ?? null);

        if ($schoolYear !== null && $organizationId === null) {
            $organizationId = (int) $schoolYear->organization_id;
        }

        $scope = TeacherScope::fromFilters($filters, $organizationId);

        $students = $scope->applyStudents(Student::query());
        $gradeLevels = $scope->applyGradeLevels(GradeLevel::query());
        $sections = $scope->sections();
        $paces = $scope->applyPaces(Pace::query());
        $assignments = $scope->applyAssignments(PaceAssignment::query());

        if ($schoolYear !== null) {
            $students->where('students.school_year_id', $schoolYear->id);
            $gradeLevels->where('grade_levels.school_year_id', $schoolYear->id);
            $sections->whereHas('gradeLevel', fn (Builder $level) => $level->where('school_year_id', $schoolYear->id));
            $assignments->where('pace_assignments.school_year', $schoolYear->name);
        }

        if ($gradeLevelId !== null) {
            $gradeLevels->where('grade_levels.id', $gradeLevelId);
            $sections->where('sections.grade_level_id', $gradeLevelId);
            $students->whereHas('section', fn (Builder $section) => $section->where('grade_level_id', $gradeLevelId));
            $assignments->whereIn(
                'pace_assignments.subject_id',
                Subject::query()->where('grade_level_id', $gradeLevelId)->select('id')
            );
        }

        if ($sectionId !== null) {
            $sections->where('sections.id', $sectionId);
            $students->where('students.section_id', $sectionId);
            $assignments->whereIn(
                'pace_assignments.student_id',
                Student::query()->where('section_id', $sectionId)->select('id')
            );
        }

        if ($subjectId !== null) {
            $assignments->where('pace_assignments.subject_id', $subjectId);
        }

        if ($paceId !== null) {
            $paces->where('paces.id', $paceId);
            $assignments->where('pace_assignments.pace_id', $paceId);
        }

        // The counts that do not come from the records follow the subject and PACE filters too: a subject keeps
        // the students who take it and the sections that offer it (with a teacher, the sections they teach it in);
        // a PACE keeps the students who have a (matching) record of it and their sections.
        if ($subjectId !== null) {
            $sections->when(
                $scope->isSingleTeacher(),
                fn (Builder $query) => $query->whereHas('teachingAssignments', fn (Builder $assignment) => $assignment
                    ->where('teaching_assignments.teacher_id', $scope->teacherId)
                    ->where('teaching_assignments.subject_id', $subjectId)),
                fn (Builder $query) => $query->whereHas('subjects', fn (Builder $subject) => $subject->where('subjects.id', $subjectId))
            );

            $students
                ->whereHas('subjects', fn (Builder $subject) => $subject->where('subjects.id', $subjectId))
                ->whereIn('students.section_id', (clone $sections)->select('sections.id'));
        }

        if ($paceId !== null) {
            $students->whereIn('students.id', (clone $assignments)->select('pace_assignments.student_id'));
            $sections->whereHas('students', fn (Builder $enrolled) => $enrolled
                ->whereIn('students.id', (clone $students)->select('students.id')));
        }

        // With a class or subject filter, the PACEs counted are the ones used in the matching records.
        if ($gradeLevelId !== null || $sectionId !== null || $subjectId !== null) {
            $paces->whereIn('paces.id', (clone $assignments)->select('pace_assignments.pace_id'));
        }

        // Grade levels (and so the "Students by grade level" chart) keep only those with a remaining section.
        if ($sectionId !== null || $subjectId !== null || $paceId !== null) {
            $gradeLevels->whereHas('sections', fn (Builder $section) => $section
                ->whereIn('sections.id', (clone $sections)->select('sections.id')));
        }

        // The teachers counted (and ranked below) are the ones teaching in what is left.
        $narrowed = $schoolYear !== null || $gradeLevelId !== null || $sectionId !== null || $subjectId !== null || $paceId !== null;
        $teachers = $scope->teachers();

        if ($narrowed) {
            $teachers->whereHas('teachingAssignments', fn (Builder $assignment) => $assignment
                ->whereIn('teaching_assignments.section_id', (clone $sections)->select('sections.id'))
                ->when($subjectId !== null, fn (Builder $query) => $query->where('teaching_assignments.subject_id', $subjectId)));
        }

        // The students counted per grade level are the ones left after every filter.
        $studentScope = $scope->isSingleTeacher() || $sectionId !== null || $subjectId !== null || $paceId !== null
            ? fn (Builder $query) => $query->whereIn('students.id', (clone $students)->select('students.id'))
            : null;

        return [
            'organization_id' => $organizationId,
            'branch_id' => $scope->branchId,
            'teacher_id' => $scope->teacherId,
            'scope' => $scope,
            'school_year' => $schoolYear,
            'teachers' => (clone $teachers)->count(),
            'grade_levels' => (clone $gradeLevels)->count(),
            'sections' => (clone $sections)->count(),
            'students' => (clone $students)->count(),
            'paces' => (clone $paces)->count(),
            'assignments' => (clone $assignments)->count(),
            'tested' => $this->breakdowns->testedCondition(clone $assignments)->count(),
            'scored' => (clone $assignments)->whereNotNull('score')->count(),
            'passed' => (clone $assignments)->where('score', '>=', PaceAssignment::PASSING_SCORE)->count(),
            'issued' => (clone $assignments)->issuedOnly()->count(),
            'assigned' => (clone $assignments)->assignedOnly()->count(),
            'retakes' => (clone $assignments)->where('retake', true)->count(),
            'failed_count' => (clone $assignments)->whereNotNull('score')->where('score', '<', PaceAssignment::PASSING_SCORE)->count(),
            'by_quarter' => $this->breakdowns->byQuarter(clone $assignments),
            'by_subject' => $this->breakdowns->bySubject(clone $assignments),
            'by_grade_level' => $this->breakdowns->byGradeLevel(clone $gradeLevels, $studentScope),
            'failed' => $this->failedAssignments(clone $assignments),
            'top_performers' => $this->topPerformers(clone $assignments),
            'score_distribution' => $this->breakdowns->scoreDistribution(clone $assignments),
            'hardest_paces' => $this->hardestPaces(clone $assignments),
            // Who is busiest and who enrolled last, over the same narrowed students, records and teachers.
            'teacher_activity' => $this->teacherActivity(
                $scope,
                $narrowed ? clone $teachers : null,
                $narrowed ? clone $students : null,
                $narrowed ? clone $assignments : null,
                $subjectId
            ),
            'recent_students' => $this->recentStudents($scope, clone $students),
            'totals' => $this->totals($organizationId),
        ];
    }

    /**
     * Headline counts across the organization (or the platform).
     *
     * @return array{organizations: int, branches: int, teachers: int, admins: int, school_years: int, grade_levels: int, sections: int, students: int, paces: int, assignments: int, tested: int, scored: int, passed: int}
     */
    public function totals(?int $organizationId = null): array
    {
        $scope = TeacherScope::forOrganization($organizationId);
        $assignments = $scope->applyAssignments(PaceAssignment::query());

        return [
            'organizations' => $organizationId === null ? \App\Models\Organization::query()->count() : 1,
            'branches' => \App\Models\Branch::query()->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))->count(),
            'teachers' => $scope->teachers()->count(),
            'admins' => $scope->members()->role(Role::Admin)->count(),
            'school_years' => SchoolYear::query()->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))->count(),
            'grade_levels' => $scope->applyGradeLevels(GradeLevel::query())->count(),
            'sections' => $scope->sections()->count(),
            'students' => $scope->applyStudents(Student::query())->count(),
            'paces' => $scope->applyPaces(Pace::query())->count(),
            'assignments' => (clone $assignments)->count(),
            'tested' => $this->breakdowns->testedCondition(clone $assignments)->count(),
            'scored' => (clone $assignments)->whereNotNull('score')->count(),
            'passed' => (clone $assignments)->where('score', '>=', PaceAssignment::PASSING_SCORE)->count(),
        ];
    }

    /**
     * Each teacher's student and PACE-record counts, busiest (by records) first:
     * the students of the sections they teach, and the records of the subjects
     * they are in charge of, broken down into tested / issued-only / assigned-only.
     *
     * With the dashboard's filters, `$teachers`, `$students` and `$assignments` are the narrowed queries (the
     * teachers teaching in the remaining sections, their students and the records left) and `$subjectId` the
     * subject filter, so the ranking follows every filter; without them it covers the whole scope.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\User>|null  $teachers
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Student>|null  $students
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\PaceAssignment>|null  $assignments
     * @return \Illuminate\Support\Collection<int, array{id: int, name: string, branch_name: string, organization_name: string, students_count: int, assignments_count: int, tested_count: int, issued_count: int, assigned_count: int}>
     */
    public function teacherActivity(TeacherScope|int|null $scope = null, ?Builder $teachers = null, ?Builder $students = null, ?Builder $assignments = null, ?int $subjectId = null): \Illuminate\Support\Collection
    {
        $scope = $scope instanceof TeacherScope ? $scope : TeacherScope::forOrganization($scope);

        $teachers = ($teachers ?? $scope->teachers())->with(['branch', 'organization'])->get();

        if ($teachers->isEmpty()) {
            return collect();
        }

        $ids = $teachers->pluck('id')->all();

        $students = DB::table('teaching_assignments')
            ->join('students', 'students.section_id', '=', 'teaching_assignments.section_id')
            ->join('student_subjects', function ($join): void {
                $join->on('student_subjects.student_id', '=', 'students.id')
                    ->on('student_subjects.subject_id', '=', 'teaching_assignments.subject_id');
            })
            ->whereIn('teaching_assignments.teacher_id', $ids)
            ->when($subjectId !== null, fn ($query) => $query->where('teaching_assignments.subject_id', $subjectId))
            ->when($students !== null, fn ($query) => $query->whereIn('students.id', (clone $students)->select('students.id')))
            ->groupBy('teaching_assignments.teacher_id')
            ->selectRaw('teaching_assignments.teacher_id as teacher_id, count(distinct students.id) as total')
            ->pluck('total', 'teacher_id');

        $records = DB::table('pace_assignments')
            ->join('students', 'students.id', '=', 'pace_assignments.student_id')
            ->join('teaching_assignments', function ($join): void {
                $join->on('teaching_assignments.section_id', '=', 'students.section_id')
                    ->on('teaching_assignments.subject_id', '=', 'pace_assignments.subject_id');
            })
            ->whereIn('teaching_assignments.teacher_id', $ids)
            ->when($assignments !== null, fn ($query) => $query->whereIn('pace_assignments.id', (clone $assignments)->select('pace_assignments.id')))
            ->groupBy('teaching_assignments.teacher_id')
            ->selectRaw('teaching_assignments.teacher_id as teacher_id, count(*) as total')
            ->selectRaw('sum(case when pace_assignments.date_tested is not null or pace_assignments.score is not null then 1 else 0 end) as tested')
            ->selectRaw('sum(case when pace_assignments.date_tested is null and pace_assignments.score is null and pace_assignments.issued_date is not null then 1 else 0 end) as issued')
            ->get()
            ->keyBy('teacher_id');

        return $teachers
            ->map(function (User $teacher) use ($students, $records): array {
                $row = $records->get($teacher->id);
                $total = (int) ($row->total ?? 0);
                $tested = (int) ($row->tested ?? 0);
                $issued = (int) ($row->issued ?? 0);

                return [
                    'id' => $teacher->id,
                    'name' => $teacher->name,
                    'branch_name' => $teacher->branch?->name ?? '',
                    'organization_name' => $teacher->organization?->name ?? '',
                    'students_count' => (int) ($students[$teacher->id] ?? 0),
                    'assignments_count' => $total,
                    'tested_count' => $tested,
                    'issued_count' => $issued,
                    'assigned_count' => $total - $tested - $issued,
                ];
            })
            ->sortBy([['assignments_count', 'desc'], ['students_count', 'desc'], ['name', 'asc']])
            ->take(self::TEACHER_ACTIVITY_LIMIT)
            ->values();
    }

    /**
     * The most recently enrolled students in scope (or among the given, already narrowed, students).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Student>|null  $students
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Student>
     */
    public function recentStudents(TeacherScope|int|null $scope = null, ?Builder $students = null): Collection
    {
        $scope = $scope instanceof TeacherScope ? $scope : TeacherScope::forOrganization($scope);

        return ($students ?? $scope->applyStudents(Student::query()))
            ->with(['section.gradeLevel.branch.organization', 'creator'])
            ->orderByDesc('students.created_at')
            ->orderByDesc('students.id')
            ->limit(self::RECENT_LIMIT)
            ->get();
    }

    /**
     * The most recently registered members (teachers and admins alike).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\User>
     */
    public function recentUsers(TeacherScope|int|null $scope = null): Collection
    {
        $scope = $scope instanceof TeacherScope ? $scope : TeacherScope::forOrganization($scope);

        return $scope->members()
            ->with(['branch', 'organization'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_LIMIT)
            ->get();
    }

    /**
     * The most recent PACEs in scope that scored below a pass or are flagged as a
     * retake, most recently tested first (each row says which of the two it is,
     * and who teaches that subject to the student's section). Capped at
     * FAILED_LIMIT so the dashboard stays lightweight.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\PaceAssignment>  $assignments
     * @return array<int, array{id: int, student_id: int, student_name: string, teacher_name: string, subject_name: string, pace_number: string, pace_name: string, score: ?float, failed: bool, retake: bool, quarter: int, school_year: string, date_tested: ?string}>
     */
    public function failedAssignments(Builder $assignments): array
    {
        return $assignments
            ->with(['student.section.teachingAssignments.teacher', 'subject', 'pace'])
            ->where(function (Builder $query): void {
                $query->where('retake', true)
                    ->orWhere(function (Builder $failed): void {
                        $failed->whereNotNull('score')->where('score', '<', PaceAssignment::PASSING_SCORE);
                    });
            })
            ->orderByDesc('date_tested')
            ->orderByDesc('pace_assignments.id')
            ->limit(self::FAILED_LIMIT)
            ->get()
            ->map(fn (PaceAssignment $item) => [
                'id' => $item->id,
                'student_id' => $item->student_id,
                'student_name' => $item->student?->fullName ?? '',
                'teacher_name' => $item->student?->section?->teachingAssignments
                    ->firstWhere('subject_id', $item->subject_id)?->teacher?->name ?? '',
                'subject_name' => $item->subject?->name ?? '',
                'pace_number' => $item->pace?->number ?? '',
                'pace_name' => $item->pace?->name ?? '',
                'score' => $item->score === null ? null : (float) $item->score,
                'failed' => $item->score !== null && (float) $item->score < PaceAssignment::PASSING_SCORE,
                'retake' => (bool) $item->retake,
                'quarter' => $item->quarter,
                'school_year' => $item->school_year,
                'date_tested' => $item->date_tested?->toDateString(),
            ])
            ->all();
    }

    /**
     * Students in scope ranked by average score, highest first, among those with at
     * least TOP_PERFORMER_MIN_SCORED scored PACEs. The average spans every record of
     * the student in scope: assigned / issued PACEs without a score count as 0.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\PaceAssignment>  $assignments
     * @return array<int, array{student_id: int, student_name: string, section_name: string, grade_level_name: string, average_score: float, scored_count: int, passed_count: int, failed_count: int, retake_count: int}>
     */
    public function topPerformers(Builder $assignments): array
    {
        $passing = PaceAssignment::PASSING_SCORE;

        $rows = $assignments
            ->selectRaw('pace_assignments.student_id as student_id')
            ->selectRaw('avg(coalesce(pace_assignments.score, 0)) as average_score')
            ->selectRaw('sum(case when pace_assignments.score is not null then 1 else 0 end) as scored_count')
            ->selectRaw("sum(case when pace_assignments.score is not null and pace_assignments.score >= {$passing} then 1 else 0 end) as passed_count")
            ->selectRaw("sum(case when pace_assignments.score is not null and pace_assignments.score < {$passing} then 1 else 0 end) as failed_count")
            ->selectRaw('sum(case when pace_assignments.retake = 1 then 1 else 0 end) as retake_count')
            ->groupBy('pace_assignments.student_id')
            ->having('scored_count', '>=', self::TOP_PERFORMER_MIN_SCORED)
            ->orderByDesc('average_score')
            ->orderByDesc('scored_count')
            ->limit(self::TOP_PERFORMER_LIMIT)
            ->get();

        $students = Student::query()
            ->with('section.gradeLevel')
            ->whereIn('id', $rows->pluck('student_id'))
            ->get()
            ->keyBy('id');

        return $rows->map(function ($row) use ($students) {
            $student = $students->get((int) $row->student_id);

            return [
                'student_id' => (int) $row->student_id,
                'student_name' => $student?->fullName ?? '',
                'section_name' => $student?->section?->name ?? '',
                'grade_level_name' => $student?->section?->gradeLevel?->name ?? '',
                'average_score' => (float) $row->average_score,
                'scored_count' => (int) $row->scored_count,
                'passed_count' => (int) $row->passed_count,
                'failed_count' => (int) $row->failed_count,
                'retake_count' => (int) $row->retake_count,
            ];
        })->all();
    }

    /**
     * Every PACE in scope with at least one fail or one retake, among those with at
     * least HARDEST_PACE_MIN_SCORED scored attempts, ranked by fail rate, then by
     * retake rate (PACEs flagged as a retake out of every record of that PACE),
     * hardest first. Not capped: the page lists them all.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\PaceAssignment>  $assignments
     * @return array<int, array{pace_id: int, pace_number: string, pace_name: string, total_count: int, scored_count: int, failed_count: int, fail_rate: float, retake_count: int, retake_rate: float}>
     */
    public function hardestPaces(Builder $assignments): array
    {
        $passing = PaceAssignment::PASSING_SCORE;

        $rows = $assignments
            ->selectRaw('pace_assignments.pace_id as pace_id')
            ->selectRaw('count(*) as total_count')
            ->selectRaw('sum(case when score is not null then 1 else 0 end) as scored_count')
            ->selectRaw("sum(case when score is not null and score < {$passing} then 1 else 0 end) as failed_count")
            ->selectRaw('sum(case when retake = 1 then 1 else 0 end) as retake_count')
            ->groupBy('pace_assignments.pace_id')
            ->having('scored_count', '>=', self::HARDEST_PACE_MIN_SCORED)
            ->havingRaw('(failed_count > 0 or retake_count > 0)')
            ->get();

        $paces = Pace::query()
            ->with('branch.organization')
            ->whereIn('id', $rows->pluck('pace_id'))
            ->get()
            ->keyBy('id');

        return $rows
            ->map(function ($row) use ($paces) {
                $pace = $paces->get((int) $row->pace_id);
                $total = (int) $row->total_count;
                $scored = (int) $row->scored_count;
                $failed = (int) $row->failed_count;
                $retakes = (int) $row->retake_count;

                return [
                    'pace_id' => (int) $row->pace_id,
                    'pace_number' => $pace?->number ?? '',
                    'pace_name' => $pace?->name ?? '',
                    'branch_name' => $pace?->branch?->name ?? '',
                    'organization_name' => $pace?->branch?->organization?->name ?? '',
                    'total_count' => $total,
                    'scored_count' => $scored,
                    'failed_count' => $failed,
                    'fail_rate' => $scored > 0 ? round($failed / $scored * 100, 1) : 0.0,
                    'retake_count' => $retakes,
                    'retake_rate' => $total > 0 ? round($retakes / $total * 100, 1) : 0.0,
                ];
            })
            ->sortBy([
                ['fail_rate', 'desc'],
                ['retake_rate', 'desc'],
                ['scored_count', 'desc'],
            ])
            ->values()
            ->all();
    }

    /**
     * Student and grade-level counts per school year (of the organization in scope), newest first.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\SchoolYear>
     */
    public function schoolYears(?int $organizationId = null): Collection
    {
        return SchoolYear::query()
            ->with('organization')
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->withCount(['students', 'gradeLevels'])
            ->orderByDesc('name')
            ->orderByDesc('id')
            ->limit(self::SCHOOL_YEAR_LIMIT)
            ->get();
    }
}
