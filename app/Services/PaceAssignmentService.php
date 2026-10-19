<?php

namespace App\Services;

use App\Models\PaceAssignment;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

class PaceAssignmentService
{
    public function __construct(private readonly PaceStockService $stock)
    {
    }

    /**
     * Assign one or more PACEs to a student under a single subject, school year and quarter.
     * With an issued date, every PACE is issued right away, which takes one booklet
     * from each PACE's stock (none is created when any of them is out of stock).
     *
     * Expected keys: subject_id, pace_ids (int[]), school_year, quarter, issued_date (nullable).
     *
     * @param  array<string, mixed>  $data
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\PaceAssignment>
     *
     * @throws \Illuminate\Validation\ValidationException  on `issued_date` when a PACE is out of stock
     */
    public function assignMany(Student $student, array $data): Collection
    {
        $paceIds = array_values(array_unique(array_map('intval', $data['pace_ids'] ?? [])));
        $issuedDate = $data['issued_date'] ?? null;

        $assignments = DB::transaction(function () use ($student, $data, $paceIds, $issuedDate): array {
            if ($issuedDate !== null) {
                $this->stock->consume($paceIds);
            }

            $created = [];

            foreach ($paceIds as $paceId) {
                $created[] = $student->assignments()->create([
                    'subject_id' => (int) $data['subject_id'],
                    'pace_id' => $paceId,
                    'school_year' => $data['school_year'],
                    'quarter' => (int) $data['quarter'],
                    'issued_date' => $issuedDate,
                ]);
            }

            return $created;
        });

        $ids = array_map(static fn (PaceAssignment $assignment): int => $assignment->id, $assignments);

        $query = PaceAssignment::query()->whereIn('pace_assignments.id', $ids)->with(['pace', 'subject']);
        $this->applyDefaultOrder($query);

        return $query->get();
    }

    /**
     * Update an assignment (pace, subject, period, dates, score, remarks).
     *
     * Issuing takes a booklet from the PACE's stock; un-issuing gives it back, and
     * moving an issued record to another PACE moves the booklet along with it.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws \Illuminate\Validation\ValidationException  on `issued_date` when the PACE is out of stock
     */
    public function update(PaceAssignment $assignment, array $data): PaceAssignment
    {
        return DB::transaction(function () use ($assignment, $data): PaceAssignment {
            $wasIssued = $assignment->issued_date !== null;
            $oldPaceId = $assignment->pace_id === null ? null : (int) $assignment->pace_id;

            $assignment->fill($data);

            $isIssued = $assignment->issued_date !== null;
            $newPaceId = $assignment->pace_id === null ? null : (int) $assignment->pace_id;

            if ($isIssued && (! $wasIssued || $newPaceId !== $oldPaceId)) {
                if ($wasIssued) {
                    $this->stock->release($oldPaceId);
                }

                $this->stock->consume($newPaceId === null ? [] : [$newPaceId]);
            } elseif ($wasIssued && ! $isIssued) {
                $this->stock->release($oldPaceId);
            }

            $assignment->save();

            return $assignment->load(['pace', 'subject']);
        });
    }

    /**
     * Remove a single assignment record, returning its booklet to stock when it was issued.
     */
    public function delete(PaceAssignment $assignment): void
    {
        DB::transaction(function () use ($assignment): void {
            if ($assignment->issued_date !== null) {
                $this->stock->release($assignment->pace_id === null ? null : (int) $assignment->pace_id);
            }

            $assignment->delete();
        });
    }

    /**
     * A student's assignments, optionally narrowed by school_year, quarter and subject_id.
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\PaceAssignment>
     */
    public function forStudent(Student $student, array $filters = []): Collection
    {
        $query = $student->assignments()->getQuery()->with(['pace', 'subject']);

        $schoolYear = trim((string) ($filters['school_year'] ?? ''));
        if ($schoolYear !== '') {
            $query->where('pace_assignments.school_year', $schoolYear);
        }

        $quarter = $filters['quarter'] ?? null;
        if ($quarter !== null && $quarter !== '') {
            $query->where('pace_assignments.quarter', (int) $quarter);
        }

        $subjectId = $filters['subject_id'] ?? null;
        if ($subjectId !== null && $subjectId !== '') {
            $query->where('pace_assignments.subject_id', (int) $subjectId);
        }

        $this->applyDefaultOrder($query);

        return $query->get();
    }

    /**
     * Order assignments by school year (newest first), quarter, subject order,
     * then PACE number (natural-ish: shorter numbers first).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\PaceAssignment>|\Illuminate\Database\Eloquent\Relations\Relation  $query
     */
    public function applyDefaultOrder(Builder|Relation $query): void
    {
        $query
            ->select('pace_assignments.*')
            ->join('subjects', 'subjects.id', '=', 'pace_assignments.subject_id')
            ->join('paces', 'paces.id', '=', 'pace_assignments.pace_id')
            ->orderByDesc('pace_assignments.school_year')
            ->orderBy('pace_assignments.quarter')
            ->orderBy('subjects.sort_order')
            ->orderBy('subjects.name')
            ->orderByRaw('LENGTH(paces.number), paces.number')
            ->orderBy('pace_assignments.id');
    }
}
