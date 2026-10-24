<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\GradeLevel;
use App\Models\Organization;
use App\Models\Pace;
use App\Models\PaceAssignment;
use App\Models\PaceStockHolding;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shared plumbing for API feature tests.
 *
 * Requests carry the Origin header of a Sanctum stateful domain so that
 * EnsureFrontendRequestsAreStateful applies the session middleware, exactly
 * like the SPA's browser requests do.
 *
 * The domain: an organization has branches; a branch has grade levels (per
 * school year) with subjects and sections; students are enrolled in a
 * section; a teaching assignment gives one teacher one subject in one
 * section; the PACE catalog and the stock pool are per branch, and each
 * teacher holds their own booklets.
 *
 * `User::factory()->create()` makes a teacher in a fresh organization of
 * their own (main branch, active subscription), so two factory users are
 * strangers unless placed in the same organization. The record helpers
 * below create data "for" a user: a grade level for a teacher comes with a
 * section the teacher teaches every subject in, so a teacher's students,
 * subjects and records are visible to them the way an admin would have set
 * them up.
 */
abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * Origin used by the SPA when served by `php artisan serve`.
     */
    protected const FRONTEND_ORIGIN = 'http://localhost:8000';

    /**
     * Subjects created under every grade level made by gradeLevel().
     *
     * @var array<int, string>
     */
    protected const DEFAULT_SUBJECTS = [
        'Math',
        'English',
        'Word Building',
        'Science',
        'Social Studies',
        'Literature',
        'Bible',
    ];

    /**
     * The user the record helpers create data for (set by signIn() or owner()).
     */
    protected ?User $user = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders([
            'Origin' => static::FRONTEND_ORIGIN,
            'Referer' => static::FRONTEND_ORIGIN.'/',
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
    }

    /**
     * Drop the guard instances cached on the auth manager so the next request
     * resolves the user from the session again, exactly like a fresh HTTP
     * request would (a single test process would otherwise reuse the guard).
     */
    protected function forgetGuards(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /**
     * Authenticate the test client (web guard, which Sanctum checks first).
     * Defaults to the owner of any records created earlier, so a test may
     * build data first and sign in afterwards.
     */
    protected function signIn(?User $user = null): User
    {
        $user ??= $this->user ?? User::factory()->create();

        // Explicitly the web guard: after the first request the default guard is
        // "sanctum", and a user set on that guard alone would leave the web guard a
        // guest, which the `current_password:web` rule relies on.
        $this->actingAs($user, 'web');
        $this->user = $user;

        return $user;
    }

    /**
     * The default owner for records: the signed-in user, or a fresh teacher.
     */
    protected function owner(): User
    {
        return $this->user ??= User::factory()->create();
    }

    /**
     * An organization (with its main branch and an active subscription).
     */
    protected function organization(array $attributes = []): Organization
    {
        return Organization::factory()->create($attributes);
    }

    /**
     * A school administrator of the given organization (a fresh one by default).
     */
    protected function admin(?Organization $organization = null, array $attributes = []): User
    {
        $factory = User::factory()->admin();

        if ($organization !== null) {
            $factory = $factory->inOrganization($organization);
        }

        return $factory->create($attributes);
    }

    /**
     * A teacher of the given organization (its main branch unless a branch is given).
     */
    protected function teacher(Organization $organization, array $attributes = [], ?Branch $branch = null): User
    {
        return User::factory()->inOrganization($organization, $branch)->create($attributes);
    }

    /**
     * The platform owner (no organization).
     */
    protected function platformOwner(array $attributes = []): User
    {
        return User::factory()->owner()->create($attributes);
    }

    /**
     * The branch a member works in.
     */
    protected function branchOf(User $member): Branch
    {
        return Branch::query()->findOrFail($member->branch_id);
    }

    /**
     * A school year of the given user's organization, created on first use.
     * The organization's first school year becomes its default unless
     * `is_current` is given.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function schoolYear(string $name = '2026-2027', ?User $owner = null, array $attributes = []): SchoolYear
    {
        $owner ??= $this->owner();
        $organizationId = $owner->organization_id;

        $existing = SchoolYear::query()->forOrganization($organizationId)->where('name', $name)->first();

        if ($existing !== null) {
            return $existing;
        }

        if (! array_key_exists('is_current', $attributes)) {
            $attributes['is_current'] = ! SchoolYear::query()->forOrganization($organizationId)->exists();
        }

        return SchoolYear::factory()->create($attributes + [
            'name' => $name,
            'organization_id' => $organizationId,
            'user_id' => $owner->id,
        ]);
    }

    /**
     * A grade level (with the default subjects) in the given user's branch,
     * created on first use under the given school year (default: the
     * organization's default school year, registering "2026-2027" when it
     * has none yet). A teacher gets a section of it in which they teach
     * every subject (see section()).
     *
     * @param  array<int, string>  $subjects
     */
    protected function gradeLevel(
        string $name = 'Grade 1',
        ?User $owner = null,
        array $subjects = self::DEFAULT_SUBJECTS,
        ?SchoolYear $schoolYear = null
    ): GradeLevel {
        $owner ??= $this->owner();
        $schoolYear ??= SchoolYear::query()->forOrganization($owner->organization_id)->where('is_current', true)->first()
            ?? $this->schoolYear('2026-2027', $owner, ['is_current' => true]);

        $gradeLevel = GradeLevel::query()
            ->forBranch($owner->branch_id)
            ->where('school_year_id', $schoolYear->id)
            ->where('name', $name)
            ->first();

        if ($gradeLevel === null) {
            $gradeLevel = GradeLevel::factory()->create([
                'branch_id' => $owner->branch_id,
                'school_year_id' => $schoolYear->id,
                'created_by' => $owner->id,
                'name' => $name,
                'sort_order' => GradeLevel::query()->forBranch($owner->branch_id)->where('school_year_id', $schoolYear->id)->count(),
            ]);

            foreach ($subjects as $order => $subjectName) {
                Subject::factory()->create([
                    'grade_level_id' => $gradeLevel->id,
                    'name' => $subjectName,
                    'sort_order' => $order,
                ]);
            }
        }

        $this->section($gradeLevel, $owner);

        return $gradeLevel;
    }

    /**
     * The section of a grade level the given user works with, created on
     * first use. For a teacher: a section they teach in (taking over a
     * section nobody teaches in yet, else a new one), with every subject of
     * the grade level assigned to them. For an admin: the first section.
     */
    protected function section(GradeLevel $gradeLevel, ?User $owner = null, ?string $name = null): Section
    {
        $owner ??= $this->owner();

        if (! $owner->isTeacher()) {
            return $gradeLevel->sections()->first()
                ?? Section::factory()->create(['grade_level_id' => $gradeLevel->id, 'name' => $name ?? 'Section 1', 'sort_order' => 0]);
        }

        $section = $gradeLevel->sections()->ownedBy($owner)->first();

        if ($section !== null) {
            return $section;
        }

        $section = $gradeLevel->sections()->whereDoesntHave('teachingAssignments')->first()
            ?? Section::factory()->create([
                'grade_level_id' => $gradeLevel->id,
                'name' => $name ?? 'Section '.($gradeLevel->sections()->count() + 1),
                'sort_order' => $gradeLevel->sections()->count(),
            ]);

        foreach ($gradeLevel->subjects as $subject) {
            $this->assign($owner, $section, $subject);
        }

        return $section;
    }

    /**
     * A new, empty section of a grade level (nobody teaches in it yet).
     */
    protected function emptySection(GradeLevel $gradeLevel, ?string $name = null): Section
    {
        return Section::factory()->create([
            'grade_level_id' => $gradeLevel->id,
            'name' => $name ?? 'Section '.($gradeLevel->sections()->count() + 1),
            'sort_order' => $gradeLevel->sections()->count(),
        ]);
    }

    /**
     * Give a teacher a subject in a section (replacing whoever had it).
     */
    protected function assign(User $teacher, Section $section, Subject $subject): TeachingAssignment
    {
        return TeachingAssignment::query()->updateOrCreate(
            ['section_id' => $section->id, 'subject_id' => $subject->id],
            ['teacher_id' => $teacher->id]
        );
    }

    /**
     * One of a grade level's subjects by name.
     */
    protected function subject(GradeLevel $gradeLevel, string $name = 'Math'): Subject
    {
        return $gradeLevel->subjects()->where('name', $name)->firstOrFail();
    }

    /**
     * A student registered by the given user, enrolled in their section of
     * "Grade 1" unless a `section_id` (or a `grade_level_id`, whose section
     * for the user is used) is given, and filed under the section's grade
     * level's school year unless specified.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function student(array $attributes = [], ?User $owner = null): Student
    {
        $owner ??= $this->owner();

        $attributes['created_by'] = $owner->id;

        if (! isset($attributes['section_id'])) {
            $gradeLevel = isset($attributes['grade_level_id'])
                ? GradeLevel::query()->findOrFail((int) $attributes['grade_level_id'])
                : $this->gradeLevel('Grade 1', $owner);

            $attributes['section_id'] = $this->section($gradeLevel, $owner)->id;
        }

        unset($attributes['grade_level_id']);

        if (! array_key_exists('school_year_id', $attributes)) {
            $attributes['school_year_id'] = Section::query()->findOrFail($attributes['section_id'])->gradeLevel->school_year_id;
        }

        return Student::factory()->create($attributes);
    }

    /**
     * A PACE in the given user's branch catalog, registered by them. A
     * `my_stock` attribute gives that user (a teacher) that many booklets.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function pace(array $attributes = [], ?User $owner = null): Pace
    {
        $owner ??= $this->owner();

        $myStock = $attributes['my_stock'] ?? null;
        unset($attributes['my_stock']);

        $pace = Pace::factory()->create($attributes + ['branch_id' => $owner->branch_id, 'created_by' => $owner->id]);

        if ($myStock !== null) {
            $this->holding($pace, $owner, (int) $myStock);
        }

        return $pace;
    }

    /**
     * Set how many booklets of a PACE a teacher has (no ledger entry).
     */
    protected function holding(Pace $pace, User $teacher, int $quantity): PaceStockHolding
    {
        return PaceStockHolding::query()->updateOrCreate(
            ['pace_id' => $pace->id, 'user_id' => $teacher->id],
            ['quantity' => $quantity]
        );
    }

    /**
     * A PACE record the given user is in charge of: their student, a PACE of
     * their branch, under a subject they teach in the student's section.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function assignment(array $attributes = [], ?User $owner = null): PaceAssignment
    {
        $owner ??= $this->owner();

        if (! isset($attributes['student_id'])) {
            $attributes['student_id'] = $this->student([], $owner)->id;
        }

        if (! isset($attributes['subject_id'])) {
            $student = Student::query()->findOrFail($attributes['student_id']);

            $attributes['subject_id'] = TeachingAssignment::query()
                ->forTeacher($owner)
                ->where('section_id', $student->section_id)
                ->join('subjects', 'subjects.id', '=', 'teaching_assignments.subject_id')
                ->orderBy('subjects.sort_order')
                ->value('teaching_assignments.subject_id')
                ?? $student->section->subjectsQuery()->value('id');
        }

        if (! isset($attributes['pace_id'])) {
            $attributes['pace_id'] = $this->pace([], $owner)->id;
        }

        return PaceAssignment::factory()->create($attributes);
    }

    /**
     * Id of the organization's default school year, or null when it has none.
     */
    protected function currentSchoolYearId(User $owner): ?int
    {
        $id = SchoolYear::query()->forOrganization($owner->organization_id)->where('is_current', true)->value('id');

        return $id === null ? null : (int) $id;
    }
}
