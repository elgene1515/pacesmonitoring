<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ResolvesOrganizationScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Subject\StoreSubjectRequest;
use App\Http\Requests\Subject\UpdateSubjectRequest;
use App\Http\Resources\SubjectResource;
use App\Services\Admin\AdminGradeLevelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Subjects of a grade level, admin side (ids resolved within the scope).
 */
class SubjectController extends Controller
{
    use ResolvesOrganizationScope;

    public function __construct(private readonly AdminGradeLevelService $gradeLevels)
    {
    }

    /**
     * Add a subject to a grade level (every section of it).
     */
    public function store(StoreSubjectRequest $request, int $gradeLevel): JsonResponse
    {
        $level = $this->gradeLevels->find($gradeLevel, $this->scopeOrganizationId($request));

        return (new SubjectResource($this->gradeLevels->createSubject($level, $request->subjectData())))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * A subject with its grade level, who teaches it per section, and every record, read-only.
     */
    public function show(Request $request, int $subject): SubjectResource
    {
        return new SubjectResource($this->gradeLevels->subjectDetail($subject, $this->scopeOrganizationId($request)));
    }

    /**
     * Rename or reorder a subject.
     */
    public function update(UpdateSubjectRequest $request, int $subject): SubjectResource
    {
        $record = $this->gradeLevels->findSubject($subject, $this->scopeOrganizationId($request));

        return new SubjectResource($this->gradeLevels->updateSubject($record, $request->subjectData()));
    }

    /**
     * Remove a subject together with its records and teaching assignments.
     */
    public function destroy(Request $request, int $subject): Response
    {
        $this->gradeLevels->deleteSubject($this->gradeLevels->findSubject($subject, $this->scopeOrganizationId($request)));

        return response()->noContent();
    }
}
