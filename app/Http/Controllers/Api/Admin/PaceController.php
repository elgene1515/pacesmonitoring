<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ResolvesOrganizationScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pace\AdjustPoolRequest;
use App\Http\Requests\Pace\BatchStockRequest;
use App\Http\Requests\Pace\DistributeStockRequest;
use App\Http\Requests\Pace\ReceiveStockRequest;
use App\Http\Requests\Pace\SetHoldingRequest;
use App\Http\Requests\Pace\StorePaceRangeRequest;
use App\Http\Requests\Pace\StorePaceRequest;
use App\Http\Requests\Pace\UpdatePaceRequest;
use App\Http\Resources\PaceResource;
use App\Http\Resources\PaceStockMovementResource;
use App\Models\User;
use App\Services\Admin\AdminPaceService;
use App\Services\PaceService;
use App\Services\PaceStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The PACE catalogs and the stock ledger, admin side. Not route-model-bound
 * (Pace's implicit binding is teacher-scoped, see OwnedByUser).
 */
class PaceController extends Controller
{
    use ResolvesOrganizationScope;

    public function __construct(
        private readonly AdminPaceService $catalog,
        private readonly PaceService $paces,
        private readonly PaceStockService $stock,
    ) {
    }

    /**
     * The catalogs in scope with the pool and every holding
     * (filters: search, branch_id, teacher_id).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return PaceResource::collection(
            $this->catalog->list($request->only(['search', 'branch_id', 'teacher_id']), $this->scopeOrganizationId($request))
        );
    }

    /**
     * Register a PACE in a branch's catalog (with booklets in the pool, if any arrived).
     */
    public function store(StorePaceRequest $request): JsonResponse
    {
        $branch = $this->catalog->findBranch((int) $request->validated('branch_id'), $this->scopeOrganizationId($request));

        return (new PaceResource($this->paces->create($branch, $request->paceData(), $request->user())))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Register a run of PACE numbers in a branch's catalog, all with one name.
     */
    public function storeRange(StorePaceRangeRequest $request): JsonResponse
    {
        $branch = $this->catalog->findBranch((int) $request->validated('branch_id'), $this->scopeOrganizationId($request));

        return PaceResource::collection($this->paces->createRange($branch, $request->rangeData(), $request->user()))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * A PACE with the pool, every teacher's booklets and every assignment.
     */
    public function show(Request $request, int $pace): PaceResource
    {
        return new PaceResource($this->catalog->detail($pace, $this->scopeOrganizationId($request)));
    }

    /**
     * Rename or renumber a PACE.
     */
    public function update(UpdatePaceRequest $request, int $pace): PaceResource
    {
        $record = $this->catalog->find($pace, $this->scopeOrganizationId($request));

        return new PaceResource($this->paces->update($record, $request->paceData()));
    }

    /**
     * Remove a PACE with its assignments and stock history.
     */
    public function destroy(Request $request, int $pace): Response
    {
        $this->paces->delete($this->catalog->find($pace, $this->scopeOrganizationId($request)));

        return response()->noContent();
    }

    /* -------------------------------------------------------------- stock */

    /**
     * A delivery arrived: add booklets to the pool.
     */
    public function receive(ReceiveStockRequest $request, int $pace): PaceResource
    {
        $record = $this->catalog->find($pace, $this->scopeOrganizationId($request));

        return new PaceResource($this->paces->fresh(
            $this->stock->receive($record, $request->quantity(), $request->user(), $request->note())
        ));
    }

    /**
     * Correct the pool count (lost, damaged, miscounted).
     */
    public function adjustPool(AdjustPoolRequest $request, int $pace): PaceResource
    {
        $record = $this->catalog->find($pace, $this->scopeOrganizationId($request));

        return new PaceResource($this->paces->fresh(
            $this->stock->adjustPool($record, $request->delta(), $request->user(), $request->note())
        ));
    }

    /**
     * One pool change for several PACEs, all or nothing: receive booklets,
     * add / remove a number, or set every pool to an exact count (a recount).
     */
    public function batchStock(BatchStockRequest $request): AnonymousResourceCollection
    {
        $organizationId = $this->scopeOrganizationId($request);
        $records = collect($request->paceIds())->map(fn (int $id) => $this->catalog->find($id, $organizationId));
        $quantity = $request->quantity();
        $mode = $request->mode();

        $updated = DB::transaction(fn () => $records->map(function ($record) use ($request, $quantity, $mode) {
            try {
                if ($mode === 'receive') {
                    $this->stock->receive($record, $quantity, $request->user(), $request->note());
                } elseif ($mode === 'adjust') {
                    $this->stock->adjustPool($record, $quantity, $request->user(), $request->note());
                } else {
                    $delta = $quantity - (int) $record->pool_stock;

                    if ($delta !== 0) {
                        $this->stock->adjustPool(
                            $record,
                            $delta,
                            $request->user(),
                            $request->note() ?? sprintf('Recount: pool set to %d (was %d)', $quantity, $record->pool_stock)
                        );
                    }
                }
            } catch (ValidationException $e) {
                throw ValidationException::withMessages([
                    'quantity' => [sprintf('PACE %s: %s', $record->number, collect($e->errors())->flatten()->first())],
                ]);
            }

            return $this->paces->fresh($record->refresh());
        }));

        return PaceResource::collection($updated);
    }

    /**
     * Hand booklets from the pool to a teacher of the branch.
     */
    public function distribute(DistributeStockRequest $request, int $pace): PaceResource
    {
        $record = $this->catalog->find($pace, $this->scopeOrganizationId($request));
        $teacher = User::query()->findOrFail($request->teacherId());

        $this->assertUserInScope($request, $teacher);

        return new PaceResource($this->paces->fresh(
            $this->stock->distribute($record, $teacher, $request->quantity(), $request->user(), null, $request->note())
        ));
    }

    /**
     * Set a teacher's booklet count; the difference comes from or returns to the pool.
     */
    public function setHolding(SetHoldingRequest $request, int $pace, int $teacher): PaceResource
    {
        $record = $this->catalog->find($pace, $this->scopeOrganizationId($request));
        $user = User::query()->findOrFail($teacher);

        $this->assertUserInScope($request, $user);

        return new PaceResource($this->paces->fresh(
            $this->stock->setHolding($record, $user, $request->quantity(), $request->user())
        ));
    }

    /**
     * The stock ledger of a PACE, newest first.
     */
    public function movements(Request $request, int $pace): AnonymousResourceCollection
    {
        $record = $this->catalog->find($pace, $this->scopeOrganizationId($request));

        return PaceStockMovementResource::collection($this->paces->ledger($record));
    }
}
