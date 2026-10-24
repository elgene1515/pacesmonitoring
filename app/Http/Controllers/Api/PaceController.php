<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaceResource;
use App\Models\Pace;
use App\Services\PaceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A teacher's view of their branch's PACE catalog (route binding is limited
 * to it): each PACE with the pool and the teacher's own booklets. Admins
 * manage the catalog and the stock.
 */
class PaceController extends Controller
{
    public function __construct(private readonly PaceService $paces)
    {
    }

    /**
     * The branch catalog (filter: search).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return PaceResource::collection(
            $this->paces->list($request->user(), $request->only(['search']))
        );
    }

    /**
     * A PACE with the records of the teacher's students it was assigned to.
     */
    public function show(Request $request, Pace $pace): PaceResource
    {
        return new PaceResource($this->paces->detail($pace, $request->user()));
    }
}
