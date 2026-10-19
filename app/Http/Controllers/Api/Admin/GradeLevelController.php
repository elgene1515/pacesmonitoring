<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ResolvesOrganizationScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\GradeLevelResource;
use App\Http\Resources\SubjectResource;
use App\Services\Admin\AdminGradeLevelService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class GradeLevelController extends Controller
{
    use ResolvesOrganizationScope;

    public function __construct(private readonly AdminGradeLevelService $gradeLevels)
    {
    }

    /**
     * One teacher's grade levels with their subjects (`?teacher_id=` required).
     * `?school_year_id=` narrows the list to just that school year.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $organizationId = $this->scopeOrganizationId($request);

        $filters = $request->validate([
            'teacher_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->when($organizationId !== null, fn ($rule) => $rule->where('organization_id', $organizationId)),
            ],
            'school_year_id' => ['nullable', 'integer', Rule::exists('school_years', 'id')],
        ]);

        return GradeLevelResource::collection(
            $this->gradeLevels->listFor(
                (int) $filters['teacher_id'],
                isset($filters['school_year_id']) ? (int) $filters['school_year_id'] : null,
                $organizationId
            )
        );
    }

    /**
     * A grade level (of any teacher in scope) with its subjects and every record, read-only.
     *
     * Not route-model-bound: GradeLevel's implicit binding is owner-scoped to the
     * signed-in user (see OwnedByUser), which would always 404 for an admin.
     */
    public function show(Request $request, int $gradeLevel): GradeLevelResource
    {
        return new GradeLevelResource($this->gradeLevels->detail($gradeLevel, $this->scopeOrganizationId($request)));
    }

    /**
     * A subject (of any teacher in scope) with its grade level and every record, read-only.
     */
    public function showSubject(Request $request, int $subject): SubjectResource
    {
        return new SubjectResource($this->gradeLevels->subjectDetail($subject, $this->scopeOrganizationId($request)));
    }
}
