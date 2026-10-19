<?php

namespace App\Services;

use App\Enums\Quarter;
use App\Enums\Sex;
use App\Models\GradeLevel;
use App\Models\Pace;
use App\Models\PaceAssignment;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class DashboardService
{
    /**
     * Number of students shown in the "recent students" list.
     */
    public const RECENT_LIMIT = 5;

    /**
     * Number of records shown in the "failed PACEs" list.
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
     * Headline counts, breakdowns and recent students for one user, limited to a
     * school year when one is given:
     *
     *  - grade_levels / students / by_grade_level: registered under that school year
     *  - assignments / tested / scored / passed / by_quarter / by_subject: PACE records filed under that school year
     *  - paces: a PACE is not tied to a school year, so this is always the all-time count
     *  - year_trend: always every school year the user has, regardless of the selection
     *
     * `totals` always holds the user's all-time counts (used by the setup checklist).
     *
     * Optional filters narrow the figures further:
     *  - grade_level_id: that grade level's students and the records under its subjects
     *  - subject_id: only the records filed under that subject
     *  - pace_id: only the records of that PACE (and just that PACE in the PACE count)
     *
     * @param  array{grade_level_id?: int|string|null, subject_id?: int|string|null, pace_id?: int|string|null}  $filters
     *
     * @return array{
     *     school_year: ?\App\Models\SchoolYear,
     *     grade_levels: int, students: int, paces: int, assignments: int, tested: int, scored: int, passed: int,
     *     issued: int, assigned: int, retakes: int, failed_count: int,
     *     recent_students: \Illuminate\Database\Eloquent\Collection<int, \App\Models\Student>,
     *     by_quarter: array<int, array{quarter: int, total: int, tested: int, issued: int, assigned: int, passed: int, failed: int, retakes: int}>,
     *     by_subject: array<int, array{id: int, name: string, grade_level_name: string, total: int, tested: int, issued: int, assigned: int, average_score: ?float}>,
     *     by_grade_level: array<int, array{id: int, name: string, total: int, male: int, female: int}>,
     *     year_trend: array<int, array{school_year: string, assignments: int, tested: int}>,
     *     failed: array<int, array{id: int, student_id: int, student_name: string, subject_name: string, pace_number: string, pace_name: string, score: ?float, failed: bool, retake: bool, quarter: int, school_year: string, date_tested: ?string}>,
     *     top_performers: array<int, array{student_id: int, student_name: string, average_score: float, scored_count: int, passed_count: int, failed_count: int, retake_count: int}>,
     *     score_distribution: array<int, array{label: string, total: int}>,
     *     totals: array{school_years: int, grade_levels: int, students: int, paces: int, assignments: int},
     * }
     */
    public function summary(User $user, ?SchoolYear $schoolYear = null, array $filters = []): array
    {
        $students = Student::query()->ownedBy($user);
        $paces = Pace::query()->ownedBy($user);
        $assignments = PaceAssignment::query()->ownedBy($user);

        if ($schoolYear !== null) {
            $students->where('students.school_year_id', $schoolYear->id);
            $assignments->where('pace_assignments.school_year', $schoolYear->name);
        }

        $gradeLevels = GradeLevel::query()
            ->ownedBy($user)
            ->when($schoolYear !== null, fn ($query) => $query->where('grade_levels.school_year_id', $schoolYear->id));

        $gradeLevelId = $this->filterId($filters, 'grade_level_id');
        $subjectId = $this->filterId($filters, 'subject_id');
        $paceId = $this->filterId($filters, 'pace_id');

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
            'school_year' => $schoolYear,
            'grade_levels' => (clone $gradeLevels)->count(),
            'students' => (clone $students)->count(),
            'paces' => (clone $paces)->count(),
            'assignments' => (clone $assignments)->count(),
            'tested' => $this->testedCondition(clone $assignments)->count(),
            'scored' => (clone $assignments)->whereNotNull('score')->count(),
            'passed' => (clone $assignments)->where('score', '>=', PaceAssignment::PASSING_SCORE)->count(),
            // Issued-only and assigned-only (tested + issued + assigned = assignments).
            'issued' => (clone $assignments)->issuedOnly()->count(),
            'assigned' => (clone $assignments)->assignedOnly()->count(),
            'retakes' => (clone $assignments)->where('retake', true)->count(),
            // Scored below a pass (passed + failed_count = scored).
            'failed_count' => (clone $assignments)->whereNotNull('score')->where('score', '<', PaceAssignment::PASSING_SCORE)->count(),
            'recent_students' => $this->recentStudents($students),
            'by_quarter' => $this->byQuarter(clone $assignments),
            'by_subject' => $this->bySubject(clone $assignments),
            'by_grade_level' => $this->byGradeLevel(clone $gradeLevels),
            'year_trend' => $this->yearTrend($user),
            'failed' => $this->failedAssignments(clone $assignments),
            'top_performers' => $this->topPerformers(clone $assignments),
            'score_distribution' => $this->scoreDistribution(clone $assignments),
            'totals' => [
                'school_years' => SchoolYear::query()->ownedBy($user)->count(),
                'grade_levels' => GradeLevel::query()->ownedBy($user)->count(),
                'students' => Student::query()->ownedBy($user)->count(),
                'paces' => Pace::query()->ownedBy($user)->count(),
                'assignments' => PaceAssignment::query()->ownedBy($user)->count(),
            ],
        ];
    }

    /**
     * A positive integer id from the filters, or null when absent / empty.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function filterId(array $filters, string $key): ?int
    {
        $value = $filters[$key] ?? null;

        if ($value === null || $value === '' || ! is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }

    /**
     * The latest registered students (within the given scope) with their grade level.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Student>  $students
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Student>
     */
    protected function recentStudents(Builder $students): Collection
    {
        return (clone $students)
            ->with(['gradeLevel', 'schoolYear'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_LIMIT)
            ->get();
    }

    /**
     * Narrow a PaceAssignment query to records with a test completed: either a
     * test date or a score recorded (a test can be scored later than it's taken).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\PaceAssignment>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\PaceAssignment>
     */
    public function testedCondition(Builder $query): Builder
    {
        return $query->where(function ($query) {
            $query->whereNotNull('date_tested')
                  ->orWhereNotNull('score');
        });
    }

    /**
     * Assignment counts per quarter (1st to 4th), broken down into tested / issued-only /
     * assigned-only plus passed / failed (of the scored ones) and retakes, with every
     * quarter present even when it has no records.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\PaceAssignment>  $assignments
     * @return array<int, array{quarter: int, total: int, tested: int, issued: int, assigned: int, passed: int, failed: int, retakes: int}>
     */
    public function byQuarter(Builder $assignments): array
    {
        $passing = PaceAssignment::PASSING_SCORE;

        $rows = $assignments
            ->selectRaw('pace_assignments.quarter as quarter')
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when date_tested is not null or score is not null then 1 else 0 end) as tested')
            ->selectRaw('sum(case when date_tested is null and score is null and issued_date is not null then 1 else 0 end) as issued')
            ->selectRaw("sum(case when score is not null and score >= {$passing} then 1 else 0 end) as passed")
            ->selectRaw("sum(case when score is not null and score < {$passing} then 1 else 0 end) as failed")
            ->selectRaw('sum(case when retake = 1 then 1 else 0 end) as retakes')
            ->groupBy('pace_assignments.quarter')
            ->get()
            ->keyBy('quarter');

        return collect(Quarter::values())->map(function (int $quarter) use ($rows) {
            $row = $rows->get($quarter);
            $total = (int) ($row->total ?? 0);
            $tested = (int) ($row->tested ?? 0);
            $issued = (int) ($row->issued ?? 0);

            return [
                'quarter' => $quarter,
                'total' => $total,
                'tested' => $tested,
                'issued' => $issued,
                'assigned' => $total - $tested - $issued,
                'passed' => (int) ($row->passed ?? 0),
                'failed' => (int) ($row->failed ?? 0),
                'retakes' => (int) ($row->retakes ?? 0),
            ];
        })->all();
    }

    /**
     * Assignment counts per subject, most-assigned first, broken down into tested /
     * issued-only / assigned-only. The grade level name rides along so same-named
     * subjects in different grade levels don't look identical.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\PaceAssignment>  $assignments
     * @return array<int, array{id: int, name: string, grade_level_name: string, total: int, tested: int, issued: int, assigned: int, average_score: ?float}>
     */
    public function bySubject(Builder $assignments): array
    {
        return $assignments
            ->join('subjects', 'subjects.id', '=', 'pace_assignments.subject_id')
            ->join('grade_levels', 'grade_levels.id', '=', 'subjects.grade_level_id')
            ->selectRaw('subjects.id as id, subjects.name as name, grade_levels.name as grade_level_name, count(*) as total')
            ->selectRaw('sum(case when date_tested is not null or score is not null then 1 else 0 end) as tested')
            ->selectRaw('sum(case when date_tested is null and score is null and issued_date is not null then 1 else 0 end) as issued')
            // Average over every record: assigned / issued PACEs without a score count as 0.
            ->selectRaw('avg(coalesce(pace_assignments.score, 0)) as average_score')
            ->groupBy('subjects.id', 'subjects.name', 'grade_levels.name')
            ->orderByDesc('total')
            ->get()
            ->map(function ($row) {
                $total = (int) $row->total;
                $tested = (int) $row->tested;
                $issued = (int) $row->issued;

                return [
                    'id' => (int) $row->id,
                    'name' => $row->name,
                    'grade_level_name' => $row->grade_level_name,
                    'total' => $total,
                    'tested' => $tested,
                    'issued' => $issued,
                    'assigned' => $total - $tested - $issued,
                    'average_score' => $row->average_score !== null ? (float) $row->average_score : null,
                ];
            })
            ->all();
    }

    /**
     * Student counts per grade level (total plus boys / girls), in grade-level display order.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\GradeLevel>  $gradeLevels
     * @return array<int, array{id: int, name: string, total: int, male: int, female: int}>
     */
    public function byGradeLevel(Builder $gradeLevels): array
    {
        return $gradeLevels
            ->withCount([
                'students',
                'students as male_count' => fn (Builder $query) => $query->where('sex', Sex::Male->value),
                'students as female_count' => fn (Builder $query) => $query->where('sex', Sex::Female->value),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (GradeLevel $level) => [
                'id' => $level->id,
                'name' => $level->name,
                'total' => (int) $level->students_count,
                'male' => (int) $level->male_count,
                'female' => (int) $level->female_count,
            ])
            ->all();
    }

    /**
     * Assignment and tested counts per school year (oldest first), across every
     * year the user has registered — not limited by the selected school year.
     * Used for the "all school years" trend chart.
     *
     * @return array<int, array{school_year: string, assignments: int, tested: int}>
     */
    protected function yearTrend(User $user): array
    {
        return SchoolYear::query()
            ->ownedBy($user)
            ->orderBy('name')
            ->get()
            ->map(fn (SchoolYear $year) => [
                'school_year' => $year->name,
                'assignments' => $year->assignmentsQuery($user)->count(),
                'tested' => $this->testedCondition($year->assignmentsQuery($user))->count(),
            ])
            ->all();
    }

    /**
     * The most recent PACEs that scored below a pass or are flagged as a retake,
     * most recently tested first (each row says which of the two it is).
     * Capped at FAILED_LIMIT so an active teacher's whole history isn't shipped at once.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\PaceAssignment>  $assignments
     * @return array<int, array{id: int, student_id: int, student_name: string, subject_name: string, pace_number: string, pace_name: string, score: ?float, failed: bool, retake: bool, quarter: int, school_year: string, date_tested: ?string}>
     */
    protected function failedAssignments(Builder $assignments): array
    {
        return $assignments
            ->with(['student', 'subject', 'pace'])
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
     * Students ranked by average score, highest first, among those with at least
     * TOP_PERFORMER_MIN_SCORED scored PACEs (so one lucky score can't top the list).
     * The average spans every record of the student in the period: assigned /
     * issued PACEs without a score count as 0.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\PaceAssignment>  $assignments
     * @return array<int, array{student_id: int, student_name: string, average_score: float, scored_count: int, passed_count: int, failed_count: int, retake_count: int}>
     */
    protected function topPerformers(Builder $assignments): array
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
            ->whereIn('id', $rows->pluck('student_id'))
            ->get()
            ->keyBy('id');

        return $rows->map(fn ($row) => [
            'student_id' => (int) $row->student_id,
            'student_name' => $students->get((int) $row->student_id)?->fullName ?? '',
            'average_score' => (float) $row->average_score,
            'scored_count' => (int) $row->scored_count,
            'passed_count' => (int) $row->passed_count,
            'failed_count' => (int) $row->failed_count,
            'retake_count' => (int) $row->retake_count,
        ])->all();
    }

    /**
     * Scored PACEs grouped into score bands (90-100 down to below 60), highest first.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\PaceAssignment>  $assignments
     * @return array<int, array{label: string, total: int}>
     */
    public function scoreDistribution(Builder $assignments): array
    {
        $row = $assignments
            ->whereNotNull('score')
            ->selectRaw('sum(case when score >= 90 then 1 else 0 end) as band_90')
            ->selectRaw('sum(case when score >= 80 and score < 90 then 1 else 0 end) as band_80')
            ->selectRaw('sum(case when score >= 70 and score < 80 then 1 else 0 end) as band_70')
            ->selectRaw('sum(case when score >= 60 and score < 70 then 1 else 0 end) as band_60')
            ->selectRaw('sum(case when score < 60 then 1 else 0 end) as band_below')
            ->first();

        return [
            ['label' => '90-100', 'total' => (int) ($row->band_90 ?? 0)],
            ['label' => '80-89', 'total' => (int) ($row->band_80 ?? 0)],
            ['label' => '70-79', 'total' => (int) ($row->band_70 ?? 0)],
            ['label' => '60-69', 'total' => (int) ($row->band_60 ?? 0)],
            ['label' => 'Below 60', 'total' => (int) ($row->band_below ?? 0)],
        ];
    }
}
