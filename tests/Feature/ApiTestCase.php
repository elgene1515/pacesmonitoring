<?php

namespace Tests\Feature;

use App\Models\GradeLevel;
use App\Models\Organization;
use App\Models\Pace;
use App\Models\PaceAssignment;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\Subject;
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
 * Every record belongs to a teacher, and every teacher belongs to an
 * organization. `User::factory()->create()` makes a teacher in a fresh
 * organization of their own (main branch, active subscription), so two
 * factory users are strangers unless placed in the same organization. School
 * years belong to the organization; the record helpers below create data for
 * the signed-in user by default (or for an explicit owner).
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
     * A teacher of the given organization.
     */
    protected function teacher(Organization $organization, array $attributes = []): User
    {
        return User::factory()->inOrganization($organization)->create($attributes);
    }

    /**
     * The platform owner (no organization).
     */
    protected function platformOwner(array $attributes = []): User
    {
        return User::factory()->owner()->create($attributes);
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
     * A grade level (with the default subjects) owned by the given user, created on
     * first use under the given school year (default: the organization's default
     * school year, registering "2026-2027" when it has none yet).
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

        $existing = GradeLevel::query()
            ->ownedBy($owner)
            ->where('school_year_id', $schoolYear->id)
            ->where('name', $name)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $gradeLevel = GradeLevel::factory()->for($owner)->create([
            'school_year_id' => $schoolYear->id,
            'name' => $name,
            'sort_order' => GradeLevel::query()->ownedBy($owner)->where('school_year_id', $schoolYear->id)->count(),
        ]);

        foreach ($subjects as $order => $subjectName) {
            Subject::factory()->create([
                'grade_level_id' => $gradeLevel->id,
                'name' => $subjectName,
                'sort_order' => $order,
            ]);
        }

        return $gradeLevel;
    }

    /**
     * One of a grade level's subjects by name.
     */
    protected function subject(GradeLevel $gradeLevel, string $name = 'Math'): Subject
    {
        return $gradeLevel->subjects()->where('name', $name)->firstOrFail();
    }

    /**
     * A student registered by the given user (enrolled in their "Grade 1" and
     * filed under the current school year, when there is one, unless specified).
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function student(array $attributes = [], ?User $owner = null): Student
    {
        $owner ??= $this->owner();

        $attributes['created_by'] = $owner->id;

        if (! isset($attributes['grade_level_id'])) {
            $attributes['grade_level_id'] = $this->gradeLevel('Grade 1', $owner)->id;
        }

        // A student's school year is the school year of their grade level.
        if (! array_key_exists('school_year_id', $attributes)) {
            $fromGradeLevel = GradeLevel::query()->whereKey($attributes['grade_level_id'])->value('school_year_id');
            $attributes['school_year_id'] = $fromGradeLevel === null ? $this->currentSchoolYearId($owner) : (int) $fromGradeLevel;
        }

        return Student::factory()->create($attributes);
    }

    /**
     * A PACE registered by the given user.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function pace(array $attributes = [], ?User $owner = null): Pace
    {
        $owner ??= $this->owner();

        return Pace::factory()->create($attributes + ['created_by' => $owner->id]);
    }

    /**
     * An assignment whose student and PACE both belong to the given user.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function assignment(array $attributes = [], ?User $owner = null): PaceAssignment
    {
        $owner ??= $this->owner();

        if (! isset($attributes['student_id'])) {
            $attributes['student_id'] = $this->student([], $owner)->id;
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
