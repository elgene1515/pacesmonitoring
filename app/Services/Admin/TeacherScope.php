<?php

namespace App\Services\Admin;

use App\Models\Organization;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which teachers an admin-side query may see.
 *
 * An organization admin is always limited to their own organization; the
 * owner may look at everything or narrow to one organization. On top of that
 * the dashboard filters can narrow to one branch or one teacher.
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
     * True when the scope narrows the set of teachers at all.
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
     * Sub-select of the ids of the students owned by the teachers in scope.
     */
    public function studentIds(): Builder
    {
        return Student::query()->whereIn('students.created_by', $this->teacherIds())->select('students.id');
    }

    /**
     * Limit a query on a table with a `created_by` / owner column to the teachers in scope.
     */
    public function applyOwner(Builder $query, string $column): Builder
    {
        return $this->constrains() ? $query->whereIn($column, $this->teacherIds()) : $query;
    }

    /**
     * Limit a pace_assignments query to the students of the teachers in scope.
     */
    public function applyAssignments(Builder $query): Builder
    {
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
