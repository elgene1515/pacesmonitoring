<?php

namespace Tests\Feature;

use App\Models\PaceAssignment;
use App\Models\Section;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminTest extends ApiTestCase
{
    /*
    | The platform owner has the system-wide view the former "admin" flag used to
    | give: every organization, every teacher. Organization admins are covered
    | in TenancyTest and AdminScopeTest.
    */

    public function test_a_regular_user_cannot_access_admin_routes(): void
    {
        $this->signIn(User::factory()->create());

        $this->getJson('/api/admin/users')->assertForbidden();
        $this->getJson('/api/admin/users/1')->assertForbidden();
        $this->putJson('/api/admin/users/1', [])->assertForbidden();
        $this->putJson('/api/admin/users/1/password', [])->assertForbidden();
        $this->getJson('/api/admin/students')->assertForbidden();
        $this->getJson('/api/admin/students/1')->assertForbidden();
        $this->getJson('/api/admin/grade-levels?teacher_id=1')->assertForbidden();
        $this->getJson('/api/admin/sections/1')->assertForbidden();
        $this->getJson('/api/admin/teaching-assignments')->assertForbidden();
        $this->getJson('/api/admin/school-years?teacher_id=1')->assertForbidden();
        $this->getJson('/api/admin/paces')->assertForbidden();
        $this->getJson('/api/admin/paces/1')->assertForbidden();
        $this->getJson('/api/admin/pace-requests')->assertForbidden();
        $this->getJson('/api/admin/dashboard/summary')->assertForbidden();
    }

    public function test_guests_cannot_access_admin_routes(): void
    {
        $this->getJson('/api/admin/users')->assertUnauthorized();
        $this->getJson('/api/admin/students')->assertUnauthorized();
        $this->getJson('/api/admin/grade-levels?teacher_id=1')->assertUnauthorized();
        $this->getJson('/api/admin/school-years?teacher_id=1')->assertUnauthorized();
        $this->getJson('/api/admin/paces')->assertUnauthorized();
        $this->getJson('/api/admin/dashboard/summary')->assertUnauthorized();
    }

    public function test_an_admin_can_list_every_user(): void
    {
        $admin = $this->platformOwner();
        $teacher = User::factory()->create(['name' => 'Jane Teacher', 'email' => 'jane@example.com']);

        $this->signIn($admin);

        $response = $this->getJson('/api/admin/users')->assertOk()->assertJsonCount(2, 'data');

        $response->assertJsonMissingPath('data.0.password')
            ->assertJsonMissingPath('data.1.password');

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($admin->id, $ids);
        $this->assertContains($teacher->id, $ids);
    }

    public function test_the_user_list_can_be_searched_by_name_or_email(): void
    {
        $this->signIn($this->platformOwner());
        User::factory()->create(['name' => 'Jane Teacher', 'email' => 'jane@example.com']);
        User::factory()->create(['name' => 'Someone Else', 'email' => 'else@example.com']);

        $this->getJson('/api/admin/users?search=jane')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/users?search=else@example.com')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_an_admin_can_view_a_users_details_without_their_password(): void
    {
        $admin = $this->platformOwner();
        $teacher = User::factory()->create(['name' => 'Jane Teacher', 'email' => 'jane@example.com']);

        $this->signIn($admin);

        $this->getJson('/api/admin/users/'.$teacher->id)
            ->assertOk()
            ->assertJsonPath('data.id', $teacher->id)
            ->assertJsonPath('data.name', 'Jane Teacher')
            ->assertJsonPath('data.email', 'jane@example.com')
            ->assertJsonPath('data.is_admin', false)
            ->assertJsonMissingPath('data.password');
    }

    public function test_an_admin_can_update_a_users_name_and_email_with_their_own_password(): void
    {
        $admin = $this->platformOwner(['password' => Hash::make('admin-password')]);
        $teacher = User::factory()->create(['email' => 'old@example.com']);

        $this->signIn($admin);

        $this->putJson('/api/admin/users/'.$teacher->id, [
            'name' => 'Updated Name',
            'email' => 'new@example.com',
            'admin_password' => 'admin-password',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.email', 'new@example.com');

        $this->assertDatabaseHas('users', [
            'id' => $teacher->id,
            'name' => 'Updated Name',
            'email' => 'new@example.com',
        ]);
    }

    public function test_updating_a_user_requires_the_admins_correct_password(): void
    {
        $admin = $this->platformOwner(['password' => Hash::make('admin-password')]);
        $teacher = User::factory()->create(['name' => 'Old Name']);

        $this->signIn($admin);

        $this->putJson('/api/admin/users/'.$teacher->id, [
            'name' => 'New Name',
            'email' => $teacher->email,
            'admin_password' => 'wrong-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['admin_password']);

        $this->assertDatabaseHas('users', ['id' => $teacher->id, 'name' => 'Old Name']);
    }

    public function test_updating_a_user_is_validated(): void
    {
        $admin = $this->platformOwner(['password' => Hash::make('admin-password')]);
        $teacher = User::factory()->create();
        $other = User::factory()->create(['email' => 'taken@example.com']);

        $this->signIn($admin);

        $this->putJson('/api/admin/users/'.$teacher->id, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'admin_password']);

        $this->putJson('/api/admin/users/'.$teacher->id, [
            'name' => 'Someone',
            'email' => $other->email,
            'admin_password' => 'admin-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_an_admin_can_reset_a_users_password_without_their_old_one(): void
    {
        $admin = $this->platformOwner(['password' => Hash::make('admin-password')]);
        $teacher = User::factory()->create(['password' => Hash::make('forgotten-password')]);

        $this->signIn($admin);

        $this->putJson('/api/admin/users/'.$teacher->id.'/password', [
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
            'admin_password' => 'admin-password',
        ])->assertNoContent();

        $this->assertTrue(Hash::check('brand-new-password', $teacher->fresh()->password));
    }

    public function test_resetting_a_users_password_requires_the_admins_correct_password(): void
    {
        $admin = $this->platformOwner(['password' => Hash::make('admin-password')]);
        $teacher = User::factory()->create(['password' => Hash::make('original-password')]);

        $this->signIn($admin);

        $this->putJson('/api/admin/users/'.$teacher->id.'/password', [
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
            'admin_password' => 'wrong-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['admin_password']);

        $this->assertTrue(Hash::check('original-password', $teacher->fresh()->password));
    }

    public function test_resetting_a_password_is_validated(): void
    {
        $admin = $this->platformOwner(['password' => Hash::make('admin-password')]);
        $teacher = User::factory()->create();

        $this->signIn($admin);

        $this->putJson('/api/admin/users/'.$teacher->id.'/password', [
            'password' => 'short',
            'password_confirmation' => 'different',
            'admin_password' => 'admin-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_changing_ones_own_password_requires_the_current_password(): void
    {
        $admin = $this->platformOwner(['password' => Hash::make('old-admin-password')]);

        $this->signIn($admin);

        $this->putJson('/api/admin/users/'.$admin->id.'/password', [
            'password' => 'new-admin-password',
            'password_confirmation' => 'new-admin-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);

        $this->putJson('/api/admin/users/'.$admin->id.'/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-admin-password',
            'password_confirmation' => 'new-admin-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);

        $this->putJson('/api/admin/users/'.$admin->id.'/password', [
            'current_password' => 'old-admin-password',
            'password' => 'new-admin-password',
            'password_confirmation' => 'new-admin-password',
        ])->assertNoContent();

        $this->assertTrue(Hash::check('new-admin-password', $admin->fresh()->password));
    }

    public function test_an_admin_can_manage_another_admin_account(): void
    {
        $admin = $this->platformOwner(['password' => Hash::make('admin-password')]);
        $otherAdmin = $this->platformOwner(['password' => Hash::make('other-password')]);

        $this->signIn($admin);

        $this->putJson('/api/admin/users/'.$otherAdmin->id, [
            'name' => 'Renamed Admin',
            'email' => $otherAdmin->email,
            'admin_password' => 'admin-password',
        ])->assertOk()->assertJsonPath('data.name', 'Renamed Admin');

        $this->putJson('/api/admin/users/'.$otherAdmin->id.'/password', [
            'password' => 'another-new-password',
            'password_confirmation' => 'another-new-password',
            'admin_password' => 'admin-password',
        ])->assertNoContent();

        $this->assertTrue(Hash::check('another-new-password', $otherAdmin->fresh()->password));
    }

    public function test_an_admin_can_list_students_across_every_teacher(): void
    {
        $teacherA = User::factory()->create();
        $teacherB = User::factory()->create();
        $studentA = $this->student(['first_name' => 'Ana', 'last_name' => 'Cruz'], $teacherA);
        $studentB = $this->student(['first_name' => 'Ben', 'last_name' => 'Reyes'], $teacherB);

        $this->signIn($this->platformOwner());

        $response = $this->getJson('/api/admin/students')->assertOk()->assertJsonCount(2, 'data');

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($studentA->id, $ids);
        $this->assertContains($studentB->id, $ids);
    }

    public function test_the_student_masterlist_can_be_searched_by_name(): void
    {
        $teacher = User::factory()->create();
        $this->student(['first_name' => 'Ana', 'last_name' => 'Cruz'], $teacher);
        $this->student(['first_name' => 'Ben', 'last_name' => 'Reyes'], $teacher);

        $this->signIn($this->platformOwner());

        $this->getJson('/api/admin/students?search=Cruz')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_student_masterlist_can_be_filtered_by_teacher(): void
    {
        $teacherA = User::factory()->create();
        $teacherB = User::factory()->create();
        $studentA = $this->student([], $teacherA);
        $this->student([], $teacherB);

        $this->signIn($this->platformOwner());

        $response = $this->getJson('/api/admin/students?teacher_id='.$teacherA->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame($studentA->id, $response->json('data.0.id'));
    }

    public function test_the_student_masterlist_can_be_filtered_by_grade_level_and_section(): void
    {
        $teacher = User::factory()->create();
        $gradeOne = $this->gradeLevel('Grade 1', $teacher);
        $gradeTwo = $this->gradeLevel('Grade 2', $teacher);
        $studentOne = $this->student(['grade_level_id' => $gradeOne->id], $teacher);
        $this->student(['grade_level_id' => $gradeTwo->id], $teacher);

        $this->signIn($this->platformOwner());

        $response = $this->getJson('/api/admin/students?grade_level_id='.$gradeOne->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame($studentOne->id, $response->json('data.0.id'));

        $this->getJson('/api/admin/students?section_id='.$this->section($gradeTwo, $teacher)->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.section.grade_level.name', 'Grade 2');
    }

    public function test_an_admin_can_list_a_teachers_grade_levels(): void
    {
        $teacher = User::factory()->create();
        $otherTeacher = User::factory()->create();
        $this->gradeLevel('Grade 1', $teacher);
        $this->gradeLevel('Grade 2', $teacher);
        $this->gradeLevel('Grade 1', $otherTeacher);

        $this->signIn($this->platformOwner());

        $this->getJson('/api/admin/grade-levels?teacher_id='.$teacher->id)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonCount(count(self::DEFAULT_SUBJECTS), 'data.0.subjects')
            ->assertJsonCount(1, 'data.0.sections')
            ->assertJsonPath('data.0.sections.0.teaching_assignments.0.teacher.id', $teacher->id);
    }

    public function test_a_teachers_subjects_report_the_assigned_issued_and_tested_breakdown(): void
    {
        $teacher = User::factory()->create();
        $grade = $this->gradeLevel('Grade 1', $teacher, ['Math']);
        $math = $this->subject($grade, 'Math');
        $student = $this->student(['grade_level_id' => $grade->id], $teacher);

        $this->assignment(['student_id' => $student->id, 'subject_id' => $math->id], $teacher);
        PaceAssignment::factory()->issued()->create(['student_id' => $student->id, 'subject_id' => $math->id]);
        PaceAssignment::factory()->tested()->create(['student_id' => $student->id, 'subject_id' => $math->id]);
        PaceAssignment::factory()->tested()->create(['student_id' => $student->id, 'subject_id' => $math->id]);

        $this->signIn($this->platformOwner());

        $this->getJson('/api/admin/grade-levels?teacher_id='.$teacher->id)
            ->assertOk()
            ->assertJsonPath('data.0.subjects.0.assignments_count', 4)
            ->assertJsonPath('data.0.subjects.0.tested_count', 2)
            ->assertJsonPath('data.0.subjects.0.issued_count', 1)
            ->assertJsonPath('data.0.subjects.0.assigned_count', 1);
    }

    public function test_a_teachers_grade_levels_can_be_narrowed_to_one_school_year(): void
    {
        $teacher = User::factory()->create();
        $oldYear = $this->schoolYear('2025-2026', $teacher, ['is_current' => false]);
        $newYear = $this->schoolYear('2026-2027', $teacher, ['is_current' => true]);
        $this->gradeLevel('Grade 1', $teacher, self::DEFAULT_SUBJECTS, $oldYear);
        $gradeTwo = $this->gradeLevel('Grade 2', $teacher, self::DEFAULT_SUBJECTS, $newYear);

        $this->signIn($this->platformOwner());

        $response = $this->getJson('/api/admin/grade-levels?teacher_id='.$teacher->id.'&school_year_id='.$newYear->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame($gradeTwo->id, $response->json('data.0.id'));
    }

    public function test_the_owner_lists_every_grade_level_or_one_organizations(): void
    {
        $teacherA = User::factory()->create();
        $teacherB = User::factory()->create();
        $this->gradeLevel('Grade 1', $teacherA);
        $this->gradeLevel('Grade 2', $teacherA);
        $this->gradeLevel('Grade 1', $teacherB);

        $this->signIn($this->platformOwner());

        $this->getJson('/api/admin/grade-levels')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/admin/grade-levels?organization_id='.$teacherA->organization_id)->assertOk()->assertJsonCount(2, 'data');

        $this->getJson('/api/admin/grade-levels?teacher_id=999999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['teacher_id']);
    }

    public function test_the_student_masterlist_can_be_filtered_by_school_year(): void
    {
        $teacher = User::factory()->create();
        $oldYear = $this->schoolYear('2025-2026', $teacher, ['is_current' => false]);
        $newYear = $this->schoolYear('2026-2027', $teacher, ['is_current' => true]);
        $oldStudent = $this->student(['school_year_id' => $oldYear->id], $teacher);
        $this->student(['school_year_id' => $newYear->id], $teacher);

        $this->signIn($this->platformOwner());

        $response = $this->getJson('/api/admin/students?school_year_id='.$oldYear->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame($oldStudent->id, $response->json('data.0.id'));
    }

    public function test_an_admin_can_list_a_teachers_school_years(): void
    {
        $teacher = User::factory()->create();
        $otherTeacher = User::factory()->create();
        $this->schoolYear('2025-2026', $teacher, ['is_current' => false]);
        $this->schoolYear('2026-2027', $teacher, ['is_current' => true]);
        $this->schoolYear('2025-2026', $otherTeacher);

        $this->signIn($this->platformOwner());

        $this->getJson('/api/admin/school-years?teacher_id='.$teacher->id)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', '2026-2027');
    }

    public function test_the_owner_lists_every_organizations_school_years_without_a_teacher_id(): void
    {
        $teacherA = User::factory()->create();
        $teacherB = User::factory()->create();
        $this->schoolYear('2025-2026', $teacherA);
        $this->schoolYear('2026-2027', $teacherB);

        $this->signIn($this->platformOwner());

        $this->getJson('/api/admin/school-years')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', '2026-2027')
            ->assertJsonPath('data.0.organization.id', $teacherB->organization_id);

        $this->getJson('/api/admin/school-years?teacher_id=999999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['teacher_id']);
    }

    public function test_an_admin_can_list_the_paces_a_teacher_holds_booklets_of(): void
    {
        $teacher = User::factory()->create();
        $otherTeacher = User::factory()->create();
        $this->pace(['number' => '1001', 'my_stock' => 2], $teacher);
        $this->pace(['number' => '1002', 'my_stock' => 1], $teacher);
        // In the catalog, but the teacher has no booklet of it.
        $this->pace(['number' => '1003'], $teacher);
        $this->pace(['number' => '2001'], $otherTeacher);

        $this->signIn($this->platformOwner());

        $this->getJson('/api/admin/paces')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/api/admin/paces?branch_id='.$teacher->branch_id)->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/admin/paces?teacher_id='.$teacher->id)->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_teachers_paces_report_the_assigned_issued_and_tested_breakdown(): void
    {
        $teacher = User::factory()->create();
        $pace = $this->pace(['number' => '1001'], $teacher);

        PaceAssignment::factory()->create(['pace_id' => $pace->id]);
        PaceAssignment::factory()->issued()->create(['pace_id' => $pace->id]);
        PaceAssignment::factory()->tested()->count(2)->create(['pace_id' => $pace->id]);

        $this->signIn($this->platformOwner());

        $this->getJson('/api/admin/paces?branch_id='.$teacher->branch_id)
            ->assertOk()
            ->assertJsonPath('data.0.assignments_count', 4)
            ->assertJsonPath('data.0.tested_count', 2)
            ->assertJsonPath('data.0.issued_count', 1)
            ->assertJsonPath('data.0.assigned_count', 1);
    }

    public function test_an_admin_can_view_any_paces_detail(): void
    {
        $teacher = User::factory()->create();
        $grade = $this->gradeLevel('Grade 4', $teacher);
        $student = $this->student(['grade_level_id' => $grade->id], $teacher);
        $subject = $this->subject($grade, 'Math');
        $pace = $this->pace(['number' => '1001', 'my_stock' => 3], $teacher);
        $assignment = PaceAssignment::factory()->create([
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'pace_id' => $pace->id,
        ]);

        $this->signIn($this->platformOwner());

        $this->getJson('/api/admin/paces/'.$pace->id)
            ->assertOk()
            ->assertJsonPath('data.id', $pace->id)
            ->assertJsonPath('data.holdings_total', 3)
            ->assertJsonPath('data.holdings.0.teacher.id', $teacher->id)
            ->assertJsonCount(1, 'data.assignments')
            ->assertJsonPath('data.assignments.0.id', $assignment->id)
            ->assertJsonPath('data.assignments.0.student.id', $student->id);
    }

    public function test_viewing_an_unknown_pace_as_admin_returns_404(): void
    {
        $this->signIn($this->platformOwner());

        $this->getJson('/api/admin/paces/999999')->assertNotFound();
    }

    public function test_an_admin_can_view_any_students_detail_with_assignments_and_school_years(): void
    {
        $teacher = User::factory()->create();
        $this->schoolYear('2025-2026', $teacher, ['is_current' => false]);
        $this->schoolYear('2026-2027', $teacher, ['is_current' => true]);
        $grade = $this->gradeLevel('Grade 4', $teacher);
        $student = $this->student(['grade_level_id' => $grade->id], $teacher);
        $subject = $this->subject($grade, 'Math');
        $pace = $this->pace(['number' => '1001'], $teacher);
        $assignment = PaceAssignment::factory()->create([
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'pace_id' => $pace->id,
            'school_year' => '2026-2027',
            'quarter' => 1,
        ]);

        $this->signIn($this->platformOwner());

        $this->getJson('/api/admin/students/'.$student->id)
            ->assertOk()
            ->assertJsonPath('data.id', $student->id)
            ->assertJsonPath('data.registered_by.id', $teacher->id)
            // The page heading names the school the student's class belongs to.
            ->assertJsonPath('data.section.grade_level.branch.organization.name', $teacher->organization->name)
            ->assertJsonPath('data.section.grade_level.subjects.0.name', 'Math')
            ->assertJsonPath('data.section.teaching_assignments.0.teacher.id', $teacher->id)
            ->assertJsonCount(1, 'data.assignments')
            ->assertJsonPath('data.assignments.0.id', $assignment->id)
            ->assertJsonCount(2, 'school_years')
            ->assertJsonPath('school_years.0.name', '2026-2027');
    }

    public function test_viewing_an_unknown_student_as_admin_returns_404(): void
    {
        $this->signIn($this->platformOwner());

        $this->getJson('/api/admin/students/999999')->assertNotFound();
    }

    public function test_an_admin_enrolls_edits_moves_and_removes_students_directly(): void
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $teacher = $this->teacher($organization);
        $current = $this->schoolYear('2026-2027', $admin, ['is_current' => true]);
        $next = $this->schoolYear('2027-2028', $admin, ['is_current' => false]);
        $gradeOne = $this->gradeLevel('Grade 1', $teacher);
        $sectionOne = $this->section($gradeOne, $teacher);
        $gradeTwo = $this->gradeLevel('Grade 2', $admin, ['Math'], $next);
        $sectionTwo = $this->section($gradeTwo, $admin);

        $this->signIn($admin);

        $details = [
            'first_name' => 'Juan',
            'last_name' => 'Cruz',
            'birth_date' => '2015-03-14',
            'age' => 11,
            'sex' => 'male',
        ];

        $created = $this->postJson('/api/admin/students', $details + ['section_id' => $sectionOne->id])
            ->assertCreated()
            ->assertJsonPath('data.section.id', $sectionOne->id)
            ->assertJsonPath('data.grade_level.name', 'Grade 1')
            ->assertJsonPath('data.school_year_id', $current->id);

        $studentId = $created->json('data.id');
        $this->assertDatabaseHas('students', ['id' => $studentId, 'created_by' => $admin->id]);

        // Only sections of the school.
        $this->postJson('/api/admin/students', $details + ['section_id' => $this->section($this->gradeLevel('Grade 1', User::factory()->create()))->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['section_id']);

        $this->postJson('/api/admin/students', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name', 'last_name', 'birth_date', 'age', 'sex', 'section_id']);

        // Editing and moving to another section: the school year follows the section.
        $this->putJson('/api/admin/students/'.$studentId, ['first_name' => 'Juan Miguel', 'sex' => 'male'] + $details + ['section_id' => $sectionTwo->id])
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Juan Miguel')
            ->assertJsonPath('data.section.id', $sectionTwo->id)
            ->assertJsonPath('data.grade_level.name', 'Grade 2')
            ->assertJsonPath('data.school_year_id', $next->id);

        $this->assertDatabaseHas('students', ['id' => $studentId, 'first_name' => 'Juan Miguel', 'section_id' => $sectionTwo->id, 'school_year_id' => $next->id]);

        // The teacher of the old section no longer sees them.
        $this->forgetGuards();
        $this->signIn($teacher);
        $this->getJson('/api/students')->assertOk()->assertJsonCount(0, 'data');

        $this->forgetGuards();
        $this->signIn($admin);

        PaceAssignment::factory()->create(['student_id' => $studentId]);

        $this->deleteJson('/api/admin/students/'.$studentId)->assertNoContent();
        $this->assertDatabaseMissing('students', ['id' => $studentId]);
        $this->assertDatabaseCount('pace_assignments', 0);

        // Another school's student is out of reach.
        $foreign = $this->student([], User::factory()->create());
        $this->putJson('/api/admin/students/'.$foreign->id, $details + ['section_id' => $sectionOne->id])->assertNotFound();
        $this->deleteJson('/api/admin/students/'.$foreign->id)->assertNotFound();
        $this->assertDatabaseHas('students', ['id' => $foreign->id]);
    }

    public function test_the_admin_dashboard_can_be_narrowed_to_a_teacher_school_year_grade_level_and_subject(): void
    {
        $admin = $this->platformOwner();
        $teacherA = User::factory()->create();
        $teacherB = User::factory()->create();

        $yearA = $this->schoolYear('2026-2027', $teacherA, ['is_current' => true]);
        $yearB = $this->schoolYear('2026-2027', $teacherB, ['is_current' => true]);
        $gradeA = $this->gradeLevel('Grade 1', $teacherA, ['Math', 'English'], $yearA);
        $gradeB = $this->gradeLevel('Grade 1', $teacherB, ['Math'], $yearB);

        $pupilA = $this->student(['grade_level_id' => $gradeA->id, 'school_year_id' => $yearA->id], $teacherA);
        $pupilB = $this->student(['grade_level_id' => $gradeB->id, 'school_year_id' => $yearB->id], $teacherB);

        PaceAssignment::factory()->tested()->create([
            'student_id' => $pupilA->id,
            'subject_id' => $this->subject($gradeA, 'Math')->id,
            'pace_id' => $this->pace([], $teacherA)->id,
            'school_year' => '2026-2027',
            'score' => 70,
        ]);
        PaceAssignment::factory()->create([
            'student_id' => $pupilA->id,
            'subject_id' => $this->subject($gradeA, 'English')->id,
            'pace_id' => $this->pace([], $teacherA)->id,
            'school_year' => '2026-2027',
        ]);
        PaceAssignment::factory()->create([
            'student_id' => $pupilB->id,
            'subject_id' => $this->subject($gradeB, 'Math')->id,
            'pace_id' => $this->pace([], $teacherB)->id,
            'school_year' => '2026-2027',
        ]);

        $this->signIn($admin);

        // System-wide: every teacher's records, with the breakdowns the teacher Home page has.
        $this->getJson('/api/admin/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.teacher_id', null)
            ->assertJsonPath('data.students', 2)
            ->assertJsonPath('data.sections', 2)
            ->assertJsonPath('data.assignments', 3)
            ->assertJsonPath('data.tested', 1)
            ->assertJsonPath('data.failed_count', 1)
            ->assertJsonCount(4, 'data.by_quarter')
            ->assertJsonCount(2, 'data.by_grade_level')
            ->assertJsonPath('data.by_grade_level.0.branch_name', $this->branchOf($teacherA)->name)
            ->assertJsonCount(1, 'data.failed')
            ->assertJsonPath('data.failed.0.teacher_name', $teacherA->name)
            ->assertJsonPath('data.failed.0.failed', true)
            ->assertJsonPath('data.totals.students', 2);

        // One teacher: the sections they teach in, their students, grade levels and records.
        $this->getJson('/api/admin/dashboard/summary?teacher_id='.$teacherA->id)
            ->assertOk()
            ->assertJsonPath('data.teacher_id', $teacherA->id)
            ->assertJsonPath('data.students', 1)
            ->assertJsonPath('data.grade_levels', 1)
            ->assertJsonPath('data.sections', 1)
            ->assertJsonPath('data.assignments', 2)
            ->assertJsonCount(2, 'data.by_subject')
            ->assertJsonCount(1, 'data.by_grade_level')
            ->assertJsonPath('data.by_grade_level.0.total', 1)
            ->assertJsonPath('data.totals.students', 2);

        // A school year implies its organization; a subject narrows the records only.
        $this->getJson('/api/admin/dashboard/summary?school_year_id='.$yearA->id.'&subject_id='.$this->subject($gradeA, 'English')->id)
            ->assertOk()
            ->assertJsonPath('data.teacher_id', null)
            ->assertJsonPath('data.organization_id', $teacherA->organization_id)
            ->assertJsonPath('data.school_year.id', $yearA->id)
            ->assertJsonPath('data.students', 1)
            ->assertJsonPath('data.assignments', 1)
            ->assertJsonPath('data.tested', 0)
            ->assertJsonCount(1, 'data.by_subject');

        // A grade level narrows students and records to that level.
        $this->getJson('/api/admin/dashboard/summary?grade_level_id='.$gradeB->id)
            ->assertOk()
            ->assertJsonPath('data.students', 1)
            ->assertJsonPath('data.assignments', 1)
            ->assertJsonCount(1, 'data.by_grade_level')
            ->assertJsonPath('data.by_grade_level.0.id', $gradeB->id);

        // A section narrows students and records to that section; another section of the level has none.
        $sectionA = $pupilA->section;
        $emptySection = Section::factory()->create(['grade_level_id' => $gradeA->id, 'name' => 'Section Z']);

        $this->getJson('/api/admin/dashboard/summary?grade_level_id='.$gradeA->id)
            ->assertOk()
            ->assertJsonPath('data.sections', 2);

        $this->getJson('/api/admin/dashboard/summary?grade_level_id='.$gradeA->id.'&section_id='.$sectionA->id)
            ->assertOk()
            ->assertJsonPath('data.sections', 1)
            ->assertJsonPath('data.students', 1)
            ->assertJsonPath('data.assignments', 2);

        $this->getJson('/api/admin/dashboard/summary?section_id='.$emptySection->id)
            ->assertOk()
            ->assertJsonPath('data.sections', 1)
            ->assertJsonPath('data.students', 0)
            ->assertJsonPath('data.assignments', 0);

        $this->getJson('/api/admin/dashboard/summary?section_id=999999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['section_id']);

        // An admin is not a teacher, and unknown ids are rejected.
        $this->getJson('/api/admin/dashboard/summary?teacher_id='.$admin->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['teacher_id']);

        $this->getJson('/api/admin/dashboard/summary?subject_id=999999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject_id']);
    }

    public function test_the_school_admins_dashboard_follows_every_filter_in_every_figure(): void
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $teacherA = $this->teacher($organization, ['name' => 'Teacher A']);
        $teacherB = $this->teacher($organization, ['name' => 'Teacher B']);

        $year = $this->schoolYear('2026-2027', $admin, ['is_current' => true]);
        $otherYear = $this->schoolYear('2025-2026', $admin, ['is_current' => false]);

        // Grade 1 (teacher A: Math and English) with two students, one of whom does not take Math;
        // Grade 2 (teacher B: Math) with one student.
        $grade1 = $this->gradeLevel('Grade 1', $teacherA, ['Math', 'English'], $year);
        $grade2 = $this->gradeLevel('Grade 2', $teacherB, ['Math'], $year);
        $section1 = $this->section($grade1, $teacherA);
        $section2 = $this->section($grade2, $teacherB);
        $math1 = $this->subject($grade1, 'Math');
        $english1 = $this->subject($grade1, 'English');
        $math2 = $this->subject($grade2, 'Math');

        $p1 = $this->student(['section_id' => $section1->id], $teacherA);
        $p2 = $this->student(['section_id' => $section1->id], $teacherA);
        $q1 = $this->student(['section_id' => $section2->id], $teacherB);
        $p2->subjects()->detach($math1->id);

        $mathPace = $this->pace(['number' => '1001', 'name' => 'Math'], $teacherA);
        $englishPace = $this->pace(['number' => '1002', 'name' => 'English'], $teacherA);

        $record = fn ($student, $subject, $pace) => PaceAssignment::factory()->tested()->create([
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'pace_id' => $pace->id,
            'school_year' => '2026-2027',
            'score' => 90,
        ]);

        $record($p1, $math1, $mathPace);
        $record($p1, $english1, $englishPace);
        $record($p2, $english1, $englishPace);
        $record($q1, $math2, $mathPace);

        $this->signIn($admin);

        $summary = fn (string $query = '') => $this->getJson('/api/admin/dashboard/summary'.$query)->assertOk()->json('data');
        $activity = fn (array $data) => collect($data['teacher_activity'])->keyBy('name');

        // Nothing narrowed: everything.
        $all = $summary();
        $this->assertSame([2, 2, 2, 3, 4], [$all['teachers'], $all['grade_levels'], $all['sections'], $all['students'], $all['assignments']]);
        $this->assertSame(2, $all['paces']);
        $this->assertEqualsCanonicalizing(['Teacher A', 'Teacher B'], $activity($all)->keys()->all());
        $this->assertSame(3, $activity($all)['Teacher A']['assignments_count']);

        // A subject: its records, the students who take it, the sections that offer it, the teachers of those
        // sections, the grade levels, the chart and the ranking all shrink with it (Math is Grade 1's and Grade 2's).
        $math = $summary('?subject_id='.$math1->id);
        $this->assertSame([1, 1, 1, 1, 1], [$math['teachers'], $math['grade_levels'], $math['sections'], $math['students'], $math['assignments']]);
        $this->assertSame(1, $math['paces']);
        $this->assertCount(1, $math['by_grade_level']);
        $this->assertSame(1, $math['by_grade_level'][0]['total']);
        $this->assertSame(['Teacher A'], $activity($math)->keys()->all());
        $this->assertSame([1, 1], [$activity($math)['Teacher A']['students_count'], $activity($math)['Teacher A']['assignments_count']]);
        $this->assertCount(1, $math['recent_students']);

        // English: both Grade 1 students, only teacher A.
        $english = $summary('?subject_id='.$english1->id);
        $this->assertSame([1, 1, 1, 2, 2], [$english['teachers'], $english['grade_levels'], $english['sections'], $english['students'], $english['assignments']]);

        // A PACE: the students who have it, their sections, grade levels and teachers, and the ranking.
        $pace = $summary('?pace_id='.$englishPace->id);
        $this->assertSame([1, 1, 1, 2, 2, 1], [$pace['teachers'], $pace['grade_levels'], $pace['sections'], $pace['students'], $pace['assignments'], $pace['paces']]);
        $this->assertSame(['Teacher A'], $activity($pace)->keys()->all());
        $this->assertSame(2, $activity($pace)['Teacher A']['assignments_count']);

        $thisPace = $summary('?pace_id='.$mathPace->id);
        $this->assertSame([2, 2, 2, 2, 2], [$thisPace['teachers'], $thisPace['grade_levels'], $thisPace['sections'], $thisPace['students'], $thisPace['assignments']]);

        // A grade level and a section.
        $level = $summary('?grade_level_id='.$grade2->id);
        $this->assertSame([1, 1, 1, 1, 1], [$level['teachers'], $level['grade_levels'], $level['sections'], $level['students'], $level['assignments']]);
        $this->assertSame(['Teacher B'], $activity($level)->keys()->all());

        $section = $summary('?section_id='.$section1->id);
        $this->assertSame([1, 1, 2, 3], [$section['teachers'], $section['sections'], $section['students'], $section['assignments']]);
        $this->assertSame(['Teacher A'], $activity($section)->keys()->all());

        // A school year with nothing in it empties every figure and the ranking.
        $empty = $summary('?school_year_id='.$otherYear->id);
        $this->assertSame([0, 0, 0, 0, 0], [$empty['teachers'], $empty['grade_levels'], $empty['students'], $empty['assignments'], count($empty['teacher_activity'])]);

        $current = $summary('?school_year_id='.$year->id);
        $this->assertSame([2, 3, 4], [$current['teachers'], $current['students'], $current['assignments']]);

        // A teacher together with a subject: only where that teacher teaches it.
        $taught = $summary("?teacher_id={$teacherA->id}&subject_id={$english1->id}");
        $this->assertSame([1, 2, 2], [$taught['sections'], $taught['students'], $taught['assignments']]);

        $notTaught = $summary("?teacher_id={$teacherB->id}&subject_id={$english1->id}");
        $this->assertSame([0, 0, 0, 0, 0], [$notTaught['teachers'], $notTaught['sections'], $notTaught['students'], $notTaught['assignments'], count($notTaught['teacher_activity'])]);

        // Everything together still adds up: Grade 1 · its section · English · the English PACE.
        $together = $summary("?school_year_id={$year->id}&grade_level_id={$grade1->id}&section_id={$section1->id}&subject_id={$english1->id}&pace_id={$englishPace->id}");
        $this->assertSame([1, 1, 1, 1, 2, 2, 1], [$together['teachers'], $together['grade_levels'], $together['sections'], $together['paces'], $together['students'], $together['assignments'], count($together['teacher_activity'])]);
        $this->assertSame(2, $together['by_grade_level'][0]['total']);
    }

    public function test_an_admin_can_view_the_system_wide_dashboard_summary(): void
    {
        $admin = $this->platformOwner();
        $teacherA = User::factory()->create();
        $teacherB = User::factory()->create();

        // Explicit, non-colliding school years/grade levels for both teachers, so
        // nothing here is left to the test helpers' own auto-creation fallbacks.
        $yearA = $this->schoolYear('2025-2026', $teacherA, ['is_current' => true]);
        $yearB = $this->schoolYear('2026-2027', $teacherB, ['is_current' => true]);
        $gradeA = $this->gradeLevel('Grade 1', $teacherA, self::DEFAULT_SUBJECTS, $yearA);
        $gradeB = $this->gradeLevel('Grade 2', $teacherB, self::DEFAULT_SUBJECTS, $yearB);

        $studentA = $this->student(['first_name' => 'Ana', 'grade_level_id' => $gradeA->id, 'school_year_id' => $yearA->id], $teacherA);
        $this->student(['first_name' => 'Ben', 'grade_level_id' => $gradeB->id, 'school_year_id' => $yearB->id], $teacherB);

        $paceA = $this->pace([], $teacherA);
        // pace_id is given explicitly: PaceAssignment::factory()'s default would
        // otherwise create its own unrelated Pace.
        PaceAssignment::factory()->tested()->create(['student_id' => $studentA->id, 'pace_id' => $paceA->id]);

        $this->signIn($admin);

        $response = $this->getJson('/api/admin/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.totals.teachers', 2)
            ->assertJsonPath('data.totals.admins', 0)
            ->assertJsonPath('data.totals.school_years', 2)
            ->assertJsonPath('data.totals.grade_levels', 2)
            ->assertJsonPath('data.totals.sections', 2)
            ->assertJsonPath('data.totals.students', 2)
            ->assertJsonPath('data.totals.paces', 1)
            ->assertJsonPath('data.totals.assignments', 1)
            ->assertJsonPath('data.totals.tested', 1)
            ->assertJsonCount(2, 'data.recent_students')
            ->assertJsonCount(3, 'data.recent_users')
            ->assertJsonCount(2, 'data.school_years')
            // Newest school year name first: "2026-2027" (teacher B) before "2025-2026" (teacher A).
            ->assertJsonPath('data.school_years.0.id', $yearB->id)
            ->assertJsonPath('data.school_years.0.organization.id', $teacherB->organization_id)
            ->assertJsonPath('data.school_years.0.students_count', 1)
            ->assertJsonPath('data.school_years.0.grade_levels_count', 1)
            ->assertJsonPath('data.school_years.1.id', $yearA->id)
            ->assertJsonPath('data.school_years.1.organization.id', $teacherA->organization_id)
            ->assertJsonPath('data.school_years.1.students_count', 1)
            ->assertJsonPath('data.school_years.1.grade_levels_count', 1);

        $studentIds = array_column($response->json('data.recent_students'), 'id');
        $this->assertContains($studentA->id, $studentIds);

        $recentStudent = collect($response->json('data.recent_students'))->firstWhere('id', $studentA->id);
        $this->assertSame($teacherA->id, $recentStudent['registered_by']['id']);
    }
}
