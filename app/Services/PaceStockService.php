<?php

namespace App\Services;

use App\Models\Pace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The booklets a teacher has on hand for each PACE. Issuing a PACE to a
 * student takes one; clearing the issued date or deleting the record gives
 * it back. The teacher adjusts the count by hand, and the admin adds the
 * booklets a stock request was fulfilled with.
 */
class PaceStockService
{
    /**
     * Add (or, when negative, remove) booklets. The count never drops below zero.
     *
     * @throws \Illuminate\Validation\ValidationException  on `stock`
     */
    public function adjust(Pace $pace, int $delta): Pace
    {
        return DB::transaction(function () use ($pace, $delta): Pace {
            $locked = Pace::query()->whereKey($pace->id)->lockForUpdate()->firstOrFail();
            $next = (int) $locked->stock + $delta;

            if ($next < 0) {
                throw ValidationException::withMessages([
                    'stock' => [sprintf('Only %d in stock; the count cannot go below zero.', $locked->stock)],
                ]);
            }

            $locked->stock = $next;
            $locked->save();

            $pace->stock = $next;

            return $locked;
        });
    }

    /**
     * Take one booklet per record being issued, all or nothing. Call it inside
     * the transaction that creates or updates the records.
     *
     * @param  array<int, int>  $paceIds  one entry per record (a PACE may repeat)
     *
     * @throws \Illuminate\Validation\ValidationException  on `$field` when any PACE is short
     */
    public function consume(array $paceIds, string $field = 'issued_date'): void
    {
        if ($paceIds === []) {
            return;
        }

        $needed = array_count_values(array_map('intval', $paceIds));
        $paces = Pace::query()->whereIn('id', array_keys($needed))->lockForUpdate()->get()->keyBy('id');
        $short = [];

        foreach ($needed as $id => $count) {
            $pace = $paces->get($id);

            if ($pace === null || (int) $pace->stock >= $count) {
                continue;
            }

            $short[] = (int) $pace->stock === 0
                ? sprintf('PACE #%s %s is out of stock', $pace->number, $pace->name)
                : sprintf('PACE #%s %s has only %d in stock', $pace->number, $pace->name, $pace->stock);
        }

        if ($short !== []) {
            throw ValidationException::withMessages([
                $field => [implode('; ', $short).'. Assign it without an issued date, or add stock first.'],
            ]);
        }

        foreach ($needed as $id => $count) {
            $paces->get($id)?->decrement('stock', $count);
        }
    }

    /**
     * Give booklets back (the record was un-issued or deleted).
     */
    public function release(?int $paceId, int $count = 1): void
    {
        if ($paceId === null || $count <= 0) {
            return;
        }

        Pace::query()->whereKey($paceId)->increment('stock', $count);
    }
}
