<?php

namespace App\Services;

use App\Models\GradeLevel;
use App\Models\PaceAssignment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class StudentService
{
    public function __construct(
        private readonly PaceAssignmentService $assignments,
        private readonly SchoolYearService $schoolYears,
        private readonly SubscriptionService $subscriptions,
    ) {
    }

    /**
     * Master list of the user's students with their grade level, school year and PACE count.
     *
     * Supported filters: search (matches first/middle/last name), grade_level_id, school_year_id.
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Student>
     */
    public function list(User $user, array $filters = []): Collection
    {
        $query = Student::query()
            ->ownedBy($user)
            ->with(['gradeLevel', 'schoolYear'])
            ->withCount($this->enrolledStatusCounts())
            ->withSum(['assignments as score_sum' => $this->enrolledYearOnly()], 'score')
            ->orderBy('last_name')
            ->orderBy('first_name');

        $this->applyFilters($query, $filters);

        return $query->get();
    }

    /**
     * Register a new student owned by the given user. The student is filed
     * under the school year of their grade level (falling back to the user's
     * current school year for a grade level without one).
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $creator): Student
    {
        $this->subscriptions->assertCanAddStudent($creator->organization);

        $data['school_year_id'] = $this->schoolYearFor($data, $creator);

        $student = Student::create($data + ['created_by' => $creator->id]);

        return $this->fresh($student);
    }

    /**
     * Update a student's details; the school year follows the grade level.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Student $student, array $data): Student
    {
        $data['school_year_id'] = $this->schoolYearFor(
            $data + ['grade_level_id' => $student->grade_level_id, 'school_year_id' => $student->school_year_id],
            $student->creator
        );

        $student->fill($data);
        $student->save();

        return $this->fresh($student);
    }

    /**
     * The school year a student with these attributes belongs to.
     *
     * @param  array<string, mixed>  $data
     */
    protected function schoolYearFor(array $data, ?User $owner): ?int
    {
        $gradeLevelId = $data['grade_level_id'] ?? null;

        if ($gradeLevelId !== null && $gradeLevelId !== '') {
            $fromGradeLevel = GradeLevel::query()->whereKey((int) $gradeLevelId)->value('school_year_id');

            if ($fromGradeLevel !== null) {
                return (int) $fromGradeLevel;
            }
        }

        if (! empty($data['school_year_id'])) {
            return (int) $data['school_year_id'];
        }

        return $owner === null ? null : $this->schoolYears->currentFor($owner)?->id;
    }

    /**
     * Remove a student together with their PACE records (cascaded by the database).
     */
    public function delete(Student $student): void
    {
        $student->delete();
    }

    /**
     * Load everything the student detail view needs: the grade level with its
     * subjects, the school year, and every assignment (with pace and subject) in display order.
     */
    public function detail(Student $student): Student
    {
        return $student->load([
            'gradeLevel.subjects',
            'gradeLevel.schoolYear',
            'schoolYear',
            'assignments' => function ($query): void {
                $query->with(['pace', 'subject']);
                $this->assignments->applyDefaultOrder($query);
            },
        ]);
    }

    /**
     * Reload the relations and counts the list/detail resources expose.
     */
    protected function fresh(Student $student): Student
    {
        return $student
            ->load(['gradeLevel', 'schoolYear'])
            ->loadCount($this->enrolledStatusCounts())
            ->loadSum(['assignments as score_sum' => $this->enrolledYearOnly()], 'score');
    }

    /**
     * Like PaceAssignment::statusCounts() (assignments_count plus tested / issued /
     * assigned counts) but limited to the student's enrolled school year, so the
     * list's PACE records column and average only reflect the current year.
     *
     * @return array<string, \Closure>
     */
    public function enrolledStatusCounts(): array
    {
        $enrolled = $this->enrolledYearOnly();

        return [
            'assignments as assignments_count' => $enrolled,
            'assignments as tested_count' => static function (Builder $query) use ($enrolled): void {
                $enrolled($query);
                $query->tested();
            },
            'assignments as issued_count' => static function (Builder $query) use ($enrolled): void {
                $enrolled($query);
                $query->issuedOnly();
            },
            'assignments as assigned_count' => static function (Builder $query) use ($enrolled): void {
                $enrolled($query);
                $query->assignedOnly();
            },
        ];
    }

    /**
     * Constraint for an `assignments` aggregate: only the records filed in the
     * school year the student is enrolled in (matched by the year's label), so
     * the student's counts and average are not dragged around by earlier years.
     *
     * @return \Closure(\Illuminate\Database\Eloquent\Builder<\App\Models\PaceAssignment>): void
     */
    public function enrolledYearOnly(): \Closure
    {
        return static function (Builder $query): void {
            $query->whereIn('pace_assignments.school_year', function ($sub): void {
                $sub->select('name')
                    ->from('school_years')
                    ->whereColumn('school_years.id', 'students.school_year_id');
            });
        };
    }

    /**
     * Apply master-list filters to the query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Student>  $query
     * @param  array<string, mixed>  $filters
     */
    protected function applyFilters(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $like = '%'.$this->escapeLike($search).'%';

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
    }

    /**
     * Escape LIKE wildcards inside a user supplied search term.
     */
    protected function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}
