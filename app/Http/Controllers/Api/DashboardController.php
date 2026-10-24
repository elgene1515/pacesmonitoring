<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SchoolYearResource;
use App\Http\Resources\StudentResource;
use App\Models\GradeLevel;
use App\Models\Pace;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use App\Services\DashboardService;
use App\Services\SchoolYearService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly SchoolYearService $schoolYears,
    ) {
    }

    /**
     * Headline counts and the most recently enrolled students for the home
     * page, over what the teacher's assignments cover, in one school year.
     *
     * `?school_year_id=` picks the school year; `all` requests all-time totals
     * across every school year; omitting it uses the teacher's current school
     * year, and a teacher with no school years sees all-time counts either way.
     *
     * `?grade_level_id=`, `?section_id=` and `?subject_id=` narrow the figures
     * further to one of the grade levels the teacher teaches in (its students and
     * the records under its subjects), to one of their sections, and to one of
     * their subjects. `?pace_id=` narrows the records
     * (and the PACE count) to one PACE of the branch's catalog.
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        $scope = $request->validate([
            'grade_level_id' => ['nullable', 'integer', $this->visible(GradeLevel::query()->ownedBy($user), 'grade level')],
            'section_id' => ['nullable', 'integer', $this->visible(Section::query()->ownedBy($user), 'section')],
            'subject_id' => ['nullable', 'integer', $this->visible(Subject::query()->ownedBy($user), 'subject')],
            'pace_id' => ['nullable', 'integer', $this->visible(Pace::query()->ownedBy($user), 'PACE')],
        ]);

        if ($request->query('school_year_id') === 'all') {
            $schoolYear = null;
        } else {
            $filters = $request->validate([
                'school_year_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('school_years', 'id')->where('organization_id', $user->organization_id),
                ],
            ]);

            $schoolYear = isset($filters['school_year_id'])
                ? SchoolYear::query()->ownedBy($user)->find((int) $filters['school_year_id'])
                : $this->schoolYears->currentFor($user);
        }

        $summary = $this->dashboard->summary($user, $schoolYear, $scope);

        return response()->json([
            'data' => [
                'school_year' => $summary['school_year'] ? (new SchoolYearResource($summary['school_year']))->resolve() : null,
                'grade_levels' => $summary['grade_levels'],
                'sections' => $summary['sections'],
                'students' => $summary['students'],
                'paces' => $summary['paces'],
                'assignments' => $summary['assignments'],
                'tested' => $summary['tested'],
                'scored' => $summary['scored'],
                'passed' => $summary['passed'],
                'issued' => $summary['issued'],
                'assigned' => $summary['assigned'],
                'retakes' => $summary['retakes'],
                'failed_count' => $summary['failed_count'],
                'recent_students' => StudentResource::collection($summary['recent_students'])->resolve(),
                'by_quarter' => $summary['by_quarter'],
                'by_subject' => $summary['by_subject'],
                'by_grade_level' => $summary['by_grade_level'],
                'year_trend' => $summary['year_trend'],
                'failed' => $summary['failed'],
                'top_performers' => $summary['top_performers'],
                'score_distribution' => $summary['score_distribution'],
                'totals' => $summary['totals'],
            ],
        ]);
    }

    /**
     * The id must be one of the records the teacher can see.
     */
    protected function visible(\Illuminate\Database\Eloquent\Builder $query, string $label): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($query, $label): void {
            if (! (clone $query)->whereKey((int) $value)->exists()) {
                $fail("The selected {$label} is invalid.");
            }
        };
    }
}
