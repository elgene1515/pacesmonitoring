<?php

namespace App\Services\Admin;

use App\Models\Branch;
use App\Models\GradeLevel;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use App\Services\GradeLevelService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The admin side of the curriculum: every grade level, section and subject
 * of the organization in scope, and the set-up actions.
 */
class AdminGradeLevelService
{
    public function __construct(private readonly GradeLevelService $gradeLevels)
    {
    }

    /**
     * The grade levels in scope with subjects, sections and who teaches what,
     * newest school year first. Filters: branch_id, teacher_id (the grade
     * levels that teacher teaches in), school_year_id.
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\GradeLevel>
     */
    public function list(array $filters = [], ?int $organizationId = null): Collection
    {
        $scope = TeacherScope::fromFilters($filters, $organizationId);

        return $this->gradeLevels->listQuery($scope->applyGradeLevels(GradeLevel::query()), $filters)
            ->with(['sections' => fn ($sections) => $sections
                ->when($scope->isSingleTeacher(), fn ($query) => $query->ownedBy($scope->teacherId))
                ->withCount('students')
                ->with(['teachingAssignments.teacher', 'subjects:subjects.id'])])
            ->get();
    }

    /**
     * A grade level in scope; 404 outside it.
     */
    public function find(int $id, ?int $organizationId = null): GradeLevel
    {
        return GradeLevel::query()
            ->when($organizationId !== null, fn (Builder $query) => $query->forOrganization($organizationId))
            ->findOrFail($id);
    }

    public function findSection(int $id, ?int $organizationId = null): Section
    {
        return Section::query()
            ->when($organizationId !== null, fn (Builder $query) => $query->forOrganization($organizationId))
            ->findOrFail($id);
    }

    public function findSubject(int $id, ?int $organizationId = null): Subject
    {
        return Subject::query()
            ->when($organizationId !== null, fn (Builder $query) => $query->whereHas('gradeLevel', fn (Builder $level) => $level->forOrganization($organizationId)))
            ->findOrFail($id);
    }

    public function findBranch(int $id, ?int $organizationId = null): Branch
    {
        return Branch::query()
            ->when($organizationId !== null, fn (Builder $query) => $query->where('organization_id', $organizationId))
            ->findOrFail($id);
    }

    public function detail(int $gradeLevelId, ?int $organizationId = null): GradeLevel
    {
        return $this->gradeLevels->detail($this->find($gradeLevelId, $organizationId));
    }

    public function subjectDetail(int $subjectId, ?int $organizationId = null): Subject
    {
        return $this->gradeLevels->subjectDetail($this->findSubject($subjectId, $organizationId));
    }

    public function sectionDetail(int $sectionId, ?int $organizationId = null): Section
    {
        return $this->gradeLevels->sectionDetail($this->findSection($sectionId, $organizationId));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Branch $branch, array $data, User $admin): GradeLevel
    {
        return $this->gradeLevels->create($branch, $data, $admin);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(GradeLevel $gradeLevel, array $data): GradeLevel
    {
        return $this->gradeLevels->update($gradeLevel, $data);
    }

    public function delete(GradeLevel $gradeLevel): void
    {
        $this->gradeLevels->delete($gradeLevel);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createSubject(GradeLevel $gradeLevel, array $data): Subject
    {
        return $this->gradeLevels->createSubject($gradeLevel, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateSubject(Subject $subject, array $data): Subject
    {
        return $this->gradeLevels->updateSubject($subject, $data);
    }

    public function deleteSubject(Subject $subject): void
    {
        $this->gradeLevels->deleteSubject($subject);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createSection(GradeLevel $gradeLevel, array $data): Section
    {
        return $this->gradeLevels->createSection($gradeLevel, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateSection(Section $section, array $data): Section
    {
        return $this->gradeLevels->updateSection($section, $data);
    }

    public function offerSubject(Section $section, Subject $subject): Section
    {
        return $this->gradeLevels->offerSubject($section, $subject);
    }

    public function withdrawSubject(Section $section, Subject $subject): Section
    {
        return $this->gradeLevels->withdrawSubject($section, $subject);
    }

    public function deleteSection(Section $section): void
    {
        $this->gradeLevels->deleteSection($section);
    }
}
