<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pace\AdjustPaceStockRequest;
use App\Http\Requests\Pace\StorePaceRangeRequest;
use App\Http\Requests\Pace\StorePaceRequest;
use App\Http\Requests\Pace\UpdatePaceRequest;
use App\Http\Resources\PaceResource;
use App\Models\Pace;
use App\Services\PaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class PaceController extends Controller
{
    public function __construct(private readonly PaceService $paces)
    {
    }

    /**
     * The current user's PACEs (filter: search).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return PaceResource::collection(
            $this->paces->list($request->user(), $request->only(['search']))
        );
    }

    /**
     * Register a new PACE.
     */
    public function store(StorePaceRequest $request): JsonResponse
    {
        $pace = $this->paces->create($request->paceData(), $request->user());

        return (new PaceResource($pace))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Register a PACE for every number in a start-end range, all sharing one name.
     */
    public function storeRange(StorePaceRangeRequest $request): JsonResponse
    {
        $paces = $this->paces->createRange($request->rangeData(), $request->user());

        return PaceResource::collection($paces)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * A single PACE with every student it has been assigned to.
     */
    public function show(Pace $pace): PaceResource
    {
        return new PaceResource($this->paces->detail($pace));
    }

    /**
     * Update a PACE's name, number or stock.
     */
    public function update(UpdatePaceRequest $request, Pace $pace): PaceResource
    {
        return new PaceResource(
            $this->paces->update($pace, $request->paceData())
        );
    }

    /**
     * Add or remove booklets from the PACE's stock.
     */
    public function adjustStock(AdjustPaceStockRequest $request, Pace $pace): PaceResource
    {
        return new PaceResource(
            $this->paces->adjustStock($pace, $request->delta())
        );
    }

    /**
     * Remove a PACE and its assignments.
     */
    public function destroy(Pace $pace): Response
    {
        $this->paces->delete($pace);

        return response()->noContent();
    }
}
