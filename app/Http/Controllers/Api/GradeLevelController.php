<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GradeLevelResource;
use App\Models\GradeLevel;
use App\Services\GradeLevelService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * A teacher's view of the curriculum: the grade levels they teach in (route
 * binding is limited to those), read-only — admins set the curriculum up.
 */
class GradeLevelController extends Controller
{
    public function __construct(private readonly GradeLevelService $gradeLevels)
    {
    }

    /**
     * The grade levels the teacher teaches in, with the sections they teach
     * and every subject, newest school year first. `?school_year_id=` limits
     * the list to one school year.
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
     * The whole curriculum of the teacher's branch: every grade level with
     * every section and who teaches what, so a teacher can find an open slot
     * to ask for. `?school_year_id=` limits it to one school year.
     */
    public function branch(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $filters = $request->validate([
            'school_year_id' => [
                'nullable',
                'integer',
                Rule::exists('school_years', 'id')->where('organization_id', $user->organization_id),
            ],
        ]);

        if ($user->branch_id === null) {
            return GradeLevelResource::collection([]);
        }

        return GradeLevelResource::collection(
            $this->gradeLevels->listForBranch((int) $user->branch_id, $filters)
        );
    }

    /**
     * A grade level with its subjects, the sections the teacher teaches and
     * the records of their students.
     */
    public function show(Request $request, GradeLevel $gradeLevel): GradeLevelResource
    {
        return new GradeLevelResource($this->gradeLevels->detail($gradeLevel, $request->user()));
    }
}
