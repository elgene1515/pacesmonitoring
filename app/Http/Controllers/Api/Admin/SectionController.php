<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ResolvesOrganizationScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Section\PromoteSectionRequest;
use App\Http\Requests\Section\StoreSectionRequest;
use App\Http\Requests\Section\UpdateSectionRequest;
use App\Http\Requests\TeachingAssignment\AssignTeacherRequest;
use App\Http\Resources\SectionResource;
use App\Http\Resources\StudentResource;
use App\Http\Resources\TeachingAssignmentResource;
use App\Models\User;
use App\Services\Admin\AdminGradeLevelService;
use App\Services\Admin\PromotionService;
use App\Services\TeachingAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Sections of a grade level and who teaches what in them, admin side
 * (ids resolved within the scope).
 */
class SectionController extends Controller
{
    use ResolvesOrganizationScope;

    public function __construct(
        private readonly AdminGradeLevelService $gradeLevels,
        private readonly TeachingAssignmentService $teaching,
        private readonly PromotionService $promotion,
    ) {
    }

    /**
     * Add a section to a grade level.
     */
    public function store(StoreSectionRequest $request, int $gradeLevel): JsonResponse
    {
        $level = $this->gradeLevels->find($gradeLevel, $this->scopeOrganizationId($request));

        return (new SectionResource($this->gradeLevels->createSection($level, $request->sectionData())))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * A section with its roster, grade level and who teaches what.
     */
    public function show(Request $request, int $section): SectionResource
    {
        return new SectionResource($this->gradeLevels->sectionDetail($section, $this->scopeOrganizationId($request)));
    }

    /**
     * Promote: move the section's students to other sections (typically the
     * next school year's) in one go, each to their own destination; null
     * keeps a student where they are. Returns the counts and the moved students.
     */
    public function promote(PromoteSectionRequest $request, int $section): JsonResponse
    {
        $from = $this->gradeLevels->findSection($section, $this->scopeOrganizationId($request));

        $result = $this->promotion->promote($from, $request->moves(), $request->user());

        return response()->json([
            'data' => [
                'moved' => $result['moved'],
                'stayed' => $result['stayed'],
                'students' => StudentResource::collection($result['students']),
            ],
        ]);
    }

    /**
     * Rename or reorder a section.
     */
    public function update(UpdateSectionRequest $request, int $section): SectionResource
    {
        $record = $this->gradeLevels->findSection($section, $this->scopeOrganizationId($request));

        return new SectionResource($this->gradeLevels->updateSection($record, $request->sectionData()));
    }

    /**
     * Remove a section. Refused while it has students (422).
     */
    public function destroy(Request $request, int $section): Response
    {
        $this->gradeLevels->deleteSection($this->gradeLevels->findSection($section, $this->scopeOrganizationId($request)));

        return response()->noContent();
    }

    /**
     * Make the section offer one of its grade level's subjects.
     */
    public function offer(Request $request, int $section, int $subject): SectionResource
    {
        $organizationId = $this->scopeOrganizationId($request);

        return new SectionResource($this->gradeLevels->offerSubject(
            $this->gradeLevels->findSection($section, $organizationId),
            $this->gradeLevels->findSubject($subject, $organizationId)
        ));
    }

    /**
     * Stop offering a subject in the section. Refused (422) while a teacher is
     * assigned to it or students of the section take it.
     */
    public function withdraw(Request $request, int $section, int $subject): SectionResource
    {
        $organizationId = $this->scopeOrganizationId($request);

        return new SectionResource($this->gradeLevels->withdrawSubject(
            $this->gradeLevels->findSection($section, $organizationId),
            $this->gradeLevels->findSubject($subject, $organizationId)
        ));
    }

    /**
     * The teaching assignments in scope (`?branch_id=`, `?teacher_id=`, `?section_id=`).
     */
    public function assignments(Request $request): AnonymousResourceCollection
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
                Rule::exists('users', 'id')->when($organizationId !== null, fn ($rule) => $rule->where('organization_id', $organizationId)),
            ],
            'section_id' => ['nullable', 'integer', Rule::exists('sections', 'id')],
        ]);

        return TeachingAssignmentResource::collection($this->teaching->listForOrganization($organizationId, $filters));
    }

    /**
     * Give a section + subject to a teacher of the section's branch
     * (replacing whoever had it).
     */
    public function assign(AssignTeacherRequest $request, int $section, int $subject): JsonResponse
    {
        $organizationId = $this->scopeOrganizationId($request);
        $record = $this->gradeLevels->findSection($section, $organizationId);
        $subjectRecord = $this->gradeLevels->findSubject($subject, $organizationId);
        $teacher = User::query()->findOrFail($request->teacherId());

        $this->assertUserInScope($request, $teacher);

        // Always 200: the slot is set, whether the row was created or replaced.
        return (new TeachingAssignmentResource(
            $this->teaching->assign($record, $subjectRecord, $teacher, $request->user())
        ))->response()->setStatusCode(Response::HTTP_OK);
    }

    /**
     * Take a section + subject away from its teacher.
     */
    public function unassign(Request $request, int $section, int $subject): Response
    {
        $organizationId = $this->scopeOrganizationId($request);

        $this->teaching->unassign(
            $this->gradeLevels->findSection($section, $organizationId),
            $this->gradeLevels->findSubject($subject, $organizationId)
        );

        return response()->noContent();
    }
}
