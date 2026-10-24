<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\PaceRequestStatus;
use App\Http\Controllers\Api\Concerns\ResolvesOrganizationScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GivePaceRequestRequest;
use App\Http\Requests\Admin\UpdatePaceRequestRequest;
use App\Http\Resources\PaceRequestResource;
use App\Models\PaceRequest;
use App\Services\Admin\TeacherScope;
use App\Services\PaceRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The school admin's side of the teachers' PACE stock requests: see them,
 * adjust them, hand the booklets over or decline.
 */
class PaceRequestController extends Controller
{
    use ResolvesOrganizationScope;

    public function __construct(private readonly PaceRequestService $paceRequests)
    {
    }

    /**
     * The organization's requests, pending first (`?status=`, `?teacher_id=`, `?branch_id=` narrow;
     * `?search=` matches the teacher or the PACE; `?needed_from=` / `?needed_to=` bound the needed-by date).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(PaceRequestStatus::values())],
            'teacher_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'needed_from' => ['nullable', 'date_format:Y-m-d'],
            'needed_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return PaceRequestResource::collection(
            $this->paceRequests->listFor(
                TeacherScope::fromFilters($filters, $this->scopeOrganizationId($request)),
                $filters['status'] ?? null,
                $filters
            )
        );
    }

    /**
     * Adjust a pending request (PACE, quantity, needed-by date).
     */
    public function update(UpdatePaceRequestRequest $request, PaceRequest $paceRequest): PaceRequestResource
    {
        $this->assertInScope($request, $paceRequest->organization_id);

        return new PaceRequestResource($this->paceRequests->update($paceRequest, $request->requestData()));
    }

    /**
     * Hand the booklets over from the branch's pool (registering a new PACE in
     * the catalog first) and close the request.
     */
    public function give(GivePaceRequestRequest $request, PaceRequest $paceRequest): PaceRequestResource
    {
        $this->assertInScope($request, $paceRequest->organization_id);

        return new PaceRequestResource(
            $this->paceRequests->give($paceRequest, $request->user(), $request->validated())
        );
    }

    /**
     * Give or decline several pending requests in one go (`action` = give | decline, `ids`,
     * an optional `decision_note` for all). They are decided oldest first, each on its own:
     * the ones that cannot be (pool too small, PACE not in the catalog yet, already decided)
     * stay as they were and come back under `skipped` with the reason.
     */
    public function batch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', Rule::in(['give', 'decline'])],
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct'],
            'decision_note' => ['nullable', 'string', 'max:255'],
        ], [
            'ids.required' => 'Select at least one request.',
            'ids.min' => 'Select at least one request.',
            'ids.max' => 'Decide at most 100 requests at a time.',
        ]);

        $requests = PaceRequest::query()
            ->with(['teacher.branch', 'pace', 'organization'])
            ->whereIn('id', $data['ids'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        // A request that does not exist or is not in this admin's scope is "not found" for the whole batch.
        abort_if($requests->count() !== count($data['ids']), 404, 'Not Found.');

        foreach ($requests as $paceRequest) {
            $this->assertInScope($request, $paceRequest->organization_id);
        }

        $note = $data['decision_note'] ?? null;

        $result = $data['action'] === 'give'
            ? $this->paceRequests->giveMany($requests, $request->user(), $note)
            : $this->paceRequests->declineMany($requests, $request->user(), $note);

        return response()->json([
            'data' => [
                'done' => PaceRequestResource::collection($result['done'])->resolve($request),
                'skipped' => array_map(static fn (array $row): array => [
                    'id' => $row['request']->id,
                    'teacher_name' => $row['request']->teacher?->name ?? '',
                    'pace_number' => $row['request']->pace_number,
                    'pace_name' => $row['request']->pace_name,
                    'quantity' => (int) $row['request']->quantity,
                    'reason' => $row['reason'],
                ], $result['skipped']),
            ],
        ]);
    }

    /**
     * Turn the request down, with an optional note shown to the teacher.
     */
    public function decline(Request $request, PaceRequest $paceRequest): PaceRequestResource
    {
        $this->assertInScope($request, $paceRequest->organization_id);

        $data = $request->validate([
            'decision_note' => ['nullable', 'string', 'max:255'],
        ]);

        return new PaceRequestResource(
            $this->paceRequests->decline($paceRequest, $request->user(), $data['decision_note'] ?? null)
        );
    }
}
