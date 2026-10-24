<?php

namespace App\Services;

use App\Enums\TeachingAssignmentRequestStatus;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\TeachingAssignmentRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who teaches which subject to which section. An admin assigns a teacher
 * directly, or a teacher asks for an open slot and the admin decides.
 */
class TeachingAssignmentService
{
    /**
     * A teacher's assignments, grouped naturally by school year, grade level and section.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\TeachingAssignment>
     */
    public function listFor(User $teacher): Collection
    {
        return TeachingAssignment::query()
            ->forTeacher($teacher)
            ->with(['section.gradeLevel.schoolYear', 'section.gradeLevel.branch', 'subject'])
            ->get()
            ->sortBy([
                fn (TeachingAssignment $a, TeachingAssignment $b) => strcmp((string) $b->section->gradeLevel->schoolYear?->name, (string) $a->section->gradeLevel->schoolYear?->name),
                fn (TeachingAssignment $a, TeachingAssignment $b) => $a->section->gradeLevel->sort_order <=> $b->section->gradeLevel->sort_order,
                fn (TeachingAssignment $a, TeachingAssignment $b) => $a->section->sort_order <=> $b->section->sort_order,
                fn (TeachingAssignment $a, TeachingAssignment $b) => $a->subject->sort_order <=> $b->subject->sort_order,
            ])
            ->values();
    }

    /**
     * The assignments of an organization (or of every organization for the
     * owner). Filters: branch_id, teacher_id, section_id.
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\TeachingAssignment>
     */
    public function listForOrganization(?int $organizationId, array $filters = []): Collection
    {
        return TeachingAssignment::query()
            ->when($organizationId !== null, fn (Builder $query) => $query->forOrganization($organizationId))
            ->when(! empty($filters['branch_id']), fn (Builder $query) => $query->whereHas('section', fn (Builder $section) => $section->forBranch((int) $filters['branch_id'])))
            ->when(! empty($filters['teacher_id']), fn (Builder $query) => $query->forTeacher((int) $filters['teacher_id']))
            ->when(! empty($filters['section_id']), fn (Builder $query) => $query->where('section_id', (int) $filters['section_id']))
            ->with(['section.gradeLevel.schoolYear', 'section.gradeLevel.branch', 'subject', 'teacher'])
            ->orderBy('section_id')
            ->orderBy('subject_id')
            ->get();
    }

    /**
     * Give a section + subject to a teacher (replacing whoever had it). The
     * teacher must belong to the section's branch; pending requests for the
     * slot are closed.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function assign(Section $section, Subject $subject, User $teacher, ?User $by = null): TeachingAssignment
    {
        $this->assertSubjectInSection($section, $subject);
        $this->assertTeacherInBranch($section, $teacher);

        return DB::transaction(function () use ($section, $subject, $teacher, $by): TeachingAssignment {
            $assignment = TeachingAssignment::query()->updateOrCreate(
                ['section_id' => $section->id, 'subject_id' => $subject->id],
                ['teacher_id' => $teacher->id, 'assigned_by' => $by?->id]
            );

            $pending = TeachingAssignmentRequest::query()
                ->pending()
                ->where('section_id', $section->id)
                ->where('subject_id', $subject->id);

            (clone $pending)->where('user_id', $teacher->id)->update([
                'status' => TeachingAssignmentRequestStatus::Accepted->value,
                'decided_by' => $by?->id,
                'decided_at' => now(),
            ]);

            (clone $pending)->where('user_id', '!=', $teacher->id)->update([
                'status' => TeachingAssignmentRequestStatus::Declined->value,
                'decline_reason' => 'The subject was assigned to another teacher.',
                'decided_by' => $by?->id,
                'decided_at' => now(),
            ]);

            return $assignment->load(['section.gradeLevel.schoolYear', 'subject', 'teacher']);
        });
    }

    /**
     * Take a section + subject away from its teacher (the slot becomes open).
     */
    public function unassign(Section $section, Subject $subject): void
    {
        TeachingAssignment::query()
            ->where('section_id', $section->id)
            ->where('subject_id', $subject->id)
            ->delete();
    }

    /* ----------------------------------------------------------- requests */

    /**
     * A teacher asks to teach a subject to a section (an open slot in their branch).
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function request(User $teacher, Section $section, Subject $subject, ?string $message = null): TeachingAssignmentRequest
    {
        $this->assertSubjectInSection($section, $subject);
        $this->assertTeacherInBranch($section, $teacher, 'section_id', 'That section is not in your branch.');

        $taken = TeachingAssignment::query()
            ->where('section_id', $section->id)
            ->where('subject_id', $subject->id)
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'subject_id' => ['This subject already has a teacher in that section.'],
            ]);
        }

        $duplicate = TeachingAssignmentRequest::query()
            ->pending()
            ->where('user_id', $teacher->id)
            ->where('section_id', $section->id)
            ->where('subject_id', $subject->id)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'subject_id' => ['You already asked to teach this subject in that section.'],
            ]);
        }

        $request = TeachingAssignmentRequest::create([
            'user_id' => $teacher->id,
            'organization_id' => $teacher->organization_id,
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'message' => $message,
            'status' => TeachingAssignmentRequestStatus::Pending,
        ]);

        return $request->load(['section.gradeLevel.schoolYear', 'subject']);
    }

    /**
     * A teacher's requests, pending first then newest.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\TeachingAssignmentRequest>
     */
    public function requestsFor(User $teacher): Collection
    {
        return $this->orderRequests(TeachingAssignmentRequest::query()->where('user_id', $teacher->id))
            ->with(['section.gradeLevel.schoolYear', 'subject', 'decider'])
            ->get();
    }

    /**
     * The requests of an organization (every organization for the owner),
     * pending first. Filters: status, branch_id.
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\TeachingAssignmentRequest>
     */
    public function requestsForOrganization(?int $organizationId, array $filters = []): Collection
    {
        $query = TeachingAssignmentRequest::query()
            ->when($organizationId !== null, fn (Builder $query) => $query->where('organization_id', $organizationId))
            ->when(! empty($filters['branch_id']), fn (Builder $query) => $query->whereHas('section', fn (Builder $section) => $section->forBranch((int) $filters['branch_id'])));

        $status = $filters['status'] ?? null;

        if (is_string($status) && in_array($status, TeachingAssignmentRequestStatus::values(), true)) {
            $query->where('status', $status);
        }

        return $this->orderRequests($query)
            ->with(['teacher.branch', 'section.gradeLevel.schoolYear', 'subject', 'decider'])
            ->get();
    }

    /**
     * Accept a pending request: the teacher gets the slot.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function accept(TeachingAssignmentRequest $request, User $admin): TeachingAssignment
    {
        $this->assertPending($request);

        return $this->assign($request->section, $request->subject, $request->teacher, $admin);
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    public function decline(TeachingAssignmentRequest $request, User $admin, ?string $reason = null): TeachingAssignmentRequest
    {
        $this->assertPending($request);

        $request->forceFill([
            'status' => TeachingAssignmentRequestStatus::Declined,
            'decline_reason' => $reason,
            'decided_by' => $admin->id,
            'decided_at' => now(),
        ])->save();

        return $request->load(['teacher.branch', 'section.gradeLevel.schoolYear', 'subject', 'decider']);
    }

    /**
     * The teacher withdraws a pending request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function cancel(TeachingAssignmentRequest $request): TeachingAssignmentRequest
    {
        $this->assertPending($request);

        $request->forceFill([
            'status' => TeachingAssignmentRequestStatus::Cancelled,
            'decided_at' => now(),
        ])->save();

        return $request->load(['section.gradeLevel.schoolYear', 'subject']);
    }

    /* ---------------------------------------------------------- membership */

    /**
     * A teacher leaving the organization loses every assignment and pending request.
     */
    public function clearFor(User $teacher): void
    {
        TeachingAssignment::query()->forTeacher($teacher)->delete();

        TeachingAssignmentRequest::query()
            ->pending()
            ->where('user_id', $teacher->id)
            ->update(['status' => TeachingAssignmentRequestStatus::Cancelled->value, 'decided_at' => now()]);
    }

    /**
     * After a move between branches, assignments in the old branch are dropped
     * (a section belongs to one branch's grade level).
     */
    public function clearOutsideBranch(User $teacher): void
    {
        if ($teacher->branch_id === null) {
            $this->clearFor($teacher);

            return;
        }

        TeachingAssignment::query()
            ->forTeacher($teacher)
            ->whereHas('section.gradeLevel', fn (Builder $level) => $level->where('branch_id', '!=', $teacher->branch_id))
            ->delete();

        TeachingAssignmentRequest::query()
            ->pending()
            ->where('user_id', $teacher->id)
            ->whereHas('section.gradeLevel', fn (Builder $level) => $level->where('branch_id', '!=', $teacher->branch_id))
            ->update(['status' => TeachingAssignmentRequestStatus::Cancelled->value, 'decided_at' => now()]);
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function assertSubjectInSection(Section $section, Subject $subject): void
    {
        if ((int) $subject->grade_level_id !== (int) $section->grade_level_id) {
            throw ValidationException::withMessages([
                'subject_id' => ["The subject does not belong to the section's grade level."],
            ]);
        }

        if (! $section->offers($subject)) {
            throw ValidationException::withMessages([
                'subject_id' => ['That subject is not offered in this section.'],
            ]);
        }
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function assertTeacherInBranch(
        Section $section,
        User $teacher,
        string $field = 'teacher_id',
        string $message = "The teacher must belong to the section's branch."
    ): void {
        $branchId = $section->gradeLevel?->branch_id ?? $section->gradeLevel()->value('branch_id');

        if (! $teacher->isTeacher() || $teacher->branch_id === null || (int) $teacher->branch_id !== (int) $branchId) {
            throw ValidationException::withMessages([
                $field => [$message],
            ]);
        }
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function assertPending(TeachingAssignmentRequest $request): void
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages([
                'request' => ['This request has already been decided.'],
            ]);
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\TeachingAssignmentRequest>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\TeachingAssignmentRequest>
     */
    protected function orderRequests(Builder $query): Builder
    {
        return $query
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->orderByDesc('id');
    }
}
