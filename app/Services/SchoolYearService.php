<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\PaceAssignment;
use App\Models\SchoolYear;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * School years belong to an organization and are registered by its admins,
 * so every teacher of the school picks from the same list. Exactly one of an
 * organization's school years is its default ("current"); a teacher can pick
 * a different one as their own current year, which only affects them.
 *
 * Every school year handed out here carries two flags:
 *  - is_organization_default: the organization's default (the stored flag)
 *  - is_current: what counts as current for the viewer (their own pick for a
 *    teacher, the organization default otherwise)
 */
class SchoolYearService
{
    /**
     * The viewer's school years, newest first, with record counts: a
     * teacher's own counts, or organization-wide counts for an admin.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\SchoolYear>
     */
    public function listFor(User $viewer): Collection
    {
        $owner = $viewer->isTeacher() ? $viewer : null;

        $years = SchoolYear::query()
            ->ownedBy($viewer)
            ->withCount($this->recordCounts($owner))
            ->orderByDesc('name')
            ->get();

        $this->attachAssignmentCounts($years, $viewer->organization_id, $owner);

        return $this->flagCurrent($years, $viewer);
    }

    /**
     * The school year that is current for the user (see User::currentSchoolYear()).
     */
    public function currentFor(User $user): ?SchoolYear
    {
        return $user->currentSchoolYear();
    }

    /**
     * Register a school year for the admin's organization. The organization's
     * first school year becomes its default.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $admin, array $data): SchoolYear
    {
        return DB::transaction(function () use ($admin, $data): SchoolYear {
            $organizationId = (int) $admin->organization_id;
            $isFirst = ! SchoolYear::query()->forOrganization($organizationId)->exists();
            $makeCurrent = $isFirst || ! empty($data['is_current']);

            if ($makeCurrent) {
                $this->clearCurrent($organizationId);
            }

            $year = SchoolYear::create($data + [
                'organization_id' => $organizationId,
                'user_id' => $admin->id,
                'is_current' => $makeCurrent,
            ]);

            $year->is_current = $makeCurrent;
            $year->save();

            return $this->detail($year, $admin);
        });
    }

    /**
     * Update a school year's label, dates or default flag.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(SchoolYear $year, array $data, ?User $viewer = null): SchoolYear
    {
        return DB::transaction(function () use ($year, $data, $viewer): SchoolYear {
            if (! empty($data['is_current'])) {
                $this->clearCurrent($year->organization_id);
            }

            $year->fill($data);
            $year->save();

            return $this->detail($year, $viewer);
        });
    }

    /**
     * A teacher picks the school year they are currently working in.
     */
    public function selectCurrent(User $teacher, SchoolYear $year): SchoolYear
    {
        $teacher->current_school_year_id = $year->id;
        $teacher->save();

        return $this->detail($year, $teacher);
    }

    /**
     * Reload a school year with the counts the API exposes (the viewer's own
     * counts for a teacher, organization-wide otherwise) and the current flags.
     */
    public function detail(SchoolYear $year, ?User $viewer = null): SchoolYear
    {
        $owner = $viewer !== null && $viewer->isTeacher() ? $viewer : null;

        $year->loadCount($this->recordCounts($owner));
        $year->setAttribute('assignments_count', $year->assignmentsQuery($owner)->count());

        if ($viewer !== null) {
            $this->flagCurrent(new Collection([$year]), $viewer);
        } else {
            $year->setAttribute('is_organization_default', (bool) $year->is_current);
        }

        return $year;
    }

    /**
     * Unflag whichever school year is the organization's default.
     */
    protected function clearCurrent(Organization|int|null $organization): void
    {
        SchoolYear::query()->forOrganization($organization)->where('is_current', true)->update(['is_current' => false]);
    }

    /**
     * withCount() spec for students and grade levels: organization-wide, or
     * limited to the sections a teacher teaches in when given.
     *
     * @return array<int|string, mixed>
     */
    protected function recordCounts(?User $owner): array
    {
        if ($owner === null) {
            return ['students', 'gradeLevels'];
        }

        return [
            'students' => fn (Builder $query) => $query->ownedBy($owner),
            'gradeLevels' => fn (Builder $query) => $query->ownedBy($owner),
        ];
    }

    /**
     * Set `is_organization_default` (the stored flag) and `is_current` (what
     * is current for the viewer) on every year.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, \App\Models\SchoolYear>  $years
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\SchoolYear>
     */
    protected function flagCurrent(Collection $years, User $viewer): Collection
    {
        $currentId = $viewer->isTeacher() ? $viewer->currentSchoolYear()?->id : null;

        foreach ($years as $year) {
            $year->setAttribute('is_organization_default', (bool) $year->is_current);

            if ($currentId !== null) {
                $year->setAttribute('is_current', $year->id === $currentId);
            }
        }

        return $years;
    }

    /**
     * Count the PACE records per school-year label in one query: the records
     * one teacher is in charge of, or every record of the organization.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, \App\Models\SchoolYear>  $years
     */
    protected function attachAssignmentCounts(Collection $years, ?int $organizationId, ?User $owner): void
    {
        $query = PaceAssignment::query()
            ->groupBy('pace_assignments.school_year')
            ->selectRaw('pace_assignments.school_year as name, COUNT(*) as total');

        if ($owner !== null) {
            $query->ownedBy($owner);
        } else {
            $query->forOrganization($organizationId);
        }

        $counts = $query->pluck('total', 'name');

        foreach ($years as $year) {
            $year->setAttribute('assignments_count', (int) ($counts[$year->name] ?? 0));
        }
    }
}
