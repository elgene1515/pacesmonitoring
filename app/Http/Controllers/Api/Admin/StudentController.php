<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ResolvesOrganizationScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Student\StoreStudentRequest;
use App\Http\Requests\Student\UpdateStudentRequest;
use App\Http\Resources\SchoolYearResource;
use App\Http\Resources\StudentResource;
use App\Services\Admin\AdminStudentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

/**
 * Students, admin side: every student of the sections in scope; enrolling,
 * editing, moving and removing them. Not route-model-bound (Student's
 * implicit binding is teacher-scoped, see OwnedByUser).
 */
class StudentController extends Controller
{
    use ResolvesOrganizationScope;

    public function __construct(private readonly AdminStudentService $students)
    {
    }

    /**
     * The masterlist (filters: search, teacher_id, branch_id, grade_level_id,
     * section_id, school_year_id).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return StudentResource::collection(
            $this->students->list(
                $request->only(['search', 'teacher_id', 'branch_id', 'grade_level_id', 'section_id', 'school_year_id']),
                $this->scopeOrganizationId($request)
            )
        );
    }

    /**
     * Enroll a student in a section of the organization.
     */
    public function store(StoreStudentRequest $request): JsonResponse
    {
        $section = $this->students->findSection((int) $request->validated('section_id'), $this->scopeOrganizationId($request));

        $student = $this->students->create($request->studentData(), $section, $request->user(), $request->subjectIds());

        return (new StudentResource($student))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * A student with everything the PACE Projection view needs, plus the
     * organization's school years for the school-year filter.
     */
    public function show(Request $request, int $student): JsonResource
    {
        $record = $this->students->detail($student, $this->scopeOrganizationId($request));

        return (new StudentResource($record))->additional([
            'school_years' => SchoolYearResource::collection($this->students->schoolYearsFor($record)),
        ]);
    }

    /**
     * Edit a student's details and/or move them to another section.
     */
    public function update(UpdateStudentRequest $request, int $student): StudentResource
    {
        $organizationId = $this->scopeOrganizationId($request);
        $record = $this->students->find($student, $organizationId);

        // The target section must be in scope too.
        $this->students->findSection((int) $request->validated('section_id'), $organizationId);

        return new StudentResource($this->students->update($record, $request->studentData(), $request->subjectIds(), $request->user()));
    }

    /**
     * Remove a student and their PACE records.
     */
    public function destroy(Request $request, int $student): Response
    {
        $this->students->delete($this->students->find($student, $this->scopeOrganizationId($request)));

        return response()->noContent();
    }
}
