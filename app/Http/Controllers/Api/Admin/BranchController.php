<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ResolvesOrganizationScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreBranchRequest;
use App\Http\Requests\Organization\UpdateBranchRequest;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use App\Services\OrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class BranchController extends Controller
{
    use ResolvesOrganizationScope;

    public function __construct(private readonly OrganizationService $organizations)
    {
    }

    /**
     * The branches of the organization in scope (every organization's for an unscoped owner).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $organizationId = $this->scopeOrganizationId($request);

        $branches = Branch::query()
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->withCount(['teachers', 'users'])
            ->orderBy('organization_id')
            ->orderByDesc('is_main')
            ->orderBy('name')
            ->get();

        return BranchResource::collection($branches);
    }

    /**
     * Add a branch (within the plan's branch limit).
     */
    public function store(StoreBranchRequest $request): JsonResponse
    {
        $branch = $this->organizations->createBranch($this->scopeOrganization($request), $request->branchData());

        return (new BranchResource($branch))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Rename a branch or make it the main one.
     */
    public function update(UpdateBranchRequest $request, Branch $branch): BranchResource
    {
        $this->assertInScope($request, $branch->organization_id);

        return new BranchResource($this->organizations->updateBranch($branch, $request->branchData()));
    }

    /**
     * Remove an empty, non-main branch.
     */
    public function destroy(Request $request, Branch $branch): Response
    {
        $this->assertInScope($request, $branch->organization_id);

        $this->organizations->deleteBranch($branch);

        return response()->noContent();
    }
}
