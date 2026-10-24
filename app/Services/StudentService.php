<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\PaceAssignment;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Students are enrolled in a section and shared by every teacher assigned to
 * it. Admins and teachers enroll them; only admins change their details
 * directly (teachers propose changes, see StudentChangeRequestService).
 */
class StudentService
{
    public function __construct(
        private readonly PaceAssignmentService $assignments,
        private readonly SubscriptionService $subscriptions,
        private readonly StudentEnrollmentService $enrollments,
    ) {
    }

    /**
     * The students in the sections a teacher teaches, with their section
     * (and grade level), school year and enrolled-year record counts.
     *
     * Supported filters: search (first/middle/last name), section_id,
     * grade_level_id, school_year_id.
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Student>
     */
    public function list(User $teacher, array $filters = []): Collection
    {
        $query = $this->listQuery(Student::query()->ownedBy($teacher));

        $this->applyFilters($query, $filters);

        return $query->get();
    }

    /**
     * The common list shape: section with grade level, school year, counts, average.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Student>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Student>
     */
    public function listQuery(Builder $query): Builder
    {
        return $query
            ->with(['section.gradeLevel', 'schoolYear', 'subjects'])
            ->withCount($this->enrolledStatusCounts())
            ->withSum(['assignments as score_sum' => $this->enrolledYearOnly()], 'score')
            ->orderBy('last_name')
            ->orderBy('first_name');
    }

    /**
     * Enroll a new student in a section. The student's school year is the
     * section's grade level's; the plan's student limit applies.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, int>|null  $subjectIds  the subjects the student takes; null = every subject the section offers
     */
    public function create(array $data, Section $section, User $creator, ?array $subjectIds = null): Student
    {
        $section->loadMissing('gradeLevel.branch');

        $this->subscriptions->assertCanAddStudent($this->organizationOf($section));

        $student = Student::create($data + [
            'section_id' => $section->id,
            'school_year_id' => $section->gradeLevel->school_year_id,
            'created_by' => $creator->id,
        ]);

        $student->subjects()->sync($subjectIds ?? $section->subjects()->pluck('subjects.id')->all());

        // The first enrollment-history row is written by the model's `created` hook.
        return $this->fresh($student);
    }

    /**
     * Change a student's details, section and/or subjects (the school year follows the section).
     *
     * Moving to a section of another grade level replaces the subjects (by the
     * given ones, else every subject of the new grade level); the old records
     * stay as history. Within a grade level, a subject that has PACE records
     * cannot be dropped.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, int>|null  $subjectIds  null = keep the current subjects
     * @param  User|null  $by  who moved the student (kept in the enrollment history)
     */
    public function update(Student $student, array $data, ?array $subjectIds = null, ?User $by = null): Student
    {
        $moved = array_key_exists('section_id', $data) && (int) $data['section_id'] !== (int) $student->section_id;
        $newSection = null;
        $changesGradeLevel = false;

        if ($moved) {
            $newSection = Section::query()->with('gradeLevel')->whereKey((int) $data['section_id'])->first();
            $data['school_year_id'] = $newSection?->gradeLevel?->school_year_id;
            $changesGradeLevel = $newSection?->grade_level_id !== $student->section?->grade_level_id;
        }

        // Moving to a section that offers different subjects drops the ones it lacks (records stay as history).
        $this->guardDroppedSubjects($student, $subjectIds, $moved);

        $student->fill($data);
        $student->save();

        if ($subjectIds !== null) {
            $student->subjects()->sync($subjectIds);
        } elseif ($newSection !== null) {
            $offered = $newSection->subjects()->pluck('subjects.id');
            $keep = $changesGradeLevel ? $offered : $student->subjects()->pluck('subjects.id')->intersect($offered);

            $student->subjects()->sync($keep->values()->all());
        }

        if ($moved && $newSection !== null) {
            $this->enrollments->recordMove($student, $newSection, $by);
        }

        return $this->fresh($student);
    }

    /**
     * A subject with PACE records cannot be dropped while the student stays in the grade level.
     *
     * @param  array<int, int>|null  $subjectIds
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function guardDroppedSubjects(Student $student, ?array $subjectIds, bool $changesGradeLevel): void
    {
        if ($subjectIds === null || $changesGradeLevel) {
            return;
        }

        $dropped = $student->subjects()->pluck('subjects.id')->diff($subjectIds);

        if ($dropped->isEmpty()) {
            return;
        }

        $withRecords = PaceAssignment::query()
            ->where('student_id', $student->id)
            ->whereIn('subject_id', $dropped)
            ->with('subject:id,name')
            ->get()
            ->pluck('subject.name')
            ->unique()
            ->values();

        if ($withRecords->isNotEmpty()) {
            throw ValidationException::withMessages([
                'subject_ids' => [sprintf(
                    'The student has PACE records in %s. Delete them before dropping the subject.',
                    $withRecords->implode(', ')
                )],
            ]);
        }
    }

    /**
     * Remove a student together with their PACE records (cascaded by the database).
     */
    public function delete(Student $student): void
    {
        $student->delete();
    }

    /**
     * Everything the student page needs: the section with its grade level
     * (subjects, school year, branch) and who teaches what, the school year,
     * and every record (with pace and subject) in display order.
     */
    public function detail(Student $student): Student
    {
        return $student->load([
            'section.gradeLevel.subjects',
            'section.gradeLevel.schoolYear',
            'section.gradeLevel.branch.organization',
            'section.teachingAssignments.teacher',
            'schoolYear',
            'subjects',
            'creator',
            'enrollments.mover',
            'assignments' => function ($query): void {
                $query->with(['pace', 'subject']);
                $this->assignments->applyDefaultOrder($query);
            },
        ]);
    }

    /**
     * The organization a section belongs to.
     */
    public function organizationOf(Section $section): ?Organization
    {
        return $section->gradeLevel?->branch?->organization;
    }

    /**
     * Reload the relations and counts the list/detail resources expose.
     */
    protected function fresh(Student $student): Student
    {
        return $student
            ->load(['section.gradeLevel', 'schoolYear', 'subjects'])
            ->loadCount($this->enrolledStatusCounts())
            ->loadSum(['assignments as score_sum' => $this->enrolledYearOnly()], 'score');
    }

    /**
     * Like PaceAssignment::statusCounts() but limited to the student's enrolled
     * school year, so the list's record column and average only reflect it.
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
     * school year the student is enrolled in (matched by the year's label).
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
    public function applyFilters(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';

            $query->where(function (Builder $inner) use ($like): void {
                $inner->where('first_name', 'like', $like)
                    ->orWhere('middle_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('student_number', 'like', $like);
            });
        }

        $sectionId = $filters['section_id'] ?? null;

        if ($sectionId !== null && $sectionId !== '') {
            $query->where('students.section_id', (int) $sectionId);
        }

        $gradeLevelId = $filters['grade_level_id'] ?? null;

        if ($gradeLevelId !== null && $gradeLevelId !== '') {
            $query->whereHas('section', fn (Builder $section) => $section->where('grade_level_id', (int) $gradeLevelId));
        }

        $schoolYearId = $filters['school_year_id'] ?? null;

        if ($schoolYearId !== null && $schoolYearId !== '') {
            $query->where('students.school_year_id', (int) $schoolYearId);
        }
    }
}
