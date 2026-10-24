<?php

namespace App\Services\Admin;

use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The admin side of students: every student of the sections in scope, and
 * enrolling, editing, moving and removing them directly.
 */
class AdminStudentService
{
    public function __construct(private readonly StudentService $students)
    {
    }

    /**
     * Every student in scope, with their section and grade level, school
     * year, who registered them, and the enrolled-year record counts and
     * average, like the teacher's own list.
     *
     * Supported filters: search, teacher_id (students in sections that
     * teacher teaches), branch_id, grade_level_id, section_id, school_year_id.
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Student>
     */
    public function list(array $filters = [], ?int $organizationId = null): Collection
    {
        $scope = TeacherScope::fromFilters($filters, $organizationId);

        $query = $this->students->listQuery($scope->applyStudents(Student::query()))
            ->with(['section.gradeLevel.branch', 'creator']);

        $this->students->applyFilters($query, $filters);

        return $query->get();
    }

    /**
     * A student in scope; 404 outside it.
     */
    public function find(int $id, ?int $organizationId = null): Student
    {
        return Student::query()
            ->when($organizationId !== null, fn (Builder $query) => $query->forOrganization($organizationId))
            ->findOrFail($id);
    }

    public function findSection(int $id, ?int $organizationId = null): Section
    {
        return Section::query()
            ->when($organizationId !== null, fn (Builder $query) => $query->forOrganization($organizationId))
            ->findOrFail($id);
    }

    /**
     * A student in scope with everything the PACE Projection view needs.
     */
    public function detail(int $studentId, ?int $organizationId = null): Student
    {
        return $this->students->detail($this->find($studentId, $organizationId));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, int>|null  $subjectIds
     */
    public function create(array $data, Section $section, User $admin, ?array $subjectIds = null): Student
    {
        return $this->students->create($data, $section, $admin, $subjectIds);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, int>|null  $subjectIds
     */
    public function update(Student $student, array $data, ?array $subjectIds = null, ?User $by = null): Student
    {
        return $this->students->update($student, $data, $subjectIds, $by);
    }

    public function delete(Student $student): void
    {
        $this->students->delete($student);
    }

    /**
     * Every school year of the student's organization (for the school-year
     * filter), newest first.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\SchoolYear>
     */
    public function schoolYearsFor(Student $student): Collection
    {
        $student->loadMissing('section.gradeLevel.branch');

        return SchoolYear::query()
            ->forOrganization($student->section?->gradeLevel?->branch?->organization_id)
            ->orderByDesc('name')
            ->get();
    }
}
