<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubjectResource;
use App\Models\Subject;
use App\Services\GradeLevelService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A teacher's subjects: the ones they are assigned to teach (route binding
 * is limited to those), read-only.
 */
class SubjectController extends Controller
{
    public function __construct(private readonly GradeLevelService $gradeLevels)
    {
    }

    /**
     * The subjects the teacher is assigned, optionally limited to one grade level.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'grade_level_id' => ['nullable', 'integer'],
        ]);

        $subjects = Subject::query()
            ->ownedBy($request->user())
            ->withCount('assignments')
            ->with(['teachingAssignments' => fn ($query) => $query->forTeacher($request->user())->with('section')])
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
     * A subject with its grade level and the records of the teacher's students under it.
     */
    public function show(Request $request, Subject $subject): SubjectResource
    {
        return new SubjectResource($this->gradeLevels->subjectDetail($subject, $request->user()));
    }
}
