<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SchoolYearResource;
use App\Http\Resources\StudentResource;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Services\DashboardService;
use App\Services\SchoolYearService;
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
     * Headline counts and the most recently registered students for the home page,
     * limited to the current user's records in one school year.
     *
     * `?school_year_id=` picks the school year; `all` requests all-time totals
     * across every school year; omitting it uses the user's current school year,
     * and a user with no school years sees all-time counts either way.
     *
     * `?grade_level_id=` and `?subject_id=` narrow the figures further to one of
     * the user's grade levels (its students and the records under its subjects)
     * and to one subject's records. `?pace_id=` narrows the records (and the PACE
     * count) to one of the user's PACEs.
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        $scope = $request->validate([
            'grade_level_id' => [
                'nullable',
                'integer',
                Rule::exists('grade_levels', 'id')->where('user_id', $user->id),
            ],
            'subject_id' => [
                'nullable',
                'integer',
                Rule::exists('subjects', 'id')->whereIn(
                    'grade_level_id',
                    GradeLevel::query()->ownedBy($user)->pluck('id')->all()
                ),
            ],
            'pace_id' => [
                'nullable',
                'integer',
                Rule::exists('paces', 'id')->where('created_by', $user->id),
            ],
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
}
