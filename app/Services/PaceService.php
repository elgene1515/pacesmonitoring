<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Pace;
use App\Models\PaceStockHolding;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A branch's PACE catalog. Admins register PACEs (a teacher's stock request
 * can add one too); teachers read their branch's catalog and see their own
 * booklets of each.
 */
class PaceService
{
    public function __construct(private readonly PaceStockService $stock)
    {
    }

    /**
     * The teacher's branch catalog with assignment counts, the pool and the
     * teacher's own booklets (`my_stock`), ordered by number.
     * Filters: search (number or name), stock (mine | pool | none).
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Pace>
     */
    public function list(User $teacher, array $filters = []): Collection
    {
        $query = $this->catalogQuery(Pace::query()->ownedBy($teacher), $filters)
            ->addSelect(['my_stock' => $this->holdingOf($teacher)]);

        return $query->get();
    }

    /**
     * A branch's catalog with the pool, every teacher's holding and the total
     * held. Filters: search, teacher_id (PACEs that teacher has booklets of).
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Pace>
     */
    public function listForBranch(Branch|int $branch, array $filters = []): Collection
    {
        $query = $this->catalogQuery(Pace::query()->forBranch($branch), $filters)
            ->withSum('holdings as holdings_total', 'quantity')
            ->with('holdings.teacher');

        $teacherId = $filters['teacher_id'] ?? null;

        if ($teacherId !== null && $teacherId !== '') {
            $query->whereHas('holdings', fn (Builder $holding) => $holding->where('user_id', (int) $teacherId)->where('quantity', '>', 0));
        }

        return $query->get();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Pace>  $query
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Pace>
     */
    public function catalogQuery(Builder $query, array $filters = []): Builder
    {
        $query
            ->select('paces.*')
            ->withCount(Pace::counts())
            ->orderByRaw('LENGTH(paces.number), paces.number')
            ->orderBy('paces.name');

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';

            $query->where(function (Builder $inner) use ($like): void {
                $inner->where('paces.number', 'like', $like)
                    ->orWhere('paces.name', 'like', $like);
            });
        }

        return $query;
    }

    /**
     * Sub-select of the given teacher's booklets of each PACE.
     *
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\PaceStockHolding>
     */
    public function holdingOf(User|int $teacher): Builder
    {
        return PaceStockHolding::query()
            ->select('quantity')
            ->whereColumn('pace_stock_holdings.pace_id', 'paces.id')
            ->forTeacher($teacher)
            ->limit(1);
    }

    /**
     * Register a PACE in a branch's catalog, with booklets in the pool when
     * some arrived with it. Returns the existing entry, untouched, when the
     * number + name is already there.
     *
     * @param  array<string, mixed>  $data  name, number, pool_stock?
     */
    public function create(Branch $branch, array $data, ?User $creator = null): Pace
    {
        $poolStock = (int) ($data['pool_stock'] ?? 0);
        unset($data['pool_stock']);

        $pace = DB::transaction(function () use ($branch, $data, $creator, $poolStock): Pace {
            $pace = Pace::query()->firstOrCreate(
                ['branch_id' => $branch->id, 'number' => $data['number'], 'name' => $data['name']],
                ['created_by' => $creator?->id, 'pool_stock' => 0]
            );

            if ($poolStock > 0 && $pace->wasRecentlyCreated) {
                $this->stock->receive($pace, $poolStock, $creator, 'Registered with the PACE.');
            }

            return $pace;
        });

        return $this->fresh($pace, $creator);
    }

    /**
     * Register a PACE for every number in a start-end range (inclusive), all
     * sharing one name and starting pool stock. Numbers keep the start value's
     * digit width, so "001"-"010" yields "001".."010". Numbers already in the
     * catalog under that name are left as they are.
     *
     * @param  array<string, mixed>  $data  name, start, end, pool_stock?
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Pace>
     */
    public function createRange(Branch $branch, array $data, ?User $creator = null): Collection
    {
        $width = strlen((string) $data['start']);
        $poolStock = (int) ($data['pool_stock'] ?? 0);

        $paces = DB::transaction(function () use ($branch, $data, $creator, $width, $poolStock): array {
            $created = [];

            foreach (range((int) $data['start'], (int) $data['end']) as $number) {
                $created[] = $this->create($branch, [
                    'name' => $data['name'],
                    'number' => str_pad((string) $number, $width, '0', STR_PAD_LEFT),
                    'pool_stock' => $poolStock,
                ], $creator);
            }

            return $created;
        });

        return new Collection($paces);
    }

    /**
     * Rename or renumber a PACE (the pool only changes through the ledger).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Pace $pace, array $data): Pace
    {
        unset($data['pool_stock'], $data['branch_id']);

        $pace->fill($data);
        $pace->save();

        return $this->fresh($pace);
    }

    /**
     * Remove a PACE with its assignments, holdings and ledger (cascaded).
     */
    public function delete(Pace $pace): void
    {
        $pace->delete();
    }

    /**
     * The PACE with every assignment (student and subject included), newest
     * school year and quarter first. For a teacher: only the records of
     * students in sections they teach, plus their own booklet count.
     */
    public function detail(Pace $pace, ?User $teacher = null): Pace
    {
        $pace->loadCount(array_merge(GradeLevelService::recordCounts($teacher), ['pendingPaceRequests']))->load([
            'branch.organization',
            'assignments' => function ($query) use ($teacher): void {
                $query
                    ->when($teacher !== null, fn ($records) => $records->whereHas('student', fn (Builder $student) => $student->ownedBy($teacher)))
                    ->with(['student.section.gradeLevel', 'subject'])
                    ->orderByDesc('school_year')
                    ->orderBy('quarter')
                    ->orderByDesc('id');
            },
        ]);

        if ($teacher !== null) {
            $pace->setAttribute('my_stock', $pace->stockFor($teacher));
        } else {
            $pace->load('holdings.teacher')->loadSum('holdings as holdings_total', 'quantity');
        }

        return $pace;
    }

    /**
     * The stock ledger of a PACE, newest first.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\PaceStockMovement>
     */
    public function ledger(Pace $pace, int $limit = 100): Collection
    {
        return $pace->movements()
            ->with(['fromTeacher', 'toTeacher', 'performer'])
            ->limit($limit)
            ->get();
    }

    /**
     * Reload the counts and holdings the catalog resources expose.
     */
    public function fresh(Pace $pace, ?User $teacher = null): Pace
    {
        $pace->loadCount(Pace::counts())->load('branch');

        if ($teacher !== null && $teacher->isTeacher()) {
            $pace->setAttribute('my_stock', $pace->stockFor($teacher));
        } else {
            $pace->load('holdings.teacher')->loadSum('holdings as holdings_total', 'quantity');
        }

        return $pace;
    }
}
