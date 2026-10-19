<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ResolvesOrganizationScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\SchoolYearResource;
use App\Http\Resources\StudentResource;
use App\Services\Admin\AdminStudentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentController extends Controller
{
    use ResolvesOrganizationScope;

    public function __construct(private readonly AdminStudentService $students)
    {
    }

    /**
     * The masterlist of every student of the teachers in scope
     * (filters: search, teacher_id, branch_id, grade_level_id, school_year_id).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return StudentResource::collection(
            $this->students->list(
                $request->only(['search', 'teacher_id', 'branch_id', 'grade_level_id', 'school_year_id']),
                $this->scopeOrganizationId($request)
            )
        );
    }

    /**
     * A student (of any teacher in scope) with everything the PACE Projection
     * view needs, plus the organization's school years for the school-year filter.
     *
     * Not route-model-bound: Student's implicit binding is owner-scoped to the
     * signed-in user (see OwnedByUser), which would always 404 for an admin.
     */
    public function show(Request $request, int $student): JsonResource
    {
        $record = $this->students->detail($student, $this->scopeOrganizationId($request));

        return (new StudentResource($record))->additional([
            'school_years' => SchoolYearResource::collection($this->students->schoolYearsFor($record)),
        ]);
    }
}
