<?php

namespace App\Services\Owner;

use App\Enums\Role;
use App\Models\GradeLevel;
use App\Models\Pace;
use App\Models\PaceRequest;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Accounts that exist but belong to no organization: teachers who have not
 * been admitted yet (or were removed) and school admins who have not
 * registered their school. The owner reviews them and may delete the ones
 * that are going nowhere.
 */
class UnassignedUserService
{
    /**
     * The most accounts the list returns at once; the rest is reached by narrowing the filters.
     */
    public const LIMIT = 100;

    /**
     * Record tables that point at a user, with the column that does. Deleting an
     * account that still owns any of them would delete (or orphan) data that
     * belongs to its previous school, so such accounts are kept. (Students,
     * grade levels and PACEs belong to the school now and only remember who
     * registered them, so they no longer hold an account back.)
     *
     * @var array<string, array{0: class-string<\Illuminate\Database\Eloquent\Model>, 1: string}>
     */
    protected const RECORDS = [
        'school_years' => [SchoolYear::class, 'user_id'],
        'pace_requests' => [PaceRequest::class, 'user_id'],
    ];

    /**
     * The roles an account without an organization can have (platform accounts never appear here).
     *
     * @return array<int, string>
     */
    public static function roles(): array
    {
        return [Role::Teacher->value, Role::Admin->value];
    }

    /**
     * Every non-platform account without an organization.
     *
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\User>
     */
    public function query(): Builder
    {
        return User::query()
            ->whereNull('organization_id')
            ->whereNotIn('role', Role::platform());
    }

    /**
     * The accounts, longest without a school first.
     *
     * Supported filters: search (name or email), role (teacher or admin), from / to
     * (the day the account was created, "Y-m-d", inclusive; a reversed range is put in order).
     *
     * @param  array<string, mixed>  $filters
     * @return array{users: \Illuminate\Database\Eloquent\Collection<int, \App\Models\User>, total: int, overall: int}
     */
    public function list(array $filters = []): array
    {
        $query = $this->query();
        $overall = (clone $query)->count();

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';

            $query->where(function (Builder $inner) use ($like): void {
                $inner->where('name', 'like', $like)->orWhere('email', 'like', $like);
            });
        }

        $role = $filters['role'] ?? null;

        if (in_array($role, self::roles(), true)) {
            $query->where('role', $role);
        }

        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        if ($from && $to && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        $query
            ->when($from, fn (Builder $inner) => $inner->whereDate('created_at', '>=', $from))
            ->when($to, fn (Builder $inner) => $inner->whereDate('created_at', '<=', $to));

        $total = (clone $query)->count();

        $users = $query
            ->with(['latestJoinRequest.organization', 'latestJoinRequest.branch'])
            ->orderByRaw('coalesce(left_organization_at, created_at) asc')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();

        $this->attachRecordCounts($users);

        return ['users' => $users, 'total' => $total, 'overall' => $overall];
    }

    /**
     * Delete an account that is in no organization and owns no records.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function delete(User $user): void
    {
        if ($user->isPlatform() || $user->hasOrganization()) {
            abort(404, 'Not Found.');
        }

        $owned = $this->recordCounts([$user->id])[$user->id];
        $parts = [];

        foreach ($owned as $kind => $count) {
            if ($count > 0) {
                $parts[] = $count.' '.str_replace('_', ' ', $kind);
            }
        }

        if ($parts !== []) {
            throw ValidationException::withMessages([
                'user' => [sprintf(
                    'This account still owns records (%s) that belong to its previous school, so it cannot be deleted.',
                    implode(', ', $parts)
                )],
            ]);
        }

        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();
            $user->delete();
        });
    }

    /**
     * Put a `records` attribute (counts per kind) on each user.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, \App\Models\User>  $users
     */
    protected function attachRecordCounts(Collection $users): void
    {
        $counts = $this->recordCounts($users->pluck('id')->all());

        foreach ($users as $user) {
            $user->setAttribute('records', $counts[$user->id]);
        }
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, array<string, int>>  user id => counts per record kind
     */
    protected function recordCounts(array $ids): array
    {
        $counts = array_fill_keys($ids, array_fill_keys(array_keys(self::RECORDS), 0));

        if ($ids === []) {
            return $counts;
        }

        foreach (self::RECORDS as $kind => [$model, $column]) {
            $rows = $model::query()
                ->whereIn($column, $ids)
                ->selectRaw("{$column} as owner_id, count(*) as total")
                ->groupBy($column)
                ->pluck('total', 'owner_id');

            foreach ($rows as $ownerId => $total) {
                $counts[(int) $ownerId][$kind] = (int) $total;
            }
        }

        return $counts;
    }
}
