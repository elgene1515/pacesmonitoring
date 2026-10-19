<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ResolvesOrganizationScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaceResource;
use App\Services\Admin\AdminPaceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PaceController extends Controller
{
    use ResolvesOrganizationScope;

    public function __construct(private readonly AdminPaceService $paces)
    {
    }

    /**
     * Every PACE of the teachers in scope (filters: search, teacher_id, branch_id).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return PaceResource::collection(
            $this->paces->list($request->only(['search', 'teacher_id', 'branch_id']), $this->scopeOrganizationId($request))
        );
    }

    /**
     * A PACE (owned by any teacher in scope) with every assignment.
     *
     * Not route-model-bound: Pace's implicit binding is owner-scoped to the
     * signed-in user (see OwnedByUser), which would always 404 for an admin.
     */
    public function show(Request $request, int $pace): PaceResource
    {
        return new PaceResource($this->paces->detail($pace, $this->scopeOrganizationId($request)));
    }
}
