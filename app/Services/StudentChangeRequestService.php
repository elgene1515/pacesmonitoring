<?php

namespace App\Services;

use App\Enums\StudentChangeRequestStatus;
use App\Models\Student;
use App\Models\StudentChangeRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A teacher's proposed edit to a student's details, applied once an admin
 * accepts it.
 */
class StudentChangeRequestService
{
    public function __construct(private readonly StudentService $students)
    {
    }

    /**
     * Propose changes. Only the fields that differ from the student are kept;
     * one proposal per student may be pending at a time.
     *
     * @param  array<string, mixed>  $changes
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function propose(Student $student, User $teacher, array $changes): StudentChangeRequest
    {
        $changes = $this->diff($student, $changes);

        if ($changes === []) {
            throw ValidationException::withMessages([
                'changes' => ['Nothing is different from the current details.'],
            ]);
        }

        if ($student->changeRequests()->pending()->exists()) {
            throw ValidationException::withMessages([
                'changes' => ['A change for this student is already waiting for the administrator.'],
            ]);
        }

        $request = StudentChangeRequest::create([
            'student_id' => $student->id,
            'organization_id' => $teacher->organization_id,
            'requested_by' => $teacher->id,
            'changes' => $changes,
            'status' => StudentChangeRequestStatus::Pending,
        ]);

        return $request->load(['student.section.gradeLevel', 'requester']);
    }

    /**
     * A teacher's proposals, pending first then newest.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\StudentChangeRequest>
     */
    public function listFor(User $teacher): Collection
    {
        return $this->order(StudentChangeRequest::query()->where('requested_by', $teacher->id))
            ->with(['student.section.gradeLevel', 'requester', 'decider'])
            ->get();
    }

    /**
     * The proposals of an organization (every organization for the owner),
     * pending first. Filters: status.
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\StudentChangeRequest>
     */
    public function listForOrganization(?int $organizationId, array $filters = []): Collection
    {
        $query = StudentChangeRequest::query()
            ->when($organizationId !== null, fn (Builder $query) => $query->where('organization_id', $organizationId));

        $status = $filters['status'] ?? null;

        if (is_string($status) && in_array($status, StudentChangeRequestStatus::values(), true)) {
            $query->where('status', $status);
        }

        return $this->order($query)
            ->with(['student.section.gradeLevel', 'requester.branch', 'decider'])
            ->get();
    }

    /**
     * Apply the proposal to the student.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function accept(StudentChangeRequest $request, User $admin): StudentChangeRequest
    {
        $this->assertPending($request);

        return DB::transaction(function () use ($request, $admin): StudentChangeRequest {
            $changes = $request->changes;

            if (isset($changes['birth_date']) && ! isset($changes['age'])) {
                $changes['age'] = Carbon::parse($changes['birth_date'])->age;
            }

            $this->students->update($request->student, $changes);

            $request->forceFill([
                'status' => StudentChangeRequestStatus::Accepted,
                'decided_by' => $admin->id,
                'decided_at' => now(),
            ])->save();

            return $request->load(['student.section.gradeLevel', 'requester.branch', 'decider']);
        });
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    public function decline(StudentChangeRequest $request, User $admin, ?string $reason = null): StudentChangeRequest
    {
        $this->assertPending($request);

        $request->forceFill([
            'status' => StudentChangeRequestStatus::Declined,
            'decline_reason' => $reason,
            'decided_by' => $admin->id,
            'decided_at' => now(),
        ])->save();

        return $request->load(['student.section.gradeLevel', 'requester.branch', 'decider']);
    }

    /**
     * The teacher withdraws a pending proposal.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function cancel(StudentChangeRequest $request): StudentChangeRequest
    {
        $this->assertPending($request);

        $request->forceFill([
            'status' => StudentChangeRequestStatus::Cancelled,
            'decided_at' => now(),
        ])->save();

        return $request->load(['student.section.gradeLevel', 'requester']);
    }

    /**
     * Keep only changeable fields whose value differs from the student's.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    protected function diff(Student $student, array $changes): array
    {
        $kept = [];

        foreach (StudentChangeRequest::CHANGEABLE_FIELDS as $field) {
            if (! array_key_exists($field, $changes)) {
                continue;
            }

            $proposed = $changes[$field];
            $current = $student->{$field};

            if ($field === 'birth_date') {
                $current = $current?->format('Y-m-d');
            } elseif ($field === 'sex') {
                $current = $current?->value ?? $current;
            }

            $proposed = is_string($proposed) ? trim($proposed) : $proposed;
            $proposed = $proposed === '' ? null : $proposed;

            if ($proposed !== $current) {
                $kept[$field] = $proposed;
            }
        }

        return $kept;
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function assertPending(StudentChangeRequest $request): void
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages([
                'request' => ['This request has already been decided.'],
            ]);
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\StudentChangeRequest>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\StudentChangeRequest>
     */
    protected function order(Builder $query): Builder
    {
        return $query
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->orderByDesc('id');
    }
}
