<?php

namespace App\Services\Admin;

use App\Enums\Role;
use App\Models\GradeLevel;
use App\Models\Pace;
use App\Models\PaceAssignment;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read-only analytics for the admin Home page: every teacher's records in
 * scope combined, optionally narrowed to one branch, teacher, school year,
 * grade level and subject. An organization admin's scope is their own
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
     *  - branch_id: the teachers of one branch
     *  - teacher_id: one teacher's students, grade levels, PACEs and records
     *  - school_year_id: one school year (it belongs to an organization, so for the owner it implies that organization)
     *  - grade_level_id: that grade level's students and the records under its subjects
     *  - subject_id: only the records filed under that subject
     *  - pace_id: only the records of that PACE (and just that PACE in the PACE count)
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
        $subjectId = TeacherScope::id($filters['subject_id'] ?? null);
        $paceId = TeacherScope::id($filters['pace_id'] ?? null);

        if ($schoolYear !== null && $organizationId === null) {
            $organizationId = (int) $schoolYear->organization_id;
        }

        $scope = TeacherScope::fromFilters($filters, $organizationId);

        $students = $scope->applyOwner(Student::query(), 'students.created_by');
        $gradeLevels = $scope->applyOwner(GradeLevel::query(), 'grade_levels.user_id');
        $paces = $scope->applyOwner(Pace::query(), 'paces.created_by');
        $assignments = $scope->applyAssignments(PaceAssignment::query());

        if ($schoolYear !== null) {
            $students->where('students.school_year_id', $schoolYear->id);
            $gradeLevels->where('grade_levels.school_year_id', $schoolYear->id);
            $assignments->where('pace_assignments.school_year', $schoolYear->name);
        }

        if ($gradeLevelId !== null) {
            $gradeLevels->where('grade_levels.id', $gradeLevelId);
            $students->where('students.grade_level_id', $gradeLevelId);
            $assignments->whereIn(
                'pace_assignments.subject_id',
                Subject::query()->where('grade_level_id', $gradeLevelId)->select('id')
            );
        }

        if ($subjectId !== null) {
            $assignments->where('pace_assignments.subject_id', $subjectId);
        }

        if ($paceId !== null) {
            $paces->where('paces.id', $paceId);
            $assignments->where('pace_assignments.pace_id', $paceId);
        }

        return [
            'organization_id' => $organizationId,
            'branch_id' => $scope->branchId,
            'teacher_id' => $scope->teacherId,
            'scope' => $scope,
            'school_year' => $schoolYear,
            'teachers' => $scope->teachers()->count(),
            'grade_levels' => (clone $gradeLevels)->count(),
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
            'by_grade_level' => $this->byGradeLevel(clone $gradeLevels, ! $scope->isSingleTeacher()),
            'failed' => $this->failedAssignments(clone $assignments),
            'top_performers' => $this->topPerformers(clone $assignments),
            'score_distribution' => $this->breakdowns->scoreDistribution(clone $assignments),
            'hardest_paces' => $this->hardestPaces(clone $assignments),
            'totals' => $this->totals($organizationId),
        ];
    }

    /**
     * Headline counts across every teacher of the organization (or of the platform).
     *
     * @return array{organizations: int, branches: int, teachers: int, admins: int, school_years: int, grade_levels: int, students: int, paces: int, assignments: int, tested: int, scored: int, passed: int}
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
            'grade_levels' => $scope->applyOwner(GradeLevel::query(), 'grade_levels.user_id')->count(),
            'students' => $scope->applyOwner(Student::query(), 'students.created_by')->count(),
            'paces' => $scope->applyOwner(Pace::query(), 'paces.created_by')->count(),
            'assignments' => (clone $assignments)->count(),
            'tested' => $this->breakdowns->testedCondition(clone $assignments)->count(),
            'scored' => (clone $assignments)->whereNotNull('score')->count(),
            'passed' => (clone $assignments)->where('score', '>=', PaceAssignment::PASSING_SCORE)->count(),
        ];
    }

    /**
     * Student counts per grade level (total plus boys / girls). With several
     * teachers in scope the teacher's name rides along, since different teachers
     * register same-named grade levels.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\GradeLevel>  $gradeLevels
     * @return array<int, array{id: int, name: string, total: int, male: int, female: int, teacher_name: string}>
     */
    protected function byGradeLevel(Builder $gradeLevels, bool $withTeacher): array
    {
        $rows = $this->breakdowns->byGradeLevel(clone $gradeLevels);

        $teachers = $withTeacher
            ? GradeLevel::query()
                ->whereIn('id', array_column($rows, 'id'))
                ->with('user')
                ->get()
                ->mapWithKeys(fn (GradeLevel $level) => [$level->id => $level->user?->name ?? ''])
            : collect();

        return array_map(fn (array $row) => $row + ['teacher_name' => (string) ($teachers[$row['id']] ?? '')], $rows);
    }

    /**
     * Each teacher's student and PACE-assignment counts, busiest (by assignments) first.
     * Assignment counts are broken down into tested / issued-only / assigned-only.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\User>
     */
    public function teacherActivity(TeacherScope|int|null $scope = null): Collection
    {
        $scope = $scope instanceof TeacherScope ? $scope : TeacherScope::forOrganization($scope);

        return $scope->teachers()
            ->with('branch')
            ->withCount([
                'students',
                'assignments',
                'assignments as tested_count' => function ($query) {
                    $query->where(function ($inner) {
                        $inner->whereNotNull('pace_assignments.date_tested')
                              ->orWhereNotNull('pace_assignments.score');
                    });
                },
                'assignments as issued_count' => function ($query) {
                    $query->whereNull('pace_assignments.date_tested')
                          ->whereNull('pace_assignments.score')
                          ->whereNotNull('pace_assignments.issued_date');
                },
            ])
            ->orderByDesc('assignments_count')
            ->orderByDesc('students_count')
            ->limit(self::TEACHER_ACTIVITY_LIMIT)
            ->get();
    }

    /**
     * The most recently registered students of the teachers in scope.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Student>
     */
    public function recentStudents(TeacherScope|int|null $scope = null): Collection
    {
        $scope = $scope instanceof TeacherScope ? $scope : TeacherScope::forOrganization($scope);

        return $scope
            ->applyOwner(Student::query(), 'students.created_by')
            ->with(['gradeLevel', 'creator'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
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
     * retake, most recently tested first (each row says which of the two it is and
     * who the teacher is). Capped at FAILED_LIMIT so the dashboard stays lightweight.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\PaceAssignment>  $assignments
     * @return array<int, array{id: int, student_id: int, student_name: string, teacher_name: string, subject_name: string, pace_number: string, pace_name: string, score: ?float, failed: bool, retake: bool, quarter: int, school_year: string, date_tested: ?string}>
     */
    public function failedAssignments(Builder $assignments): array
    {
        return $assignments
            ->with(['student.creator', 'subject', 'pace'])
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
                'teacher_name' => $item->student?->creator?->name ?? '',
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
     * @return array<int, array{student_id: int, student_name: string, teacher_name: string, average_score: float, scored_count: int, passed_count: int, failed_count: int, retake_count: int}>
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
            ->with('creator')
            ->whereIn('id', $rows->pluck('student_id'))
            ->get()
            ->keyBy('id');

        return $rows->map(function ($row) use ($students) {
            $student = $students->get((int) $row->student_id);

            return [
                'student_id' => (int) $row->student_id,
                'student_name' => $student?->fullName ?? '',
                'teacher_name' => $student?->creator?->name ?? '',
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
