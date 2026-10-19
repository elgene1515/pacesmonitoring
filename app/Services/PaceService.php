<?php

namespace App\Services;

use App\Models\Pace;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PaceService
{
    public function __construct(private readonly PaceStockService $stock)
    {
    }

    /**
     * Master list of the user's PACEs with assignment counts, ordered by number
     * (length first so that "9" sorts before "10").
     *
     * Supported filters: search (matches number or name).
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Pace>
     */
    public function list(User $user, array $filters = []): Collection
    {
        $query = Pace::query()
            ->ownedBy($user)
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
     * Register a new PACE owned by the given user.
     *
     * @param  array<string, mixed>  $data  name, number, stock?
     */
    public function create(array $data, User $creator): Pace
    {
        $pace = Pace::create($data + ['stock' => 0, 'created_by' => $creator->id]);

        return $pace->loadCount(Pace::counts());
    }

    /**
     * Register a PACE for every number in a start-end range (inclusive), all
     * sharing the same name and starting stock. Numbers keep the start value's
     * digit width, so "001"-"010" yields "001".."010" instead of dropping the
     * leading zeros.
     *
     * @param  array<string, mixed>  $data  {name, start, end, stock?}
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Pace>
     */
    public function createRange(array $data, User $creator): Collection
    {
        $width = strlen((string) $data['start']);
        $start = (int) $data['start'];
        $end = (int) $data['end'];
        $stock = (int) ($data['stock'] ?? 0);

        $paces = DB::transaction(function () use ($data, $creator, $start, $end, $width, $stock): array {
            return array_map(function (int $number) use ($data, $creator, $width, $stock): Pace {
                return Pace::create([
                    'name' => $data['name'],
                    'number' => str_pad((string) $number, $width, '0', STR_PAD_LEFT),
                    'stock' => $stock,
                    'created_by' => $creator->id,
                ]);
            }, range($start, $end));
        });

        return (new Collection($paces))->loadCount(Pace::counts());
    }

    /**
     * Update a PACE's name, number or stock.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Pace $pace, array $data): Pace
    {
        $pace->fill($data);
        $pace->save();

        return $pace->loadCount(Pace::counts());
    }

    /**
     * Add or remove booklets from a PACE's stock by hand.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function adjustStock(Pace $pace, int $delta): Pace
    {
        return $this->stock->adjust($pace, $delta)->loadCount(Pace::counts());
    }

    /**
     * Remove a PACE together with its assignments (cascaded by the database).
     */
    public function delete(Pace $pace): void
    {
        $pace->delete();
    }

    /**
     * Load the PACE with every assignment (student and subject included),
     * newest school year and quarter first.
     */
    public function detail(Pace $pace): Pace
    {
        return $pace->loadCount(Pace::counts())->load([
            'assignments' => function ($query): void {
                $query->with(['student.gradeLevel', 'subject'])
                    ->orderByDesc('school_year')
                    ->orderBy('quarter')
                    ->orderByDesc('id');
            },
        ]);
    }
}
