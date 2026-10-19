<?php

namespace Tests\Feature;

use App\Models\PaceAssignment;
use App\Models\SchoolYear;
use App\Models\User;

/**
 * School years belong to the organization: admins register them, teachers
 * see the same list and pick their own current year.
 */
class SchoolYearTest extends ApiTestCase
{
    public function test_an_admin_can_register_school_years_and_the_first_becomes_the_default(): void
    {
        $admin = $this->signIn($this->admin());

        $this->postJson('/api/school-years', [
            'name' => '2026-2027',
            'start_date' => '2026-06-01',
            'end_date' => '2027-05-31',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', '2026-2027')
            ->assertJsonPath('data.start_date', '2026-06-01')
            ->assertJsonPath('data.end_date', '2027-05-31')
            ->assertJsonPath('data.is_current', true)
            ->assertJsonPath('data.is_organization_default', true)
            ->assertJsonPath('data.students_count', 0)
            ->assertJsonPath('data.assignments_count', 0);

        $this->postJson('/api/school-years', ['name' => '2027-2028'])
            ->assertCreated()
            ->assertJsonPath('data.is_current', false)
            ->assertJsonPath('data.start_date', null);

        $this->assertDatabaseHas('school_years', ['organization_id' => $admin->organization_id, 'user_id' => $admin->id, 'name' => '2026-2027', 'is_current' => 1]);
        $this->assertDatabaseHas('school_years', ['organization_id' => $admin->organization_id, 'name' => '2027-2028', 'is_current' => 0]);
    }

    public function test_a_teacher_cannot_register_or_edit_school_years(): void
    {
        $this->signIn();
        $year = $this->schoolYear('2026-2027');

        $this->postJson('/api/school-years', ['name' => '2027-2028'])->assertForbidden();
        $this->putJson('/api/school-years/'.$year->id, ['name' => '2030-2031'])->assertForbidden();

        $this->assertDatabaseHas('school_years', ['id' => $year->id, 'name' => '2026-2027']);
    }

    public function test_marking_a_school_year_default_unflags_the_previous_one(): void
    {
        $admin = $this->signIn($this->admin());
        $first = $this->schoolYear('2025-2026', $admin);

        $second = $this->postJson('/api/school-years', ['name' => '2026-2027', 'is_current' => true])
            ->assertCreated()
            ->assertJsonPath('data.is_current', true);

        $this->assertDatabaseHas('school_years', ['id' => $first->id, 'is_current' => 0]);

        $this->putJson('/api/school-years/'.$first->id, ['is_current' => true])
            ->assertOk()
            ->assertJsonPath('data.is_current', true);

        $this->assertDatabaseHas('school_years', ['id' => $second->json('data.id'), 'is_current' => 0]);
        $this->assertSame(1, SchoolYear::query()->where('is_current', true)->count());
    }

    public function test_school_year_payloads_are_validated(): void
    {
        $admin = $this->signIn($this->admin());
        $this->schoolYear('2026-2027', $admin);

        $this->postJson('/api/school-years', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->postJson('/api/school-years', ['name' => '2026'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->postJson('/api/school-years', ['name' => '2026-2028'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->postJson('/api/school-years', ['name' => '2026-2027'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->postJson('/api/school-years', ['name' => '2027-2028', 'start_date' => '2027-06-01', 'end_date' => '2027-05-31'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end_date']);

        $this->assertDatabaseCount('school_years', 1);
    }

    public function test_the_same_school_year_can_be_registered_by_different_organizations(): void
    {
        $this->signIn($this->admin());
        $this->schoolYear('2026-2027', User::factory()->create());

        $this->postJson('/api/school-years', ['name' => '2026-2027'])->assertCreated();
    }

    public function test_school_years_are_listed_newest_first_with_the_teachers_own_counts(): void
    {
        $this->signIn();
        $old = $this->schoolYear('2024-2025', null, ['is_current' => false]);
        $current = $this->schoolYear('2026-2027', null, ['is_current' => true]);

        $student = $this->student();
        $this->student(['school_year_id' => $old->id]);

        PaceAssignment::factory()->count(2)->create(['student_id' => $student->id, 'school_year' => '2026-2027']);
        PaceAssignment::factory()->create(['student_id' => $student->id, 'school_year' => '2024-2025']);

        // A colleague's records in the same organization do not count towards the teacher's figures.
        $colleague = $this->teacher($this->user->organization);
        $this->student([], $colleague);

        $response = $this->getJson('/api/school-years')->assertOk()->assertJsonCount(2, 'data');

        $response->assertJsonPath('data.0.id', $current->id)
            ->assertJsonPath('data.0.is_current', true)
            ->assertJsonPath('data.0.is_organization_default', true)
            ->assertJsonPath('data.0.grade_levels_count', 1)
            ->assertJsonPath('data.1.grade_levels_count', 0)
            ->assertJsonPath('data.0.students_count', 1)
            ->assertJsonPath('data.0.assignments_count', 2)
            ->assertJsonPath('data.1.id', $old->id)
            ->assertJsonPath('data.1.students_count', 1)
            ->assertJsonPath('data.1.assignments_count', 1);

        $this->getJson('/api/school-years/'.$old->id)
            ->assertOk()
            ->assertJsonPath('data.name', '2024-2025')
            ->assertJsonPath('data.assignments_count', 1);
    }

    public function test_an_admin_sees_organization_wide_counts(): void
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $teacherA = $this->teacher($organization);
        $teacherB = $this->teacher($organization);

        $year = $this->schoolYear('2026-2027', $admin);
        $this->student([], $teacherA);
        $this->student([], $teacherB);

        $this->signIn($admin);

        $this->getJson('/api/school-years')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $year->id)
            ->assertJsonPath('data.0.students_count', 2)
            ->assertJsonPath('data.0.grade_levels_count', 2);
    }

    public function test_a_teacher_can_pick_their_own_current_school_year(): void
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $teacher = $this->teacher($organization);
        $colleague = $this->teacher($organization);

        $old = $this->schoolYear('2025-2026', $admin, ['is_current' => false]);
        $default = $this->schoolYear('2026-2027', $admin, ['is_current' => true]);

        $this->signIn($teacher);

        // Without a pick of their own, the organization's default is current.
        $this->getJson('/api/school-years')
            ->assertOk()
            ->assertJsonPath('data.0.id', $default->id)
            ->assertJsonPath('data.0.is_current', true)
            ->assertJsonPath('data.1.is_current', false);

        $this->putJson('/api/school-years/'.$old->id.'/select')
            ->assertOk()
            ->assertJsonPath('data.id', $old->id)
            ->assertJsonPath('data.is_current', true)
            ->assertJsonPath('data.is_organization_default', false);

        $this->assertDatabaseHas('users', ['id' => $teacher->id, 'current_school_year_id' => $old->id]);

        $this->getJson('/api/school-years')
            ->assertOk()
            ->assertJsonPath('data.0.is_current', false)
            ->assertJsonPath('data.0.is_organization_default', true)
            ->assertJsonPath('data.1.is_current', true);

        // The pick is personal: the organization's default is untouched and colleagues keep it.
        $this->assertDatabaseHas('school_years', ['id' => $default->id, 'is_current' => 1]);

        $this->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.school_year.id', $old->id);

        $this->forgetGuards();
        $this->signIn($colleague);

        $this->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.school_year.id', $default->id);
    }

    public function test_an_admin_can_edit_a_school_year(): void
    {
        $admin = $this->signIn($this->admin());
        $year = $this->schoolYear('2026-2027', $admin);

        $this->putJson('/api/school-years/'.$year->id, [
            'name' => '2027-2028',
            'start_date' => '2027-06-07',
            'end_date' => '2028-04-30',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', '2027-2028')
            ->assertJsonPath('data.start_date', '2027-06-07')
            ->assertJsonPath('data.end_date', '2028-04-30')
            ->assertJsonPath('data.is_current', true);

        // Only the end date sent: compared against the stored start date.
        $this->putJson('/api/school-years/'.$year->id, ['end_date' => '2027-01-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end_date']);
    }

    public function test_new_students_default_to_the_current_school_year(): void
    {
        $this->signIn();
        $this->schoolYear('2025-2026', null, ['is_current' => false]);
        $current = $this->schoolYear('2026-2027', null, ['is_current' => true]);
        $grade = $this->gradeLevel('Grade 1');

        $this->postJson('/api/students', [
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'birth_date' => '2015-01-01',
            'age' => 11,
            'sex' => 'female',
            'grade_level_id' => $grade->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.school_year_id', $current->id)
            ->assertJsonPath('data.school_year.name', '2026-2027');
    }

    public function test_a_user_only_sees_their_own_organizations_school_years(): void
    {
        $this->signIn();
        $mine = $this->schoolYear('2026-2027');

        $stranger = User::factory()->create();
        $theirs = $this->schoolYear('2026-2027', $stranger);

        $this->getJson('/api/school-years')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);

        $this->getJson('/api/school-years/'.$theirs->id)->assertNotFound();
        $this->putJson('/api/school-years/'.$theirs->id.'/select')->assertNotFound();

        $this->assertDatabaseHas('school_years', ['id' => $theirs->id, 'name' => '2026-2027']);
    }

    public function test_dashboard_summary_follows_the_selected_school_year(): void
    {
        $this->signIn();
        $old = $this->schoolYear('2025-2026', null, ['is_current' => false]);
        $current = $this->schoolYear('2026-2027', null, ['is_current' => true]);
        $grade1 = $this->gradeLevel('Grade 1');
        $grade2 = $this->gradeLevel('Grade 2');
        $this->gradeLevel('Grade 3');

        $nowA = $this->student(['grade_level_id' => $grade1->id]);
        $nowB = $this->student(['grade_level_id' => $grade1->id]);
        $this->student(['grade_level_id' => $grade2->id, 'school_year_id' => $old->id]);

        $this->pace();
        $this->pace();
        $this->pace();

        PaceAssignment::factory()->count(2)->create(['student_id' => $nowA->id, 'school_year' => '2026-2027']);
        PaceAssignment::factory()->tested()->create(['student_id' => $nowB->id, 'school_year' => '2026-2027']);
        PaceAssignment::factory()->tested()->count(2)->create(['student_id' => $nowB->id, 'school_year' => '2025-2026']);

        // Default: the current school year (all three grade levels are registered under it).
        // PACEs are not tied to a school year, so the count is always the all-time total.
        $this->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.school_year.id', $current->id)
            ->assertJsonPath('data.grade_levels', 3)
            ->assertJsonPath('data.students', 2)
            ->assertJsonPath('data.paces', 3)
            ->assertJsonPath('data.assignments', 3)
            ->assertJsonPath('data.tested', 1)
            ->assertJsonCount(2, 'data.recent_students')
            ->assertJsonPath('data.totals.school_years', 2)
            ->assertJsonPath('data.totals.grade_levels', 3)
            ->assertJsonPath('data.totals.students', 3)
            ->assertJsonPath('data.totals.paces', 3)
            ->assertJsonPath('data.totals.assignments', 5);

        // An explicitly selected school year (no grade levels were registered under it).
        $this->getJson('/api/dashboard/summary?school_year_id='.$old->id)
            ->assertOk()
            ->assertJsonPath('data.school_year.id', $old->id)
            ->assertJsonPath('data.grade_levels', 0)
            ->assertJsonPath('data.students', 1)
            ->assertJsonPath('data.paces', 3)
            ->assertJsonPath('data.assignments', 2)
            ->assertJsonPath('data.tested', 2)
            ->assertJsonCount(1, 'data.recent_students');

        // Another organization's school year is rejected.
        $foreign = $this->schoolYear('2026-2027', User::factory()->create());

        $this->getJson('/api/dashboard/summary?school_year_id='.$foreign->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_year_id']);

        // "all": all-time totals across every school year, same shape as having none registered.
        $this->getJson('/api/dashboard/summary?school_year_id=all')
            ->assertOk()
            ->assertJsonPath('data.school_year', null)
            ->assertJsonPath('data.grade_levels', 3)
            ->assertJsonPath('data.students', 3)
            ->assertJsonPath('data.paces', 3)
            ->assertJsonPath('data.assignments', 5)
            ->assertJsonPath('data.tested', 3)
            ->assertJsonCount(3, 'data.recent_students');
    }

    public function test_dashboard_summary_without_school_years_shows_all_time_counts(): void
    {
        $this->signIn();
        // Grade levels (and so students) need a school year; a PACE does not.
        $this->pace();

        $this->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.school_year', null)
            ->assertJsonPath('data.grade_levels', 0)
            ->assertJsonPath('data.students', 0)
            ->assertJsonPath('data.paces', 1)
            ->assertJsonPath('data.totals.school_years', 0)
            ->assertJsonPath('data.totals.paces', 1);
    }

    public function test_guests_cannot_manage_school_years(): void
    {
        $this->getJson('/api/school-years')->assertUnauthorized();
        $this->postJson('/api/school-years', ['name' => '2026-2027'])->assertUnauthorized();

        $this->assertDatabaseCount('school_years', 0);
    }
}
