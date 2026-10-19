<?php

namespace App\Services\Admin;

use App\Models\SchoolYear;
use Illuminate\Database\Eloquent\Collection;

class AdminSchoolYearService
{
    /**
     * The school years of one organization, newest first (every organization's
     * when none is given, for the owner).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\SchoolYear>
     */
    public function listFor(?int $organizationId): Collection
    {
        return SchoolYear::query()
            ->with('organization')
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->orderByDesc('name')
            ->orderBy('organization_id')
            ->get()
            ->each(fn (SchoolYear $year) => $year->setAttribute('is_organization_default', (bool) $year->is_current));
    }
}
