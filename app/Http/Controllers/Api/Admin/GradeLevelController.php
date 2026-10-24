<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Role;
use App\Http\Controllers\Api\Concerns\ResolvesOrganizationScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\GradeLevel\StoreGradeLevelRequest;
use App\Http\Requests\GradeLevel\UpdateGradeLevelRequest;
use App\Http\Resources\GradeLevelResource;
use App\Services\Admin\AdminGradeLevelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * The curriculum, admin side: the grade levels of the organization in scope
 * and their set-up. Not route-model-bound (GradeLevel's implicit binding is
 * teacher-scoped, see OwnedByUser); ids are resolved within the scope.
 */
class GradeLevelController extends Controller
{
    use ResolvesOrganizationScope;

    public function __construct(private readonly AdminGradeLevelService $gradeLevels)
    {
    }

    /**
     * The grade levels in scope with subjects, sections and teachers
     * (`?branch_id=`, `?teacher_id=`, `?school_year_id=` narrow the list).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $organizationId = $this->scopeOrganizationId($request);

        $filters = $request->validate([
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->when($organizationId !== null, fn ($rule) => $rule->where('organization_id', $organizationId)),
            ],
            'teacher_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')
                    ->where('role', Role::Teacher->value)
                    ->when($organizationId !== null, fn ($rule) => $rule->where('organization_id', $organizationId)),
            ],
            'school_year_id' => [
                'nullable',
                'integer',
                Rule::exists('school_years', 'id')->when($organizationId !== null, fn ($rule) => $rule->where('organization_id', $organizationId)),
            ],
        ]);

        return GradeLevelResource::collection($this->gradeLevels->list($filters, $organizationId));
    }

    /**
     * Register a grade level in one of the organization's branches.
     */
    public function store(StoreGradeLevelRequest $request): JsonResponse
    {
        $branch = $this->gradeLevels->findBranch((int) $request->validated('branch_id'), $this->scopeOrganizationId($request));

        $this->assertSchoolYearInOrganization($request, (int) $request->validated('school_year_id'), $branch->organization_id);

        $gradeLevel = $this->gradeLevels->create($branch, $request->gradeLevelData(), $request->user());

        return (new GradeLevelResource($gradeLevel))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * A grade level with its subjects, sections and every record, read-only.
     */
    public function show(Request $request, int $gradeLevel): GradeLevelResource
    {
        return new GradeLevelResource($this->gradeLevels->detail($gradeLevel, $this->scopeOrganizationId($request)));
    }

    /**
     * Rename or reorder a grade level.
     */
    public function update(UpdateGradeLevelRequest $request, int $gradeLevel): GradeLevelResource
    {
        $level = $this->gradeLevels->find($gradeLevel, $this->scopeOrganizationId($request));

        return new GradeLevelResource($this->gradeLevels->update($level, $request->gradeLevelData()));
    }

    /**
     * Remove a grade level with its subjects and sections. Refused while it has students (422).
     */
    public function destroy(Request $request, int $gradeLevel): Response
    {
        $this->gradeLevels->delete($this->gradeLevels->find($gradeLevel, $this->scopeOrganizationId($request)));

        return response()->noContent();
    }

    /**
     * The school year must be one of the branch's organization's.
     */
    protected function assertSchoolYearInOrganization(Request $request, int $schoolYearId, int $organizationId): void
    {
        $belongs = \App\Models\SchoolYear::query()->forOrganization($organizationId)->whereKey($schoolYearId)->exists();

        if (! $belongs) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'school_year_id' => ["The school year is not one of the school's school years."],
            ]);
        }
    }
}
