<?php

namespace App\Services;

use App\Models\GradeLevel;
use App\Models\PaceAssignment;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Grade levels and their subjects. Each grade level is registered under one
 * school year, so a user may run a different set every school year.
 */
class GradeLevelService
{
    /**
     * A user's grade levels with subjects and counts, in display order.
     *
     * Supported filters: school_year_id (omit for all school years).
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\GradeLevel>
     */
    public function listFor(User $user, array $filters = []): Collection
    {
        $query = GradeLevel::query()
            ->select('grade_levels.*')
            ->leftJoin('school_years', 'school_years.id', '=', 'grade_levels.school_year_id')
            ->ownedBy($user)
            ->with(['schoolYear', 'subjects' => fn ($subjects) => $subjects->withCount(PaceAssignment::statusCounts())])
            ->withCount(['students', 'subjects'])
            ->orderByDesc('school_years.name')
            ->orderBy('grade_levels.sort_order')
            ->orderBy('grade_levels.name');

        $schoolYearId = $filters['school_year_id'] ?? null;

        if ($schoolYearId !== null && $schoolYearId !== '') {
            $query->where('grade_levels.school_year_id', (int) $schoolYearId);
        }

        return $query->get();
    }

    /**
     * Load a grade level with its school year, subjects (with status counts),
     * student / subject / record counts, and every PACE record filed under its
     * subjects (student, subject and PACE included), newest school year first,
     * then by quarter and subject order.
     */
    public function detail(GradeLevel $gradeLevel): GradeLevel
    {
        return $gradeLevel
            ->loadCount(array_merge(['students', 'subjects'], PaceAssignment::statusCounts()))
            ->load([
                'schoolYear',
                'subjects' => fn ($query) => $query->withCount(PaceAssignment::statusCounts()),
                'assignments' => function ($query): void {
                    $query
                        ->with(['student', 'subject', 'pace'])
                        ->orderByDesc('pace_assignments.school_year')
                        ->orderBy('pace_assignments.quarter')
                        ->orderBy('subjects.sort_order')
                        ->orderBy('subjects.name')
                        ->orderByDesc('pace_assignments.id');
                },
            ]);
    }

    /**
     * Create a grade level for the user under a school year. Without an
     * explicit sort order the level is appended after the ones already in
     * that school year.
     *
     * @param  array<string, mixed>  $data  name, school_year_id, sort_order?
     */
    public function create(User $user, array $data): GradeLevel
    {
        if (! isset($data['sort_order'])) {
            $data['sort_order'] = $this->nextGradeLevelOrder($user, (int) $data['school_year_id']);
        }

        $gradeLevel = $user->gradeLevels()->create($data);

        return $this->fresh($gradeLevel);
    }

    /**
     * Rename or reorder a grade level (its school year never changes).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(GradeLevel $gradeLevel, array $data): GradeLevel
    {
        unset($data['school_year_id']);

        $gradeLevel->fill(array_filter($data, static fn ($value): bool => $value !== null));
        $gradeLevel->save();

        return $this->fresh($gradeLevel);
    }

    /**
     * Remove a grade level together with its subjects (and their assignments).
     * A level that still has students enrolled cannot be removed.
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

    /**
     * Add a subject under a grade level, appended last unless an order is given.
     *
     * @param  array<string, mixed>  $data
     */
    public function createSubject(GradeLevel $gradeLevel, array $data): Subject
    {
        if (! isset($data['sort_order'])) {
            $data['sort_order'] = $this->nextSubjectOrder($gradeLevel);
        }

        $subject = $gradeLevel->subjects()->create($data);

        return $subject->loadCount(PaceAssignment::statusCounts());
    }

    /**
     * Rename or reorder a subject.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateSubject(Subject $subject, array $data): Subject
    {
        $subject->fill(array_filter($data, static fn ($value): bool => $value !== null));
        $subject->save();

        return $subject->loadCount(PaceAssignment::statusCounts());
    }

    /**
     * Load a subject with its grade level (and school year), the status counts,
     * and every PACE record filed under it (student and PACE included), newest
     * school year first, then by quarter and student name.
     */
    public function subjectDetail(Subject $subject): Subject
    {
        return $subject
            ->loadCount(PaceAssignment::statusCounts())
            ->load([
                'gradeLevel.schoolYear',
                'assignments' => function ($query): void {
                    $query
                        ->select('pace_assignments.*')
                        ->join('students', 'students.id', '=', 'pace_assignments.student_id')
                        ->with(['student.gradeLevel', 'pace'])
                        ->orderByDesc('pace_assignments.school_year')
                        ->orderBy('pace_assignments.quarter')
                        ->orderBy('students.last_name')
                        ->orderBy('students.first_name')
                        ->orderByDesc('pace_assignments.id');
                },
            ]);
    }

    /**
     * Remove a subject; its PACE assignments are cascaded by the database.
     */
    public function deleteSubject(Subject $subject): void
    {
        $subject->delete();
    }

    /**
     * Reload the level with the relations and counts the API exposes.
     */
    protected function fresh(GradeLevel $gradeLevel): GradeLevel
    {
        return $gradeLevel
            ->load(['schoolYear', 'subjects' => fn ($query) => $query->withCount(PaceAssignment::statusCounts())])
            ->loadCount(['students', 'subjects']);
    }

    protected function nextGradeLevelOrder(User $user, int $schoolYearId): int
    {
        $max = GradeLevel::query()->ownedBy($user)->where('school_year_id', $schoolYearId)->max('sort_order');

        return $max === null ? 0 : (int) $max + 1;
    }

    protected function nextSubjectOrder(GradeLevel $gradeLevel): int
    {
        $max = $gradeLevel->subjects()->max('sort_order');

        return $max === null ? 0 : (int) $max + 1;
    }
}
