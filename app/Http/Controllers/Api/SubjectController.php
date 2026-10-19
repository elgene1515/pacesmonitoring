<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Subject\StoreSubjectRequest;
use App\Http\Requests\Subject\UpdateSubjectRequest;
use App\Http\Resources\SubjectResource;
use App\Models\GradeLevel;
use App\Models\Subject;
use App\Services\GradeLevelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Subjects live under a grade level and inherit its owner; only the current
 * user's subjects are ever visible here.
 */
class SubjectController extends Controller
{
    public function __construct(private readonly GradeLevelService $gradeLevels)
    {
    }

    /**
     * The current user's subjects, optionally limited to a single grade level.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'grade_level_id' => [
                'nullable',
                'integer',
                Rule::exists('grade_levels', 'id')->where('user_id', $request->user()->id),
            ],
        ]);

        $subjects = Subject::query()
            ->ownedBy($request->user())
            ->withCount('assignments')
            ->when(
                isset($filters['grade_level_id']),
                fn ($query) => $query->where('grade_level_id', (int) $filters['grade_level_id'])
            )
            ->orderBy('grade_level_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return SubjectResource::collection($subjects);
    }

    /**
     * Add a subject under one of the current user's grade levels.
     */
    public function store(StoreSubjectRequest $request): JsonResponse
    {
        $gradeLevel = GradeLevel::query()
            ->ownedBy($request->user())
            ->findOrFail((int) $request->validated('grade_level_id'));

        $subject = $this->gradeLevels->createSubject($gradeLevel, $request->subjectData());

        return (new SubjectResource($subject))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * A single subject with its grade level and every PACE record filed under it.
     */
    public function show(Subject $subject): SubjectResource
    {
        return new SubjectResource($this->gradeLevels->subjectDetail($subject));
    }

    /**
     * Rename or reorder a subject.
     */
    public function update(UpdateSubjectRequest $request, Subject $subject): SubjectResource
    {
        return new SubjectResource(
            $this->gradeLevels->updateSubject($subject, $request->subjectData())
        );
    }

    /**
     * Remove a subject together with the PACE records filed under it.
     */
    public function destroy(Subject $subject): Response
    {
        $this->gradeLevels->deleteSubject($subject);

        return response()->noContent();
    }
}
