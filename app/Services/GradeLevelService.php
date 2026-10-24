<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\GradeLevel;
use App\Models\PaceAssignment;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The curriculum: grade levels (per branch and school year) with their
 * subjects and sections. Admins set it up; a teacher sees the grade levels
 * and sections their teaching assignments cover.
 */
class GradeLevelService
{
    /**
     * The grade levels a teacher teaches in, with the sections they teach
     * and every subject, newest school year first. Filters: school_year_id.
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\GradeLevel>
     */
    public function listFor(User $teacher, array $filters = []): Collection
    {
        return $this->listQuery(GradeLevel::query()->ownedBy($teacher), $filters, $teacher)
            ->with(['sections' => fn ($sections) => $sections->ownedBy($teacher)->withCount(['students' => fn ($students) => $students->ownedBy($teacher)])->with(['teachingAssignments.teacher', 'subjects:subjects.id'])])
            ->get();
    }

    /**
     * Every grade level of a branch with every section and who teaches what.
     * Filters: school_year_id.
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\GradeLevel>
     */
    public function listForBranch(Branch|int $branch, array $filters = []): Collection
    {
        return $this->listQuery(GradeLevel::query()->forBranch($branch), $filters)
            ->with(['sections' => fn ($sections) => $sections->withCount('students')->with(['teachingAssignments.teacher', 'subjects:subjects.id'])])
            ->get();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\GradeLevel>  $query
     * @param  array<string, mixed>  $filters
     * @param  \App\Models\User|null  $teacher  count only the students taking a subject this teacher teaches
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\GradeLevel>
     */
    public function listQuery(Builder $query, array $filters = [], ?User $teacher = null): Builder
    {
        $query
            ->select('grade_levels.*')
            ->leftJoin('school_years', 'school_years.id', '=', 'grade_levels.school_year_id')
            ->with(['schoolYear', 'branch.organization', 'subjects' => fn ($subjects) => $subjects->withCount(PaceAssignment::statusCounts())])
            ->withCount([
                'students' => fn ($students) => $students->when($teacher !== null, fn ($owned) => $owned->ownedBy($teacher)),
                'subjects',
                'sections',
            ])
            ->orderByDesc('school_years.name')
            ->orderBy('grade_levels.sort_order')
            ->orderBy('grade_levels.name');

        $schoolYearId = $filters['school_year_id'] ?? null;

        if ($schoolYearId !== null && $schoolYearId !== '') {
            $query->where('grade_levels.school_year_id', (int) $schoolYearId);
        }

        return $query;
    }

    /**
     * A grade level with its subjects, sections and every PACE record under
     * them. For a teacher, the sections and records are limited to the
     * sections they teach.
     */
    public function detail(GradeLevel $gradeLevel, ?User $teacher = null): GradeLevel
    {
        return $gradeLevel
            ->loadCount(array_merge([
                'students' => fn (Builder $query) => $query->when($teacher !== null, fn ($students) => $students->ownedBy($teacher)),
                'sections' => fn (Builder $query) => $query->when($teacher !== null, fn ($sections) => $sections->ownedBy($teacher)),
                'subjects',
            ], static::recordCounts($teacher)))
            ->load([
                'schoolYear',
                'branch.organization',
                'subjects' => fn ($query) => $query->withCount(static::recordCounts($teacher)),
                'sections' => fn ($query) => $query
                    ->when($teacher !== null, fn ($sections) => $sections->ownedBy($teacher))
                    ->withCount(['students' => fn ($students) => $students->when($teacher !== null, fn ($owned) => $owned->ownedBy($teacher))])
                    ->with(['teachingAssignments.teacher', 'subjects:subjects.id']),
                'assignments' => function ($query) use ($teacher): void {
                    $query
                        ->when($teacher !== null, fn ($records) => $records->whereHas('student', fn (Builder $student) => $student->ownedBy($teacher)))
                        ->with(['student.section', 'subject', 'pace'])
                        ->orderByDesc('pace_assignments.school_year')
                        ->orderBy('pace_assignments.quarter')
                        ->orderBy('subjects.sort_order')
                        ->orderBy('subjects.name')
                        ->orderByDesc('pace_assignments.id');
                },
            ]);
    }

    /**
     * A subject with its grade level and every PACE record filed under it;
     * for a teacher, only the records of students in sections they teach.
     */
    public function subjectDetail(Subject $subject, ?User $teacher = null): Subject
    {
        return $subject
            ->loadCount(static::recordCounts($teacher))
            ->load([
                'gradeLevel.schoolYear',
                'gradeLevel.branch.organization',
                'teachingAssignments.section',
                'teachingAssignments.teacher',
                'assignments' => function ($query) use ($teacher): void {
                    $query
                        ->select('pace_assignments.*')
                        ->join('students', 'students.id', '=', 'pace_assignments.student_id')
                        ->when($teacher !== null, fn ($records) => $records->whereHas('student', fn (Builder $student) => $student->ownedBy($teacher)))
                        ->with(['student.section', 'pace'])
                        ->orderByDesc('pace_assignments.school_year')
                        ->orderBy('pace_assignments.quarter')
                        ->orderBy('students.last_name')
                        ->orderBy('students.first_name')
                        ->orderByDesc('pace_assignments.id');
                },
            ]);
    }

    /**
     * A section with its grade level (subjects and school year), who teaches
     * what, and its roster with each student's record counts and average score
     * (over the school year they are enrolled in, like the Students lists).
     */
    public function sectionDetail(Section $section, ?User $teacher = null): Section
    {
        $students = app(StudentService::class);

        return $section
            ->loadCount(['students' => fn ($students) => $students->when($teacher !== null, fn ($owned) => $owned->ownedBy($teacher))])
            ->load([
                'gradeLevel.schoolYear',
                'gradeLevel.branch.organization',
                'gradeLevel.subjects',
                'subjects:subjects.id',
                'teachingAssignments.subject',
                'teachingAssignments.teacher',
                'students' => fn ($query) => $query
                    ->when($teacher !== null, fn ($owned) => $owned->ownedBy($teacher))
                    ->with('subjects')
                    ->withCount($students->enrolledStatusCounts())
                    ->withSum(['assignments as score_sum' => $students->enrolledYearOnly()], 'score')
                    ->orderBy('last_name')
                    ->orderBy('first_name'),
            ]);
    }

    /**
     * PaceAssignment::statusCounts(), limited for a teacher to the records of
     * students in sections they teach.
     *
     * @return array<int|string, mixed>
     */
    public static function recordCounts(?User $teacher): array
    {
        $counts = PaceAssignment::statusCounts();

        if ($teacher === null) {
            return $counts;
        }

        $visible = static fn (Builder $query) => $query->whereHas('student', fn (Builder $student) => $student->ownedBy($teacher));
        $limited = [];

        foreach ($counts as $key => $constraint) {
            if (is_int($key)) {
                $limited[$constraint.' as '.$constraint.'_count'] = $visible;

                continue;
            }

            $limited[$key] = static function (Builder $query) use ($constraint, $visible): void {
                $constraint($query);
                $visible($query);
            };
        }

        return $limited;
    }

    /* ------------------------------------------------------- grade levels */

    /**
     * Register a grade level in a branch under one of the organization's
     * school years. Appended after the branch's levels of that year unless an
     * order is given.
     *
     * @param  array<string, mixed>  $data  name, school_year_id, sort_order?
     */
    public function create(Branch $branch, array $data, ?User $by = null): GradeLevel
    {
        if (! isset($data['sort_order'])) {
            $data['sort_order'] = $this->nextGradeLevelOrder($branch, (int) $data['school_year_id']);
        }

        $gradeLevel = $branch->gradeLevels()->create($data + ['created_by' => $by?->id]);

        return $this->fresh($gradeLevel);
    }

    /**
     * Rename or reorder a grade level (its branch and school year never change).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(GradeLevel $gradeLevel, array $data): GradeLevel
    {
        unset($data['school_year_id'], $data['branch_id']);

        $gradeLevel->fill(array_filter($data, static fn ($value): bool => $value !== null));
        $gradeLevel->save();

        return $this->fresh($gradeLevel);
    }

    /**
     * Remove a grade level with its subjects, sections and records. Refused
     * while any of its sections still has students.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function delete(GradeLevel $gradeLevel): void
    {
        $students = $gradeLevel->students()->count();

        if ($students > 0) {
            throw ValidationException::withMessages([
                'grade_level' => [sprintf(
                    'This grade level still has %d %s. Move or delete them first.',
                    $students,
                    $students === 1 ? 'student' : 'students'
                )],
            ]);
        }

        $gradeLevel->delete();
    }

    /* ----------------------------------------------------------- subjects */

    /**
     * Add a subject to a grade level (offered by every section at first), appended last
     * unless an order is given.
     *
     * @param  array<string, mixed>  $data
     */
    public function createSubject(GradeLevel $gradeLevel, array $data): Subject
    {
        if (! isset($data['sort_order'])) {
            $data['sort_order'] = $this->nextOrder($gradeLevel->subjects());
        }

        $subject = $gradeLevel->subjects()->create($data);

        // Every section offers it to begin with; the admin can take it off the ones that do not.
        $subject->sections()->sync($gradeLevel->sections()->pluck('id')->all());

        return $subject->loadCount(PaceAssignment::statusCounts());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateSubject(Subject $subject, array $data): Subject
    {
        $subject->fill(array_filter($data, static fn ($value): bool => $value !== null));
        $subject->save();

        return $subject->loadCount(PaceAssignment::statusCounts());
    }

    /**
     * Remove a subject; its records and teaching assignments are cascaded.
     */
    public function deleteSubject(Subject $subject): void
    {
        $subject->delete();
    }

    /* ----------------------------------------------------------- sections */

    /**
     * Add a section to a grade level, appended last unless an order is given.
     *
     * @param  array<string, mixed>  $data
     */
    public function createSection(GradeLevel $gradeLevel, array $data): Section
    {
        if (! isset($data['sort_order'])) {
            $data['sort_order'] = $this->nextOrder($gradeLevel->sections());
        }

        $section = $gradeLevel->sections()->create($data);

        // A new section offers every subject of the grade level to begin with.
        $section->subjects()->sync($gradeLevel->subjects()->pluck('id')->all());

        return $section->loadCount('students')->load(['teachingAssignments.teacher', 'subjects']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateSection(Section $section, array $data): Section
    {
        $section->fill(array_filter($data, static fn ($value): bool => $value !== null));
        $section->save();

        return $section->loadCount('students')->load(['teachingAssignments.teacher', 'subjects']);
    }

    /**
     * Make a section offer a subject of its grade level.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function offerSubject(Section $section, Subject $subject): Section
    {
        if ((int) $subject->grade_level_id !== (int) $section->grade_level_id) {
            throw ValidationException::withMessages([
                'subject_id' => ["The subject does not belong to the section's grade level."],
            ]);
        }

        $section->subjects()->syncWithoutDetaching([$subject->id]);

        return $section->load('subjects');
    }

    /**
     * Stop a section from offering a subject. Refused while a teacher is
     * assigned to it or students of the section take it; pending requests
     * for the slot are closed.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function withdrawSubject(Section $section, Subject $subject): Section
    {
        $teacher = \App\Models\TeachingAssignment::query()
            ->where('section_id', $section->id)
            ->where('subject_id', $subject->id)
            ->with('teacher')
            ->first();

        if ($teacher !== null) {
            throw ValidationException::withMessages([
                'subject_id' => [sprintf('%s teaches %s in this section. Take the teacher off first.', $teacher->teacher?->name ?? 'A teacher', $subject->name)],
            ]);
        }

        $takers = \App\Models\Student::query()
            ->where('section_id', $section->id)
            ->whereHas('subjects', fn ($query) => $query->whereKey($subject->id))
            ->count();

        if ($takers > 0) {
            throw ValidationException::withMessages([
                'subject_id' => [sprintf(
                    '%d %s in this section %s %s. Remove it from %s first.',
                    $takers,
                    $takers === 1 ? 'student' : 'students',
                    $takers === 1 ? 'takes' : 'take',
                    $subject->name,
                    $takers === 1 ? 'their subjects' : 'their subjects'
                )],
            ]);
        }

        \App\Models\TeachingAssignmentRequest::query()
            ->pending()
            ->where('section_id', $section->id)
            ->where('subject_id', $subject->id)
            ->update([
                'status' => \App\Enums\TeachingAssignmentRequestStatus::Declined->value,
                'decline_reason' => 'The subject is no longer offered in that section.',
                'decided_at' => now(),
            ]);

        $section->subjects()->detach($subject->id);

        return $section->load('subjects');
    }

    /**
     * Remove a section and its teaching assignments. Refused while it has students.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function deleteSection(Section $section): void
    {
        $students = $section->students()->count();

        if ($students > 0) {
            throw ValidationException::withMessages([
                'section' => [sprintf(
                    'This section still has %d %s. Move or delete them first.',
                    $students,
                    $students === 1 ? 'student' : 'students'
                )],
            ]);
        }

        $section->delete();
    }

    /**
     * Reload the level with the relations and counts the API exposes.
     */
    protected function fresh(GradeLevel $gradeLevel): GradeLevel
    {
        return $gradeLevel
            ->load([
                'schoolYear',
                'branch',
                'subjects' => fn ($query) => $query->withCount(PaceAssignment::statusCounts()),
                'sections' => fn ($query) => $query->withCount('students')->with(['teachingAssignments.teacher', 'subjects:subjects.id']),
            ])
            ->loadCount(['students', 'subjects', 'sections']);
    }

    protected function nextGradeLevelOrder(Branch $branch, int $schoolYearId): int
    {
        $max = $branch->gradeLevels()->where('school_year_id', $schoolYearId)->max('sort_order');

        return $max === null ? 0 : (int) $max + 1;
    }

    protected function nextOrder(\Illuminate\Database\Eloquent\Relations\HasMany $relation): int
    {
        $max = $relation->max('sort_order');

        return $max === null ? 0 : (int) $max + 1;
    }
}
