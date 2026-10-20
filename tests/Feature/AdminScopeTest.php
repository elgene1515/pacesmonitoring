<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Plan;
use App\Models\User;

/**
 * School-admin specifics added on top of the tenancy layer: branch-aware
 * analytics, read-only grade level / subject pages, the plan's admin limit,
 * and the school's address coming from its main branch.
 */
class AdminScopeTest extends ApiTestCase
{
    public function test_the_branch_filter_narrows_the_tiles_rankings_and_recent_lists(): void
    {
        $organization = $this->organization();
        $main = $organization->branches()->firstOrFail();
        $north = Branch::factory()->create(['organization_id' => $organization->id, 'name' => 'North']);
        $admin = $this->admin($organization);
        $teacherA = User::factory()->inOrganization($organization, $main)->create(['name' => 'Ann Main']);
        $teacherB = User::factory()->inOrganization($organization, $north)->create(['name' => 'Bob North']);

        $this->student([], $teacherA);
        $this->student([], $teacherB);
        $this->student([], $teacherB);

        $this->signIn($admin);

        $this->getJson('/api/admin/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.teachers', 2)
            ->assertJsonPath('data.students', 3)
            ->assertJsonCount(2, 'data.teacher_activity')
            ->assertJsonCount(3, 'data.recent_students')
            ->assertJsonCount(3, 'data.recent_users');

        $this->getJson('/api/admin/dashboard/summary?branch_id='.$north->id)
            ->assertOk()
            ->assertJsonPath('data.teachers', 1)
            ->assertJsonPath('data.students', 2)
            ->assertJsonCount(1, 'data.teacher_activity')
            ->assertJsonPath('data.teacher_activity.0.name', 'Bob North')
            ->assertJsonCount(2, 'data.recent_students')
            ->assertJsonCount(1, 'data.recent_users')
            ->assertJsonPath('data.recent_users.0.id', $teacherB->id)
            // The headline totals stay school-wide.
            ->assertJsonPath('data.totals.students', 3);

        $this->getJson('/api/admin/dashboard/summary?teacher_id='.$teacherA->id)
            ->assertOk()
            ->assertJsonPath('data.teachers', 1)
            ->assertJsonCount(1, 'data.teacher_activity')
            ->assertJsonPath('data.teacher_activity.0.name', 'Ann Main')
            ->assertJsonCount(1, 'data.recent_students');
    }

    public function test_an_admins_subject_page_names_the_teacher_and_their_branch(): void
    {
        $organization = $this->organization();
        $north = Branch::factory()->create(['organization_id' => $organization->id, 'name' => 'North']);
        $teacher = User::factory()->inOrganization($organization, $north)->create(['name' => 'Bob North', 'email' => 'bob@school.test']);
        $math = $this->subject($this->gradeLevel('Grade 3', $teacher), 'Math');

        $this->signIn($this->admin($organization));

        $this->getJson('/api/admin/subjects/'.$math->id)
            ->assertOk()
            ->assertJsonPath('data.grade_level.teacher.id', $teacher->id)
            ->assertJsonPath('data.grade_level.teacher.name', 'Bob North')
            ->assertJsonPath('data.grade_level.teacher.email', 'bob@school.test')
            ->assertJsonPath('data.grade_level.teacher.branch_name', 'North')
            // The grade level's school year is still there next to the teacher.
            ->assertJsonPath('data.grade_level.school_year.name', $math->gradeLevel->schoolYear->name);

        // The teacher's own subject page does not carry it.
        $this->forgetGuards();
        $this->signIn($teacher);

        $this->getJson('/api/subjects/'.$math->id)
            ->assertOk()
            ->assertJsonMissingPath('data.grade_level.teacher');
    }

    public function test_an_admins_pace_page_names_the_teacher_and_their_branch(): void
    {
        $organization = $this->organization();
        $north = Branch::factory()->create(['organization_id' => $organization->id, 'name' => 'North']);
        $teacher = User::factory()->inOrganization($organization, $north)->create(['name' => 'Bob North', 'email' => 'bob@school.test']);
        $pace = $this->pace(['number' => '1042', 'name' => 'Word Building', 'stock' => 4], $teacher);

        $this->signIn($this->admin($organization));

        $this->getJson('/api/admin/paces/'.$pace->id)
            ->assertOk()
            ->assertJsonPath('data.teacher.id', $teacher->id)
            ->assertJsonPath('data.teacher.name', 'Bob North')
            ->assertJsonPath('data.teacher.email', 'bob@school.test')
            ->assertJsonPath('data.teacher.branch_name', 'North')
            // The rest of the page's data is still there next to the teacher.
            ->assertJsonPath('data.number', '1042')
            ->assertJsonPath('data.stock', 4)
            ->assertJsonPath('data.assignments_count', 0);

        // The teacher's own PACE page does not carry it.
        $this->forgetGuards();
        $this->signIn($teacher);

        $this->getJson('/api/paces/'.$pace->id)
            ->assertOk()
            ->assertJsonMissingPath('data.teacher');
    }

    public function test_the_students_list_can_be_filtered_by_branch(): void
    {
        $organization = $this->organization();
        $main = $organization->branches()->firstOrFail();
        $north = Branch::factory()->create(['organization_id' => $organization->id, 'name' => 'North']);
        $teacherA = User::factory()->inOrganization($organization, $main)->create(['name' => 'Ann Main']);
        $teacherB = User::factory()->inOrganization($organization, $north)->create(['name' => 'Bob North']);

        $fromMain = $this->student(['first_name' => 'Mia'], $teacherA);
        $fromNorth = $this->student(['first_name' => 'Noah'], $teacherB);

        // A student of another school never shows up, whatever the branch.
        $this->student(['first_name' => 'Zed'], User::factory()->create());

        $this->signIn($this->admin($organization));

        $ids = fn (string $query): array => array_column($this->getJson('/api/admin/students'.$query)->assertOk()->json('data'), 'id');

        $this->assertEqualsCanonicalizing([$fromMain->id, $fromNorth->id], $ids(''));
        $this->assertSame([$fromNorth->id], $ids('?branch_id='.$north->id));
        $this->assertSame([$fromMain->id], $ids('?branch_id='.$main->id));

        // Combines with the other filters, and a student's row names the teacher's branch.
        $this->assertSame([], $ids('?branch_id='.$north->id.'&teacher_id='.$teacherA->id));
        $this->assertSame([$fromNorth->id], $ids('?branch_id='.$north->id.'&search=noah'));

        $this->getJson('/api/admin/students?branch_id='.$north->id)
            ->assertJsonPath('data.0.creator.branch_name', 'North');

        // A branch of another school matches nothing.
        $foreign = Branch::factory()->create(['organization_id' => $this->organization()->id, 'name' => 'Elsewhere']);
        $this->assertSame([], $ids('?branch_id='.$foreign->id));
    }

    public function test_the_home_summary_can_be_narrowed_to_one_pace(): void
    {
        $organization = $this->organization();
        $teacher = $this->teacher($organization);
        $grade = $this->gradeLevel('Grade 3', $teacher, ['Math']);
        $math = $this->subject($grade, 'Math');
        $student = $this->student(['grade_level_id' => $grade->id], $teacher);
        $paceA = $this->pace(['number' => '1001'], $teacher);
        $paceB = $this->pace(['number' => '1002'], $teacher);
        $year = $grade->schoolYear->name;

        $this->assignment(['student_id' => $student->id, 'pace_id' => $paceA->id, 'subject_id' => $math->id, 'school_year' => $year, 'score' => 90], $teacher);
        $this->assignment(['student_id' => $student->id, 'pace_id' => $paceB->id, 'subject_id' => $math->id, 'school_year' => $year, 'score' => 60], $teacher);
        $this->assignment(['student_id' => $student->id, 'pace_id' => $paceB->id, 'subject_id' => $math->id, 'school_year' => $year, 'score' => 70], $teacher);

        $this->signIn($this->admin($organization));

        $this->getJson('/api/admin/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.paces', 2)
            ->assertJsonPath('data.assignments', 3);

        $this->getJson('/api/admin/dashboard/summary?pace_id='.$paceA->id)
            ->assertOk()
            ->assertJsonPath('data.paces', 1)
            ->assertJsonPath('data.assignments', 1)
            ->assertJsonPath('data.passed', 1)
            ->assertJsonPath('data.failed_count', 0)
            // The headline totals stay unfiltered.
            ->assertJsonPath('data.totals.paces', 2);

        $this->getJson('/api/admin/dashboard/summary?teacher_id='.$teacher->id.'&pace_id='.$paceB->id)
            ->assertOk()
            ->assertJsonPath('data.paces', 1)
            ->assertJsonPath('data.assignments', 2)
            ->assertJsonPath('data.failed_count', 2);

        // Another school's PACE matches no records here, and unknown ids are rejected.
        $foreign = $this->pace([], User::factory()->create());
        $this->getJson('/api/admin/dashboard/summary?pace_id='.$foreign->id)
            ->assertOk()
            ->assertJsonPath('data.paces', 0)
            ->assertJsonPath('data.assignments', 0);

        $this->getJson('/api/admin/dashboard/summary?pace_id=999999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pace_id']);
    }

    public function test_an_admin_can_open_a_teachers_grade_level_and_subject_read_only(): void
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $teacher = $this->teacher($organization);
        $grade = $this->gradeLevel('Grade 3', $teacher, ['Math', 'English']);
        $math = $this->subject($grade, 'Math');
        $student = $this->student(['grade_level_id' => $grade->id], $teacher);
        $this->assignment(['student_id' => $student->id, 'subject_id' => $math->id], $teacher);

        $stranger = User::factory()->create();
        $foreignGrade = $this->gradeLevel('Grade 3', $stranger, ['Math']);

        $this->signIn($admin);

        $this->getJson('/api/admin/grade-levels/'.$grade->id)
            ->assertOk()
            ->assertJsonPath('data.id', $grade->id)
            ->assertJsonPath('data.user_id', $teacher->id)
            ->assertJsonCount(2, 'data.subjects')
            ->assertJsonCount(1, 'data.assignments')
            ->assertJsonPath('data.assignments.0.student.id', $student->id);

        $this->getJson('/api/admin/subjects/'.$math->id)
            ->assertOk()
            ->assertJsonPath('data.id', $math->id)
            ->assertJsonPath('data.grade_level.id', $grade->id)
            ->assertJsonCount(1, 'data.assignments');

        // Another school's grade level and subject are out of reach.
        $this->getJson('/api/admin/grade-levels/'.$foreignGrade->id)->assertNotFound();
        $this->getJson('/api/admin/subjects/'.$this->subject($foreignGrade, 'Math')->id)->assertNotFound();

        // The platform staff see them all.
        $this->forgetGuards();
        $this->signIn($this->platformOwner());
        $this->getJson('/api/admin/grade-levels/'.$foreignGrade->id)->assertOk();
    }

    public function test_the_plans_admin_limit_is_enforced_when_promoting(): void
    {
        $organization = $this->organization();
        $organization->currentSubscription->plan->update(['max_admins' => 1]);
        $this->admin($organization);
        $teacher = $this->teacher($organization);

        $this->signIn($this->platformOwner());

        $this->getJson('/api/owner/plans')->assertOk()->assertJsonPath('data.0.max_admins', 1);

        $this->putJson('/api/owner/users/'.$teacher->id.'/role', ['role' => 'admin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);

        $organization->currentSubscription->plan->update(['max_admins' => 2]);

        $this->putJson('/api/owner/users/'.$teacher->id.'/role', ['role' => 'admin'])
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');
    }

    public function test_the_organization_page_reports_branch_student_counts_and_the_main_branch_address(): void
    {
        $organization = $this->organization(['address' => null]);
        $main = $organization->branches()->firstOrFail();
        $main->update(['address' => '1 Main St']);
        $north = Branch::factory()->create(['organization_id' => $organization->id, 'name' => 'North', 'address' => '9 North Ave']);
        $admin = $this->admin($organization);
        $teacherA = User::factory()->inOrganization($organization, $main)->create();
        $teacherB = User::factory()->inOrganization($organization, $north)->create();
        $this->student([], $teacherA);
        $this->student([], $teacherB);
        $this->student([], $teacherB);

        $this->signIn($admin);

        $response = $this->getJson('/api/admin/organization')
            ->assertOk()
            ->assertJsonPath('data.address', '1 Main St')
            ->assertJsonPath('data.students_count', 3);

        $counts = collect($response->json('data.branches'))->pluck('students_count', 'id');
        $this->assertSame(1, $counts[$main->id]);
        $this->assertSame(2, $counts[$north->id]);

        // A student's detail names the teacher's school and branch.
        $student = $this->student([], $teacherB);
        $this->getJson('/api/admin/students/'.$student->id)
            ->assertOk()
            ->assertJsonPath('data.creator.organization_name', $organization->name)
            ->assertJsonPath('data.creator.branch_name', 'North');
    }

    public function test_role_labels_follow_the_platform_naming(): void
    {
        $this->assertSame('CEO', \App\Enums\Role::Owner->label());
        $this->assertSame('Admin', \App\Enums\Role::ViceAdmin->label());
        $this->assertSame('School Admin', \App\Enums\Role::Admin->label());
        $this->assertSame('Teacher', \App\Enums\Role::Teacher->label());
        $this->assertInstanceOf(Plan::class, Plan::query()->first());
    }
}
