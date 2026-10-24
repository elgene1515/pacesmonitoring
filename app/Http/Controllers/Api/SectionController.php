<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SectionResource;
use App\Models\Section;
use App\Services\GradeLevelService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The sections a teacher teaches in (route binding is limited to those):
 * their rosters and who teaches what.
 */
class SectionController extends Controller
{
    public function __construct(private readonly GradeLevelService $gradeLevels)
    {
    }

    /**
     * The teacher's sections with their grade level and school year.
     * `?grade_level_id=` limits the list to one grade level.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'grade_level_id' => ['nullable', 'integer'],
        ]);

        $sections = Section::query()
            ->ownedBy($request->user())
            ->with(['gradeLevel.schoolYear', 'teachingAssignments.teacher', 'teachingAssignments.subject'])
            ->withCount(['students' => fn ($students) => $students->ownedBy($request->user())])
            ->when(
                isset($filters['grade_level_id']),
                fn ($query) => $query->where('grade_level_id', (int) $filters['grade_level_id'])
            )
            ->get()
            ->sortBy([
                fn (Section $a, Section $b) => strcmp((string) $b->gradeLevel->schoolYear?->name, (string) $a->gradeLevel->schoolYear?->name),
                fn (Section $a, Section $b) => $a->gradeLevel->sort_order <=> $b->gradeLevel->sort_order,
                fn (Section $a, Section $b) => $a->sort_order <=> $b->sort_order,
                fn (Section $a, Section $b) => strcmp($a->name, $b->name),
            ])
            ->values();

        return SectionResource::collection($sections);
    }

    /**
     * A section with its roster, grade level (subjects, school year) and who teaches what.
     */
    public function show(Request $request, Section $section): SectionResource
    {
        return new SectionResource($this->gradeLevels->sectionDetail($section, $request->user()));
    }
}
