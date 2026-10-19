<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\UserResource;
use App\Services\OrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Onboarding: an admin registers their organization; a teacher looks one up
 * by its code before asking to join.
 */
class OrganizationController extends Controller
{
    public function __construct(private readonly OrganizationService $organizations)
    {
    }

    /**
     * Register the signed-in admin's organization (with its branches and a
     * trial subscription) and attach the admin to it. Answers with the
     * refreshed user so the SPA can move on.
     */
    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $this->organizations->register($request->user(), $request->organizationData());

        return (new UserResource(AuthController::withMembership($request->user()->fresh())))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Find an organization by its public code (`?code=SCH-XXXXXX`), with the
     * branches a teacher can ask to join. Only the name and branches are
     * revealed, nothing about the organization's members or records.
     */
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:16'],
        ]);

        $organization = $this->organizations->lookup($data['code']);

        if ($organization === null) {
            return response()->json([
                'message' => 'No organization matches that code. Check it with your school administrator.',
                'errors' => ['code' => ['No organization matches that code.']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'data' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'code' => $organization->code,
                'branches' => $organization->branches->map(fn ($branch) => [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'code' => $branch->code,
                    'is_main' => (bool) $branch->is_main,
                ])->values(),
            ],
        ]);
    }
}
