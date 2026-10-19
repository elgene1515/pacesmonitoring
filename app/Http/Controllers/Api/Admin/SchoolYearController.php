<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ResolvesOrganizationScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\SchoolYearResource;
use App\Models\User;
use App\Services\Admin\AdminSchoolYearService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class SchoolYearController extends Controller
{
    use ResolvesOrganizationScope;

    public function __construct(private readonly AdminSchoolYearService $schoolYears)
    {
    }

    /**
     * The school years of the organization in scope. `?teacher_id=` (kept for
     * the filter cascades) picks that teacher's organization instead.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $organizationId = $this->scopeOrganizationId($request);

        $filters = $request->validate([
            'teacher_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->when($organizationId !== null, fn ($rule) => $rule->where('organization_id', $organizationId)),
            ],
        ]);

        if (isset($filters['teacher_id'])) {
            $organizationId = User::query()->whereKey((int) $filters['teacher_id'])->value('organization_id');
            $organizationId = $organizationId === null ? 0 : (int) $organizationId;
        }

        return SchoolYearResource::collection($this->schoolYears->listFor($organizationId));
    }
}
