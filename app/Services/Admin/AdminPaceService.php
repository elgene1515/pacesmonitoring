<?php

namespace App\Services\Admin;

use App\Models\Branch;
use App\Models\Pace;
use App\Services\PaceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The admin side of the PACE catalogs: every branch's catalog in scope with
 * the pool and each teacher's booklets.
 */
class AdminPaceService
{
    public function __construct(private readonly PaceService $paces)
    {
    }

    /**
     * The catalogs in scope with assignment counts, the pool, every holding
     * and the total held. Filters: search (number or name), branch_id,
     * teacher_id (PACEs that teacher has booklets of).
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Pace>
     */
    public function list(array $filters = [], ?int $organizationId = null): Collection
    {
        $scope = TeacherScope::fromFilters($filters, $organizationId);

        return $this->paces->catalogQuery($scope->heldBy($scope->applyPaces(Pace::query())), $filters)
            ->with(['branch.organization', 'holdings.teacher'])
            ->withSum('holdings as holdings_total', 'quantity')
            ->get();
    }

    /**
     * A PACE in scope; 404 outside it.
     */
    public function find(int $id, ?int $organizationId = null): Pace
    {
        return Pace::query()
            ->when($organizationId !== null, fn (Builder $query) => $query->forOrganization($organizationId))
            ->findOrFail($id);
    }

    public function findBranch(int $id, ?int $organizationId = null): Branch
    {
        return Branch::query()
            ->when($organizationId !== null, fn (Builder $query) => $query->where('organization_id', $organizationId))
            ->findOrFail($id);
    }

    /**
     * A PACE in scope with the pool, every holding, and every assignment
     * (student and subject included). 404 outside the scope.
     */
    public function detail(int $paceId, ?int $organizationId = null): Pace
    {
        return $this->paces->detail($this->find($paceId, $organizationId));
    }
}
