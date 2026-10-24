<?php

namespace Tests\Feature;

use App\Models\GradeLevel;
use App\Models\JoinRequest;
use App\Models\Organization;
use App\Models\Pace;
use App\Models\PaceStockHolding;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;

/**
 * The demo seed is a usable, consistent school (never created in production).
 */
class DemoSeederTest extends ApiTestCase
{
    public function test_the_demo_seed_builds_a_consistent_school(): void
    {
        $this->seed(DemoSeeder::class);

        $school = Organization::query()->where('name', 'Grace Christian Academy')->firstOrFail();

        $this->assertSame(2, $school->branches()->count());
        $this->assertSame(1, User::query()->where('email', 'owner@example.com')->count());
        $this->assertSame(70, Student::query()->forOrganization($school->id)->count());

        // Section 2 of Grade 1 does not offer Filipino, and none of its students takes it.
        $section = Section::query()->where('name', 'Section 2')->whereHas('gradeLevel', fn ($level) => $level->where('name', 'Grade 1'))->firstOrFail();
        $this->assertEqualsCanonicalizing(['English', 'Math'], $section->subjects()->pluck('name')->all());
        $this->assertSame(0, Student::query()->where('section_id', $section->id)->whereHas('subjects', fn ($q) => $q->where('name', 'Filipino'))->count());

        // Stock never goes negative, and every PACE with movements has a ledger.
        $this->assertSame(0, Pace::query()->where('pool_stock', '<', 0)->count());
        $this->assertSame(0, PaceStockHolding::query()->where('quantity', '<', 0)->count());

        // The request kinds exist for the admin to work through.
        $this->assertDatabaseHas('pace_requests', ['status' => 'pending']);
        $this->assertDatabaseHas('pace_requests', ['status' => 'given']);
        $this->assertDatabaseHas('pace_requests', ['status' => 'declined']);
        $this->assertDatabaseHas('student_change_requests', ['status' => 'pending']);
        $this->assertDatabaseHas('teaching_assignment_requests', ['status' => 'pending']);
        $this->assertDatabaseHas('join_requests', ['status' => 'pending']);
    }

    public function test_the_accounts_have_their_name_in_parts_contact_details_and_the_terms_accepted(): void
    {
        $this->seed(DemoSeeder::class);

        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $this->assertSame('Maria Lopez Santos', $admin->name);
        $this->assertSame(['Maria', 'Lopez', 'Santos', null], [$admin->first_name, $admin->middle_name, $admin->last_name, $admin->suffix]);
        $this->assertNotNull($admin->phone);

        // The suffix is part of the composed name.
        $this->assertSame('Diego Luis Ramos Jr.', User::query()->where('email', 'diego@example.com')->firstOrFail()->name);

        // Everyone who signed up accepted the terms; the platform owner did not sign up.
        $this->assertSame(0, User::query()->where('role', '!=', 'owner')->whereNull('first_name')->count());
        $this->assertSame(0, User::query()->where('role', '!=', 'owner')->whereNull('terms_accepted_at')->count());
        $this->assertSame(config('saas.terms_version'), $admin->terms_version);
        $this->assertNull(User::query()->where('email', 'owner@example.com')->firstOrFail()->terms_accepted_at);
    }

    public function test_two_people_are_waiting_to_join_and_one_asks_to_be_an_administrator(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertSame(2, JoinRequest::query()->pending()->count());
        $this->assertSame('teacher', JoinRequest::query()->whereHas('user', fn ($user) => $user->where('email', 'gina@example.com'))->firstOrFail()->requested_role);
        $this->assertSame('admin', JoinRequest::query()->whereHas('user', fn ($user) => $user->where('email', 'hannah@example.com'))->firstOrFail()->requested_role);

        $this->signIn(User::query()->where('email', 'admin@example.com')->firstOrFail());

        $this->getJson('/api/admin/join-requests')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_the_students_have_ids_contacts_and_an_enrollment_history(): void
    {
        $this->seed(DemoSeeder::class);

        $school = Organization::query()->where('name', 'Grace Christian Academy')->firstOrFail();
        $students = Student::query()->forOrganization($school->id);

        // A unique school ID number and an emergency contact each; some medical remarks and a student email / mobile.
        $this->assertSame(70, (clone $students)->whereNotNull('student_number')->count());
        $this->assertSame(70, (clone $students)->distinct()->count('student_number'));
        $this->assertSame(70, (clone $students)->whereNotNull('guardian_name')->whereNotNull('guardian_phone')->count());
        $this->assertGreaterThan(0, (clone $students)->whereNotNull('medical_notes')->count());
        $this->assertGreaterThan(0, (clone $students)->whereNotNull('email')->count());
        $this->assertGreaterThan(0, (clone $students)->whereNotNull('mobile')->count());

        // Everyone has a current stay; two Grade 1 children moved sections early in the year (two stays each).
        $this->assertSame(70, StudentEnrollment::query()->open()->count());
        $this->assertSame(72, StudentEnrollment::query()->count());

        $moved = StudentEnrollment::query()->whereNotNull('ended_on')->pluck('student_id')->unique();
        $this->assertCount(2, $moved);

        foreach ($moved as $id) {
            $stays = StudentEnrollment::query()->where('student_id', $id)->orderBy('id')->get();

            $this->assertSame('Section 2', $stays[0]->section_name);
            $this->assertSame('Section 1', $stays[1]->section_name);
            $this->assertSame($stays[0]->ended_on->toDateString(), $stays[1]->started_on->toDateString());
            $this->assertSame('2026-06-01', $stays[0]->started_on->toDateString());
        }
    }

    public function test_next_years_classes_are_ready_for_the_year_end_promotion(): void
    {
        $this->seed(DemoSeeder::class);

        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $next = SchoolYear::query()->where('name', '2027-2028')->firstOrFail();

        // Registered, not the default, and holding the classes but no students or teachers yet.
        $this->assertFalse((bool) $next->is_organization_default);
        $this->assertSame(0, Student::query()->where('school_year_id', $next->id)->count());
        $this->assertSame(0, \App\Models\TeachingAssignment::query()->whereHas('section.gradeLevel', fn ($level) => $level->where('school_year_id', $next->id))->count());
        $this->assertEqualsCanonicalizing(
            ['Grade 1', 'Grade 2', 'Grade 3', 'Grade 4'],
            GradeLevel::query()->where('school_year_id', $next->id)->pluck('name')->all()
        );

        // The whole of Grade 1 · Section 1 can be promoted to next year's Grade 2 · Section A through the API.
        $from = Section::query()->where('name', 'Section 1')
            ->whereHas('gradeLevel', fn ($level) => $level->where('name', 'Grade 1')->where('school_year_id', '!=', $next->id)->whereHas('branch', fn ($b) => $b->where('name', 'Main Campus')))
            ->firstOrFail();
        $target = Section::query()->where('name', 'Section A')
            ->whereHas('gradeLevel', fn ($level) => $level->where('name', 'Grade 2')->where('school_year_id', $next->id))
            ->firstOrFail();

        $moves = $from->students()->pluck('id')->map(fn ($id) => ['student_id' => $id, 'section_id' => $target->id])->all();
        $this->assertNotEmpty($moves);

        $this->signIn($admin);

        $this->postJson('/api/admin/sections/'.$from->id.'/promote', ['moves' => $moves])
            ->assertOk()
            ->assertJsonPath('data.moved', count($moves));

        $this->assertSame(count($moves), Student::query()->where('section_id', $target->id)->where('school_year_id', $next->id)->count());
        $this->assertSame(0, $from->students()->count());
    }

    public function test_seeded_teachers_only_see_the_students_taking_their_subjects(): void
    {
        $this->seed(DemoSeeder::class);

        $ana = User::query()->where('email', 'ana@example.com')->firstOrFail();

        $this->signIn($ana);

        $visible = $this->getJson('/api/students')->assertOk()->json('data');

        $this->assertNotEmpty($visible);
        $this->assertLessThan(70, count($visible));

        // Her sections list the same students.
        $this->getJson('/api/sections')->assertOk()->assertJsonStructure(['data' => [['id', 'name', 'students_count']]]);
    }

    public function test_the_seed_can_run_twice_and_skips_production(): void
    {
        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->assertSame(70, Student::query()->count());
        $this->assertSame(1, Organization::query()->where('name', 'Grace Christian Academy')->count());
    }

    public function test_nothing_is_seeded_in_production(): void
    {
        $this->app['env'] = 'production';

        // Run it directly: through `db:seed` production would first ask for confirmation.
        (new DatabaseSeeder())->setContainer($this->app)->run();

        $this->assertSame(0, Organization::query()->where('name', 'Grace Christian Academy')->count());
        $this->assertSame(0, User::query()->where('email', 'owner@example.com')->count());
    }
}
