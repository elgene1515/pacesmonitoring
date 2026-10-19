<?php

namespace App\Services\Admin;

use App\Models\Pace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class AdminPaceService
{
    /**
     * Every PACE of the teachers in scope, with assignment counts and stock.
     *
     * Supported filters: search (matches number or name), teacher_id, branch_id.
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Pace>
     */
    public function list(array $filters = [], ?int $organizationId = null): Collection
    {
        $query = TeacherScope::fromFilters($filters, $organizationId)
            ->applyOwner(Pace::query(), 'paces.created_by')
            ->withCount(Pace::counts())
            ->orderByRaw('LENGTH(number), number');

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';

            $query->where(function (Builder $inner) use ($like): void {
                $inner->where('number', 'like', $like)
                    ->orWhere('name', 'like', $like);
            });
        }

        return $query->get();
    }

    /**
     * A PACE of a teacher in scope (with the teacher and their branch) and every
     * assignment (student and subject included), newest school year and quarter
     * first. 404 outside the scope.
     */
    public function detail(int $paceId, ?int $organizationId = null): Pace
    {
        return TeacherScope::forOrganization($organizationId)
            ->applyOwner(Pace::query(), 'paces.created_by')
            ->withCount(Pace::counts())
            ->with(['creator.branch', 'assignments' => function ($query): void {
                $query->with(['student.gradeLevel', 'subject', 'pace'])
                    ->orderByDesc('school_year')
                    ->orderBy('quarter')
                    ->orderByDesc('id');
            }])
            ->findOrFail($paceId);
    }
}
