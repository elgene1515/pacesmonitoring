<?php

namespace App\Services;

use App\Enums\PaceRequestStatus;
use App\Models\Branch;
use App\Models\Pace;
use App\Models\PaceRequest;
use App\Models\User;
use App\Services\Admin\TeacherScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Teachers ask their school admin for PACE booklets, citing a PACE of the
 * branch's catalog or a number + name not in it yet. The admin may edit the
 * request, then gives booklets from the branch's pool (registering the PACE
 * in the catalog first when it is new) or declines it. The teacher can edit
 * or withdraw it while it is pending.
 */
class PaceRequestService
{
    public function __construct(
        private readonly PaceStockService $stock,
        private readonly PaceService $paces,
    ) {
    }

    /**
     * File a request. With a `pace_id` the number and name come from that
     * catalog entry; a number + name already in the branch's catalog is
     * linked to it automatically.
     *
     * @param  array<string, mixed>  $data  pace_id?, pace_number?, pace_name?, quantity, needed_by?, message?
     */
    public function submit(User $teacher, array $data): PaceRequest
    {
        $pace = isset($data['pace_id'])
            ? Pace::query()->ownedBy($teacher)->findOrFail((int) $data['pace_id'])
            : $this->findInCatalog($teacher->branch_id, $data['pace_number'] ?? null, $data['pace_name'] ?? null);

        $request = $teacher->paceRequests()->create([
            'organization_id' => $teacher->organization_id,
            'branch_id' => $teacher->branch_id,
            'pace_id' => $pace?->id,
            'pace_number' => $pace?->number ?? trim((string) ($data['pace_number'] ?? '')),
            'pace_name' => $pace?->name ?? trim((string) ($data['pace_name'] ?? '')),
            'quantity' => (int) $data['quantity'],
            'needed_by' => $data['needed_by'] ?? null,
            'message' => $this->clean($data['message'] ?? null),
            'status' => PaceRequestStatus::Pending,
        ]);

        return $this->fresh($request);
    }

    /**
     * Several requests in one go (one per PACE), all or nothing.
     *
     * @param  array<int, array<string, mixed>>  $items  each: pace_id?, pace_number?, pace_name?, quantity, needed_by?, message?
     * @return \Illuminate\Database\Eloquent\Collection<int, PaceRequest>
     */
    public function submitMany(User $teacher, array $items): Collection
    {
        return DB::transaction(function () use ($teacher, $items): Collection {
            $requests = new Collection;

            foreach ($items as $item) {
                $requests->push($this->submit($teacher, $item));
            }

            return $requests;
        });
    }

    /**
     * Change a pending request (the teacher corrects theirs; the admin adjusts
     * what is actually going to be handed over). Only the keys present are
     * applied. The link to a catalog entry follows the number + name.
     *
     * @param  array<string, mixed>  $data  pace_id?, pace_number?, pace_name?, quantity?, needed_by?, message?
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function update(PaceRequest $request, array $data): PaceRequest
    {
        $this->assertPending($request);

        foreach (['pace_number', 'pace_name'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $request->{$field} = trim((string) $data[$field]);
            }
        }

        if (array_key_exists('pace_id', $data)) {
            if ($data['pace_id'] === null || $data['pace_id'] === '') {
                $request->pace_id = null;
            } else {
                $pace = Pace::query()->ownedBy((int) $request->user_id)->findOrFail((int) $data['pace_id']);
                $request->pace_id = $pace->id;
                $request->pace_number = $pace->number;
                $request->pace_name = $pace->name;
            }
        }

        if (array_key_exists('quantity', $data)) {
            $request->quantity = (int) $data['quantity'];
        }

        if (array_key_exists('needed_by', $data)) {
            $request->needed_by = $data['needed_by'] ?: null;
        }

        if (array_key_exists('message', $data)) {
            $request->message = $this->clean($data['message']);
        }

        $missing = array_filter([
            'pace_number' => $request->pace_number === '' ? ['Enter the PACE number.'] : null,
            'pace_name' => $request->pace_name === '' ? ['Enter the PACE name.'] : null,
        ]);

        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }

        // The link holds only while the request still describes that catalog entry.
        $request->pace_id = $this->findInCatalog($request->branch_id, $request->pace_number, $request->pace_name)?->id;

        $request->save();

        return $this->fresh($request);
    }

    /**
     * The teacher withdraws a pending request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function cancel(PaceRequest $request): PaceRequest
    {
        $this->assertPending($request);

        $request->status = PaceRequestStatus::Cancelled;
        $request->decided_at = now();
        $request->save();

        return $this->fresh($request);
    }

    /**
     * The admin hands the booklets over from the branch's pool. A request for
     * a number + name not in the catalog registers it first. The quantity may
     * be less than asked (what is actually given), down to 0 when the PACE is
     * only being registered for now; it can never exceed the pool.
     *
     * @param  array<string, mixed>  $data  quantity? (what was actually given), decision_note?
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function give(PaceRequest $request, User $decider, array $data = []): PaceRequest
    {
        $this->assertPending($request);

        return DB::transaction(function () use ($request, $decider, $data): PaceRequest {
            if (array_key_exists('quantity', $data) && $data['quantity'] !== null) {
                $request->quantity = (int) $data['quantity'];
            }

            $teacher = $request->teacher;
            $branchId = $request->branch_id ?? $teacher?->branch_id;

            if ($teacher === null || $branchId === null) {
                throw ValidationException::withMessages([
                    'request' => ['The teacher no longer belongs to a branch.'],
                ]);
            }

            $pace = $request->pace_id !== null ? Pace::query()->find($request->pace_id) : null;

            if ($pace === null) {
                $pace = $this->paces->create(
                    Branch::query()->findOrFail($branchId),
                    ['number' => $request->pace_number, 'name' => $request->pace_name],
                    $decider
                );
                $request->pace_id = $pace->id;
            }

            if ((int) $request->quantity > 0) {
                $this->stock->distribute($pace, $teacher, (int) $request->quantity, $decider, $request, 'Stock request.');
            }

            $request->status = PaceRequestStatus::Given;
            $request->decision_note = $this->clean($data['decision_note'] ?? null);
            $request->decided_by = $decider->id;
            $request->decided_at = now();
            $request->save();

            return $this->fresh($request);
        });
    }

    /**
     * Give several requests in one go, oldest first, each in its own transaction:
     * a request the pool cannot cover (or one that is already decided, or whose
     * PACE is not in the catalog yet) stays as it was and is reported, so the rest
     * still go through. Each is given the quantity that was asked for.
     *
     * @param  iterable<\App\Models\PaceRequest>  $requests
     * @return array{done: \Illuminate\Database\Eloquent\Collection<int, \App\Models\PaceRequest>, skipped: array<int, array{request: \App\Models\PaceRequest, reason: string}>}
     */
    public function giveMany(iterable $requests, User $decider, ?string $note = null): array
    {
        return $this->decideMany($requests, function (PaceRequest $request) use ($decider, $note): PaceRequest {
            if ($request->isPending() && $request->pace_id === null) {
                throw ValidationException::withMessages([
                    'request' => ['This PACE is not in the catalog yet. Give it on its own: it needs stock in the pool first.'],
                ]);
            }

            return $this->give($request, $decider, ['decision_note' => $note]);
        });
    }

    /**
     * Decline several requests in one go with one optional note; already decided ones are reported and left alone.
     *
     * @param  iterable<\App\Models\PaceRequest>  $requests
     * @return array{done: \Illuminate\Database\Eloquent\Collection<int, \App\Models\PaceRequest>, skipped: array<int, array{request: \App\Models\PaceRequest, reason: string}>}
     */
    public function declineMany(iterable $requests, User $decider, ?string $note = null): array
    {
        return $this->decideMany($requests, fn (PaceRequest $request): PaceRequest => $this->decline($request, $decider, $note));
    }

    /**
     * Run one decision per request, collecting the outcomes (a validation failure skips that request only).
     *
     * @param  iterable<\App\Models\PaceRequest>  $requests
     * @param  callable(\App\Models\PaceRequest): \App\Models\PaceRequest  $decide
     * @return array{done: \Illuminate\Database\Eloquent\Collection<int, \App\Models\PaceRequest>, skipped: array<int, array{request: \App\Models\PaceRequest, reason: string}>}
     */
    protected function decideMany(iterable $requests, callable $decide): array
    {
        $done = new Collection;
        $skipped = [];

        foreach ($requests as $request) {
            try {
                $done->push($decide($request));
            } catch (ValidationException $exception) {
                $skipped[] = [
                    'request' => $request->fresh(['teacher', 'pace']) ?? $request,
                    'reason' => (string) collect($exception->errors())->flatten()->first(),
                ];
            }
        }

        return ['done' => $done, 'skipped' => $skipped];
    }

    /**
     * The admin turns the request down (with an optional note shown to the teacher).
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function decline(PaceRequest $request, User $decider, ?string $note = null): PaceRequest
    {
        $this->assertPending($request);

        $request->status = PaceRequestStatus::Declined;
        $request->decision_note = $this->clean($note);
        $request->decided_by = $decider->id;
        $request->decided_at = now();
        $request->save();

        return $this->fresh($request);
    }

    /**
     * One teacher's requests, pending first, newest first.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\PaceRequest>
     */
    public function listForTeacher(User $teacher, ?string $status = null): Collection
    {
        return $this->ordered(
            PaceRequest::query()->where('user_id', $teacher->id)->with(['pace', 'decider']),
            $status
        )->get();
    }

    /**
     * The requests in scope (one organization, optionally one branch or one
     * teacher; every organization for platform staff), pending first.
     *
     * Supported filters: search (the teacher's name or email, or the PACE number or
     * name), needed_from / needed_to ("Y-m-d", inclusive, on the needed-by date; a
     * reversed range is put in order, and requests with no needed-by date drop out
     * once either end is given).
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\PaceRequest>
     */
    public function listFor(TeacherScope $scope, ?string $status = null, array $filters = []): Collection
    {
        $query = PaceRequest::query()
            ->with(['teacher.branch', 'pace', 'decider', 'organization'])
            ->when($scope->organizationId !== null, fn ($query) => $query->where('organization_id', $scope->organizationId))
            ->when($scope->teacherId !== null, fn ($query) => $query->where('user_id', $scope->teacherId))
            ->when($scope->branchId !== null, fn ($query) => $query->where('branch_id', $scope->branchId));

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';

            $query->where(function ($inner) use ($like): void {
                $inner->where('pace_number', 'like', $like)
                    ->orWhere('pace_name', 'like', $like)
                    ->orWhereHas('teacher', fn ($teacher) => $teacher
                        ->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like));
            });
        }

        $from = $filters['needed_from'] ?? null;
        $to = $filters['needed_to'] ?? null;

        if ($from && $to && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        if ($from || $to) {
            $query->whereNotNull('needed_by')
                ->when($from, fn ($inner) => $inner->whereDate('needed_by', '>=', $from))
                ->when($to, fn ($inner) => $inner->whereDate('needed_by', '<=', $to));
        }

        return $this->ordered($query, $status)->get();
    }

    /**
     * The branch's catalog entry with this number + name, if any.
     */
    protected function findInCatalog(?int $branchId, ?string $number, ?string $name): ?Pace
    {
        $number = trim((string) $number);
        $name = trim((string) $name);

        if ($branchId === null || $number === '' || $name === '') {
            return null;
        }

        return Pace::query()
            ->forBranch($branchId)
            ->where('number', $number)
            ->where('name', $name)
            ->first();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\PaceRequest>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\PaceRequest>
     */
    protected function ordered($query, ?string $status)
    {
        return $query
            ->when($status !== null && $status !== '', fn ($query) => $query->where('status', $status))
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    protected function fresh(PaceRequest $request): PaceRequest
    {
        return $request->load(['teacher.branch', 'pace', 'decider', 'organization']);
    }

    protected function clean(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function assertPending(PaceRequest $request): void
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages([
                'request' => ['This request has already been decided.'],
            ]);
        }
    }
}
