<?php

namespace App\Services;

use App\Enums\PaceRequestStatus;
use App\Models\Pace;
use App\Models\PaceRequest;
use App\Models\User;
use App\Services\Admin\TeacherScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Teachers ask their school admin for PACE booklets; the admin may edit the
 * request, then marks it given (topping up the linked PACE's stock) or
 * declines it. The teacher can edit or withdraw it while it is pending.
 */
class PaceRequestService
{
    public function __construct(private readonly PaceStockService $stock)
    {
    }

    /**
     * File a request. With a `pace_id` the number and name come from that PACE.
     *
     * @param  array<string, mixed>  $data  pace_id?, pace_number?, pace_name?, quantity, needed_by?, message?
     */
    public function submit(User $teacher, array $data): PaceRequest
    {
        $pace = isset($data['pace_id'])
            ? Pace::query()->ownedBy($teacher)->findOrFail((int) $data['pace_id'])
            : null;

        $request = $teacher->paceRequests()->create([
            'organization_id' => $teacher->organization_id,
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
     * Change a pending request (the teacher corrects theirs; the admin adjusts
     * what is actually going to be handed over). Only the keys present are
     * applied. The link to a registered PACE only holds while the request still
     * describes it: renaming or renumbering the request detaches it.
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

        if ($request->pace_id !== null) {
            $pace = $request->pace()->first();

            if ($pace === null || $pace->number !== $request->pace_number || $pace->name !== $request->pace_name) {
                $request->pace_id = null;
            }
        }

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
     * The admin handed the booklets over. When the request points at one of the
     * teacher's registered PACEs, the quantity is added to that PACE's stock.
     *
     * @param  array<string, mixed>  $data  quantity? (what was actually given), decision_note?
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function give(PaceRequest $request, User $decider, array $data = []): PaceRequest
    {
        $this->assertPending($request);

        return DB::transaction(function () use ($request, $decider, $data): PaceRequest {
            if (isset($data['quantity'])) {
                $request->quantity = (int) $data['quantity'];
            }

            $pace = $request->pace_id !== null ? Pace::query()->find($request->pace_id) : null;

            if ($pace !== null) {
                $this->stock->adjust($pace, (int) $request->quantity);
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
     * The requests of the teachers in scope (one organization, optionally one
     * branch or one teacher; every organization for platform staff), pending first.
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
            ->when($scope->branchId !== null, fn ($query) => $query->whereIn(
                'user_id',
                User::query()->where('branch_id', $scope->branchId)->select('id')
            ));

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
