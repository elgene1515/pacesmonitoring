<?php

namespace App\Services\Admin;

use App\Models\GradeLevel;
use App\Models\Organization;
use App\Models\Pace;
use App\Models\PaceAssignment;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * What an admin-side query may see.
 *
 * An organization admin is always limited to their own organization; the
 * owner may look at everything or narrow to one organization. On top of that
 * the dashboard filters can narrow to one branch or one teacher. Students,
 * grade levels and records are reached through sections: a branch's grade
 * levels, or the sections a teacher teaches in.
 */
final class TeacherScope
{
    public function __construct(
        public readonly ?int $organizationId = null,
        public readonly ?int $branchId = null,
        public readonly ?int $teacherId = null,
    ) {
    }

    /**
     * Build a scope from the usual request filters plus the resolved organization.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function fromFilters(array $filters, ?int $organizationId): self
    {
        return new self(
            $organizationId,
            self::id($filters['branch_id'] ?? null),
            self::id($filters['teacher_id'] ?? null),
        );
    }

    public static function forOrganization(Organization|int|null $organization): self
    {
        return new self($organization instanceof Organization ? $organization->getKey() : $organization);
    }

    /**
     * True when the scope narrows anything at all.
     */
    public function constrains(): bool
    {
        return $this->organizationId !== null || $this->branchId !== null || $this->teacherId !== null;
    }

    public function isSingleTeacher(): bool
    {
        return $this->teacherId !== null;
    }

    /**
     * The teachers in scope.
     *
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\User>
     */
    public function teachers(): Builder
    {
        return User::query()
            ->teachers()
            ->when($this->organizationId !== null, fn (Builder $query) => $query->where('users.organization_id', $this->organizationId))
            ->when($this->branchId !== null, fn (Builder $query) => $query->where('users.branch_id', $this->branchId))
            ->when($this->teacherId !== null, fn (Builder $query) => $query->whereKey($this->teacherId));
    }

    /**
     * Every member in scope (admins included), for user lists.
     *
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\User>
     */
    public function members(): Builder
    {
        return User::query()
            ->when($this->organizationId !== null, fn (Builder $query) => $query->where('users.organization_id', $this->organizationId))
            ->when($this->branchId !== null, fn (Builder $query) => $query->where('users.branch_id', $this->branchId))
            ->when($this->teacherId !== null, fn (Builder $query) => $query->whereKey($this->teacherId));
    }

    /**
     * Sub-select of the teacher ids in scope, for `whereIn` constraints.
     */
    public function teacherIds(): Builder
    {
        return $this->teachers()->select('users.id');
    }

    /**
     * The sections in scope: the organization's (or branch's) sections, and
     * only the ones the teacher teaches in when a teacher is picked.
     *
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Section>
     */
    public function sections(): Builder
    {
        return Section::query()
            ->when($this->organizationId !== null, fn (Builder $query) => $query->forOrganization($this->organizationId))
            ->when($this->branchId !== null, fn (Builder $query) => $query->forBranch($this->branchId))
            ->when($this->teacherId !== null, fn (Builder $query) => $query->ownedBy($this->teacherId));
    }

    public function sectionIds(): Builder
    {
        return $this->sections()->select('sections.id');
    }

    /**
     * The grade levels in scope.
     *
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\GradeLevel>
     */
    public function gradeLevels(): Builder
    {
        return GradeLevel::query()
            ->when($this->organizationId !== null, fn (Builder $query) => $query->forOrganization($this->organizationId))
            ->when($this->branchId !== null, fn (Builder $query) => $query->forBranch($this->branchId))
            ->when($this->teacherId !== null, fn (Builder $query) => $query->ownedBy($this->teacherId));
    }

    /**
     * Sub-select of the ids of the students in scope.
     */
    public function studentIds(): Builder
    {
        return Student::query()
            ->whereIn('students.section_id', $this->sectionIds())
            ->when($this->teacherId !== null, fn (Builder $students) => $students->ownedBy($this->teacherId))
            ->select('students.id');
    }

    /**
     * Limit a students query to the scope.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Student>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Student>
     */
    public function applyStudents(Builder $query): Builder
    {
        return $this->constrains()
            ? $query
                ->whereIn('students.section_id', $this->sectionIds())
                ->when($this->teacherId !== null, fn (Builder $students) => $students->ownedBy($this->teacherId))
            : $query;
    }

    /**
     * Limit a grade levels query to the scope.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\GradeLevel>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\GradeLevel>
     */
    public function applyGradeLevels(Builder $query): Builder
    {
        return $this->constrains() ? $query->whereIn('grade_levels.id', $this->gradeLevels()->select('grade_levels.id')) : $query;
    }

    /**
     * Limit a PACE catalog query to the scope: the organization's or branch's
     * catalogs; with a teacher, their branch's catalog (see heldBy() for the
     * PACEs a teacher actually has booklets of).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Pace>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Pace>
     */
    public function applyPaces(Builder $query): Builder
    {
        return $query
            ->when($this->organizationId !== null, fn (Builder $paces) => $paces->forOrganization($this->organizationId))
            ->when($this->branchId !== null, fn (Builder $paces) => $paces->forBranch($this->branchId))
            ->when($this->teacherId !== null, fn (Builder $paces) => $paces->ownedBy($this->teacherId));
    }

    /**
     * Narrow a PACE catalog query to the PACEs the scope's teacher has
     * booklets of (no-op without a teacher).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Pace>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Pace>
     */
    public function heldBy(Builder $query): Builder
    {
        return $query->when($this->teacherId !== null, fn (Builder $paces) => $paces->whereHas(
            'holdings',
            fn (Builder $holding) => $holding->where('user_id', $this->teacherId)->where('quantity', '>', 0)
        ));
    }

    /**
     * Limit a pace_assignments query to the scope: the records of the students
     * in scope; with a teacher, only the subjects that teacher is in charge of.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\PaceAssignment>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\PaceAssignment>
     */
    public function applyAssignments(Builder $query): Builder
    {
        if ($this->teacherId !== null) {
            return $query->ownedBy($this->teacherId);
        }

        return $this->constrains() ? $query->whereIn('pace_assignments.student_id', $this->studentIds()) : $query;
    }

    /**
     * True when the given user is one of the teachers in scope.
     */
    public function includesTeacher(User|int $user): bool
    {
        return $this->teachers()->whereKey($user instanceof User ? $user->getKey() : $user)->exists();
    }

    /**
     * A positive integer id from a filter value, or null.
     */
    public static function id(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }
}
