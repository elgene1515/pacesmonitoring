<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Role;
use App\Http\Controllers\Api\Concerns\ResolvesOrganizationScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\SchoolYearResource;
use App\Http\Resources\StudentResource;
use App\Http\Resources\UserResource;
use App\Services\Admin\AdminDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    use ResolvesOrganizationScope;

    public function __construct(private readonly AdminDashboardService $dashboard)
    {
    }

    /**
     * Analytics for the admin Home page: the same figures and breakdowns a
     * teacher sees on their Home page, across every teacher of the
     * organization in scope, plus headline totals, recent students / users,
     * a per-school-year breakdown, the teacher activity ranking and the
     * hardest PACEs.
     *
     * `?branch_id=`, `?teacher_id=`, `?school_year_id=`, `?grade_level_id=` and
     * `?subject_id=` and `?pace_id=` narrow the figures (the recent lists and the totals never
     * narrow). The owner may add `?organization_id=`.
     */
    public function summary(Request $request): JsonResponse
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
                Rule::exists('users', 'id')
                    ->where('role', Role::Teacher->value)
                    ->when($organizationId !== null, fn ($rule) => $rule->where('organization_id', $organizationId)),
            ],
            'school_year_id' => [
                'nullable',
                'integer',
                Rule::exists('school_years', 'id')->when($organizationId !== null, fn ($rule) => $rule->where('organization_id', $organizationId)),
            ],
            'grade_level_id' => ['nullable', 'integer', Rule::exists('grade_levels', 'id')],
            'subject_id' => ['nullable', 'integer', Rule::exists('subjects', 'id')],
            'pace_id' => ['nullable', 'integer', Rule::exists('paces', 'id')],
        ]);

        $summary = $this->dashboard->summary($filters, $organizationId);
        $scopeId = $summary['organization_id'];
        // The branch / teacher filters narrow the rankings and recent lists as well.
        $scope = $summary['scope'];

        $schoolYears = $this->dashboard->schoolYears($scopeId)->map(fn ($year) => [
            'id' => $year->id,
            'name' => $year->name,
            'is_current' => (bool) $year->is_current,
            'organization' => [
                'id' => $year->organization?->id,
                'name' => $year->organization?->name ?? '',
            ],
            'students_count' => $year->students_count,
            'grade_levels_count' => $year->grade_levels_count,
        ]);

        $teacherActivity = $this->dashboard->teacherActivity($scope)->map(fn ($teacher) => [
            'id' => $teacher->id,
            'name' => $teacher->name,
            'branch_name' => $teacher->branch?->name ?? '',
            'students_count' => $teacher->students_count,
            'assignments_count' => $teacher->assignments_count,
            'tested_count' => $teacher->tested_count,
            'issued_count' => $teacher->issued_count,
            'assigned_count' => $teacher->assignments_count - $teacher->tested_count - $teacher->issued_count,
        ]);

        return response()->json([
            'data' => [
                'organization_id' => $summary['organization_id'],
                'branch_id' => $summary['branch_id'],
                'teacher_id' => $summary['teacher_id'],
                'school_year' => $summary['school_year'] ? (new SchoolYearResource($summary['school_year']))->resolve() : null,
                'teachers' => $summary['teachers'],
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
                'by_quarter' => $summary['by_quarter'],
                'by_subject' => $summary['by_subject'],
                'by_grade_level' => $summary['by_grade_level'],
                'failed' => $summary['failed'],
                'top_performers' => $summary['top_performers'],
                'score_distribution' => $summary['score_distribution'],
                'hardest_paces' => $summary['hardest_paces'],
                'totals' => $summary['totals'],
                'recent_students' => StudentResource::collection($this->dashboard->recentStudents($scope))->resolve(),
                'recent_users' => UserResource::collection($this->dashboard->recentUsers($scope))->resolve(),
                'school_years' => $schoolYears,
                'teacher_activity' => $teacherActivity,
            ],
        ]);
    }
}
