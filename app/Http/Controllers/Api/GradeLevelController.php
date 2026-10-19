<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GradeLevel\StoreGradeLevelRequest;
use App\Http\Requests\GradeLevel\UpdateGradeLevelRequest;
use App\Http\Resources\GradeLevelResource;
use App\Models\GradeLevel;
use App\Services\GradeLevelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Grade levels belong to the user who created them and are registered under
 * one school year; every action here only ever sees the current user's
 * levels (route binding is owner-scoped).
 */
class GradeLevelController extends Controller
{
    public function __construct(private readonly GradeLevelService $gradeLevels)
    {
    }

    /**
     * The current user's grade levels with their subjects, newest school year
     * first. `?school_year_id=` limits the list to one school year.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'school_year_id' => [
                'nullable',
                'integer',
                Rule::exists('school_years', 'id')->where('organization_id', $request->user()->organization_id),
            ],
        ]);

        return GradeLevelResource::collection(
            $this->gradeLevels->listFor($request->user(), $filters)
        );
    }

    /**
     * Create a grade level for the current user under one of their school years.
     */
    public function store(StoreGradeLevelRequest $request): JsonResponse
    {
        $gradeLevel = $this->gradeLevels->create($request->user(), $request->gradeLevelData());

        return (new GradeLevelResource($gradeLevel))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * A single grade level with its subjects and every PACE record filed under them.
     */
    public function show(GradeLevel $gradeLevel): GradeLevelResource
    {
        return new GradeLevelResource($this->gradeLevels->detail($gradeLevel));
    }

    /**
     * Rename or reorder a grade level.
     */
    public function update(UpdateGradeLevelRequest $request, GradeLevel $gradeLevel): GradeLevelResource
    {
        return new GradeLevelResource(
            $this->gradeLevels->update($gradeLevel, $request->gradeLevelData())
        );
    }

    /**
     * Remove a grade level and its subjects. Levels with students are refused (422).
     */
    public function destroy(GradeLevel $gradeLevel): Response
    {
        $this->gradeLevels->delete($gradeLevel);

        return response()->noContent();
    }
}
