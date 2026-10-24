<?php

namespace App\Services;

use App\Enums\PaceStockMovementType;
use App\Models\Pace;
use App\Models\PaceRequest;
use App\Models\PaceStockHolding;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The stock ledger. A branch's booklets of a PACE are either in the pool
 * (`paces.pool_stock`, what the admin has) or held by a teacher. Stock only
 * enters through the pool; every pool/teacher move is logged. Issuing a
 * booklet to a student takes it from the teacher's holding; un-issuing or
 * deleting the record gives it back.
 */
class PaceStockService
{
    /**
     * A delivery arrived: add booklets to the pool.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function receive(Pace $pace, int $quantity, ?User $by = null, ?string $note = null): Pace
    {
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => ['Enter how many booklets arrived.']]);
        }

        return DB::transaction(function () use ($pace, $quantity, $by, $note): Pace {
            $locked = $this->lockPace($pace);
            $locked->pool_stock = (int) $locked->pool_stock + $quantity;
            $locked->save();

            $this->log($locked, PaceStockMovementType::Received, $quantity, by: $by, note: $note);

            return $this->sync($pace, $locked);
        });
    }

    /**
     * Correct the pool count (lost, damaged, miscounted). Never below zero.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function adjustPool(Pace $pace, int $delta, ?User $by = null, ?string $note = null): Pace
    {
        return DB::transaction(function () use ($pace, $delta, $by, $note): Pace {
            $locked = $this->lockPace($pace);
            $next = (int) $locked->pool_stock + $delta;

            if ($next < 0) {
                throw ValidationException::withMessages([
                    'delta' => [sprintf('The pool only has %d; it cannot go below zero.', $locked->pool_stock)],
                ]);
            }

            $locked->pool_stock = $next;
            $locked->save();

            $this->log($locked, PaceStockMovementType::Adjusted, $delta, by: $by, note: $note);

            return $this->sync($pace, $locked);
        });
    }

    /**
     * Hand booklets from the pool to a teacher of the branch.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function distribute(Pace $pace, User $teacher, int $quantity, ?User $by = null, ?PaceRequest $request = null, ?string $note = null): Pace
    {
        $this->assertTeacherInBranch($pace, $teacher);

        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => ['Enter how many booklets to give.']]);
        }

        return DB::transaction(function () use ($pace, $teacher, $quantity, $by, $request, $note): Pace {
            $locked = $this->lockPace($pace);

            if ((int) $locked->pool_stock < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => [sprintf('Only %d of PACE %s %s in the pool.', $locked->pool_stock, $locked->number, $locked->name)],
                ]);
            }

            $locked->pool_stock = (int) $locked->pool_stock - $quantity;
            $locked->save();

            $holding = $this->holding($locked, $teacher, lock: true);
            $holding->quantity = (int) $holding->quantity + $quantity;
            $holding->save();

            $this->log($locked, PaceStockMovementType::Distributed, $quantity, to: $teacher, by: $by, request: $request, note: $note);

            return $this->sync($pace, $locked);
        });
    }

    /**
     * Take booklets back from a teacher into the pool.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function takeBack(Pace $pace, User $teacher, int $quantity, ?User $by = null, ?string $note = null): Pace
    {
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => ['Enter how many booklets to take back.']]);
        }

        return DB::transaction(function () use ($pace, $teacher, $quantity, $by, $note): Pace {
            $locked = $this->lockPace($pace);
            $holding = $this->holding($locked, $teacher, lock: true);

            if ((int) $holding->quantity < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => [sprintf('%s only has %d of PACE %s %s.', $teacher->name, $holding->quantity, $locked->number, $locked->name)],
                ]);
            }

            $holding->quantity = (int) $holding->quantity - $quantity;
            $holding->save();

            $locked->pool_stock = (int) $locked->pool_stock + $quantity;
            $locked->save();

            $this->log($locked, PaceStockMovementType::Returned, $quantity, from: $teacher, by: $by, note: $note);

            return $this->sync($pace, $locked);
        });
    }

    /**
     * Set a teacher's holding to an exact count: the difference comes from,
     * or goes back to, the pool.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function setHolding(Pace $pace, User $teacher, int $quantity, ?User $by = null): Pace
    {
        if ($quantity < 0) {
            throw ValidationException::withMessages(['quantity' => ['The count cannot be negative.']]);
        }

        $current = $pace->stockFor($teacher);

        if ($quantity > $current) {
            return $this->distribute($pace, $teacher, $quantity - $current, $by);
        }

        if ($quantity < $current) {
            return $this->takeBack($pace, $teacher, $current - $quantity, $by);
        }

        return $pace;
    }

    /**
     * Everything a teacher holds goes back to the pools (they are leaving the
     * branch or the organization).
     */
    public function returnAll(User $teacher, ?User $by = null): void
    {
        $holdings = PaceStockHolding::query()->forTeacher($teacher)->where('quantity', '>', 0)->with('pace')->get();

        foreach ($holdings as $holding) {
            $this->takeBack($holding->pace, $teacher, (int) $holding->quantity, $by, 'Returned when the teacher left the branch.');
        }
    }

    /**
     * Take one booklet per record being issued from the teacher's holdings,
     * all or nothing. Call it inside the transaction that creates or updates
     * the records.
     *
     * @param  array<int, int>  $paceIds  one entry per record (a PACE may repeat)
     *
     * @throws \Illuminate\Validation\ValidationException  on `$field` when any PACE is short
     */
    public function consume(User $teacher, array $paceIds, string $field = 'issued_date'): void
    {
        if ($paceIds === []) {
            return;
        }

        $needed = array_count_values(array_map('intval', $paceIds));
        $paces = Pace::query()->whereIn('id', array_keys($needed))->get()->keyBy('id');
        $holdings = PaceStockHolding::query()
            ->forTeacher($teacher)
            ->whereIn('pace_id', array_keys($needed))
            ->lockForUpdate()
            ->get()
            ->keyBy('pace_id');
        $short = [];

        foreach ($needed as $id => $count) {
            $pace = $paces->get($id);
            $have = (int) ($holdings->get($id)?->quantity ?? 0);

            if ($pace === null || $have >= $count) {
                continue;
            }

            $short[] = $have === 0
                ? sprintf('you have no booklet of PACE #%s %s', $pace->number, $pace->name)
                : sprintf('you only have %d of PACE #%s %s', $have, $pace->number, $pace->name);
        }

        if ($short !== []) {
            throw ValidationException::withMessages([
                $field => [ucfirst(implode('; ', $short)).'. Assign it without an issued date, or ask your administrator for stock first.'],
            ]);
        }

        foreach ($needed as $id => $count) {
            $holdings->get($id)?->decrement('quantity', $count);
        }
    }

    /**
     * Give booklets back to the teacher (the record was un-issued or deleted).
     */
    public function release(User $teacher, ?int $paceId, int $count = 1): void
    {
        if ($paceId === null || $count <= 0) {
            return;
        }

        $this->holding($paceId, $teacher)->increment('quantity', $count);
    }

    /**
     * The teacher's holding row for a PACE, created empty when missing.
     */
    protected function holding(Pace|int $pace, User|int $teacher, bool $lock = false): PaceStockHolding
    {
        $paceId = $pace instanceof Pace ? $pace->getKey() : $pace;
        $teacherId = $teacher instanceof User ? $teacher->getKey() : $teacher;

        $holding = PaceStockHolding::query()->firstOrCreate(
            ['pace_id' => $paceId, 'user_id' => $teacherId],
            ['quantity' => 0]
        );

        return $lock ? PaceStockHolding::query()->whereKey($holding->id)->lockForUpdate()->firstOrFail() : $holding;
    }

    protected function lockPace(Pace $pace): Pace
    {
        return Pace::query()->whereKey($pace->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Copy the locked row's pool back onto the instance the caller holds.
     */
    protected function sync(Pace $pace, Pace $locked): Pace
    {
        $pace->pool_stock = $locked->pool_stock;

        return $pace;
    }

    protected function log(
        Pace $pace,
        PaceStockMovementType $type,
        int $quantity,
        ?User $from = null,
        ?User $to = null,
        ?User $by = null,
        ?PaceRequest $request = null,
        ?string $note = null,
    ): void {
        $pace->movements()->create([
            'type' => $type,
            'quantity' => $quantity,
            'from_user_id' => $from?->id,
            'to_user_id' => $to?->id,
            'performed_by' => $by?->id,
            'pace_request_id' => $request?->id,
            'note' => $note,
        ]);
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function assertTeacherInBranch(Pace $pace, User $teacher): void
    {
        if (! $teacher->isTeacher() || (int) $teacher->branch_id !== (int) $pace->branch_id) {
            throw ValidationException::withMessages([
                'teacher_id' => ["The teacher must belong to the PACE's branch."],
            ]);
        }
    }
}
