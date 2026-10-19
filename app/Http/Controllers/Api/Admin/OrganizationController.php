<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ResolvesOrganizationScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Services\OrganizationService;
use Illuminate\Http\Request;

/**
 * The admin's own organization: details, branches, subscription state.
 */
class OrganizationController extends Controller
{
    use ResolvesOrganizationScope;

    public function __construct(private readonly OrganizationService $organizations)
    {
    }

    /**
     * The organization in scope with its branches, current subscription and member counts.
     */
    public function show(Request $request): OrganizationResource
    {
        return new OrganizationResource($this->organizations->detail($this->scopeOrganization($request)));
    }

    /**
     * Update the organization's name and contact details.
     */
    public function update(UpdateOrganizationRequest $request): OrganizationResource
    {
        return new OrganizationResource(
            $this->organizations->update($this->scopeOrganization($request), $request->organizationData())
        );
    }
}
