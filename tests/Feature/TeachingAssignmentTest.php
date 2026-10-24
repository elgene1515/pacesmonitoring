<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\GradeLevel;
use App\Models\Organization;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TeachingAssignmentRequest;
use App\Models\User;

/**
 * Who teaches what: an admin gives a section + subject to a teacher, or a
 * teacher asks for an open slot and the admin decides.
 */
class TeachingAssignmentTest extends ApiTestCase
{
    private Organization $organization;

    private User $admin;

    private User $ann;

    private User $bob;

    private GradeLevel $grade;

    private Section $section;

    private Subject $math;

    private Subject $english;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->organization();
        $this->admin = $this->admin($this->organization);
        $this->ann = $this->teacher($this->organization, ['name' => 'Ann Teacher']);
        $this->bob = $this->teacher($this->organization, ['name' => 'Bob Teacher']);
        $this->grade = $this->gradeLevel('Grade 1', $this->admin, ['Math', 'English']);
        $this->section = $this->section($this->grade, $this->admin);
        $this->math = $this->subject($this->grade, 'Math');
        $this->english = $this->subject($this->grade, 'English');
    }

    private function slot(Section $section, Subject $subject): string
    {
        return '/api/admin/sections/'.$section->id.'/subjects/'.$subject->id.'/teacher';
    }

    public function test_an_admin_assigns_a_teacher_to_a_subject_in_a_section_and_can_reassign_or_unassign(): void
    {
        $this->signIn($this->admin);

        $this->putJson($this->slot($this->section, $this->math), ['teacher_id' => $this->ann->id])
            ->assertOk()
            ->assertJsonPath('data.section_id', $this->section->id)
            ->assertJsonPath('data.subject_id', $this->math->id)
            ->assertJsonPath('data.teacher_id', $this->ann->id)
            ->assertJsonPath('data.assigned_by', $this->admin->id)
            ->assertJsonPath('data.teacher.name', 'Ann Teacher')
            ->assertJsonPath('data.subject.name', 'Math')
            ->assertJsonPath('data.section.name', $this->section->name);

        $this->assertDatabaseCount('teaching_assignments', 1);

        // One teacher per slot: giving it to Bob takes it from Ann.
        $this->putJson($this->slot($this->section, $this->math), ['teacher_id' => $this->bob->id])
            ->assertOk()
            ->assertJsonPath('data.teacher_id', $this->bob->id);

        $this->assertDatabaseCount('teaching_assignments', 1);

        $this->getJson('/api/admin/teaching-assignments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.teacher.id', $this->bob->id)
            ->assertJsonPath('data.0.section.grade_level.name', 'Grade 1');

        $this->getJson('/api/admin/teaching-assignments?teacher_id='.$this->ann->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/teaching-assignments?section_id='.$this->section->id)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/teaching-assignments?branch_id='.$this->admin->branch_id)->assertOk()->assertJsonCount(1, 'data');

        $this->deleteJson($this->slot($this->section, $this->math))->assertNoContent();
        $this->assertDatabaseCount('teaching_assignments', 0);

        // Unassigning an open slot is harmless.
        $this->deleteJson($this->slot($this->section, $this->math))->assertNoContent();
    }

    public function test_assigning_a_teacher_is_validated(): void
    {
        $this->signIn($this->admin);

        $this->putJson($this->slot($this->section, $this->math), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['teacher_id']);

        // Only teachers of the section's branch.
        $this->putJson($this->slot($this->section, $this->math), ['teacher_id' => $this->admin->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['teacher_id']);

        $north = Branch::factory()->create(['organization_id' => $this->organization->id, 'name' => 'North']);
        $northTeacher = $this->teacher($this->organization, [], $north);

        $this->putJson($this->slot($this->section, $this->math), ['teacher_id' => $northTeacher->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['teacher_id']);

        $this->putJson($this->slot($this->section, $this->math), ['teacher_id' => User::factory()->create()->id])
            ->assertNotFound();

        // Only subjects of the section's grade level.
        $otherMath = $this->subject($this->gradeLevel('Grade 2', $this->admin, ['Math']), 'Math');

        $this->putJson($this->slot($this->section, $otherMath), ['teacher_id' => $this->ann->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject_id']);

        // Another school's section is out of reach.
        $this->putJson($this->slot(Section::factory()->create(), $this->math), ['teacher_id' => $this->ann->id])
            ->assertNotFound();

        $this->assertDatabaseCount('teaching_assignments', 0);
    }

    public function test_a_teacher_requests_an_open_slot_and_the_admin_accepts_it(): void
    {
        $this->signIn($this->ann);

        $this->postJson('/api/teaching-assignment-requests', [
            'section_id' => $this->section->id,
            'subject_id' => $this->math->id,
            'message' => 'I taught Grade 1 Math last year.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.user_id', $this->ann->id)
            ->assertJsonPath('data.section_id', $this->section->id)
            ->assertJsonPath('data.subject_id', $this->math->id)
            ->assertJsonPath('data.message', 'I taught Grade 1 Math last year.')
            ->assertJsonPath('data.section.grade_level.school_year.name', $this->grade->schoolYear->name)
            ->assertJsonPath('data.subject.name', 'Math');

        // Asking twice, for a taken slot, for a subject of another grade level, or for a section of another branch.
        $this->postJson('/api/teaching-assignment-requests', ['section_id' => $this->section->id, 'subject_id' => $this->math->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject_id']);

        $this->assign($this->bob, $this->section, $this->english);

        $this->postJson('/api/teaching-assignment-requests', ['section_id' => $this->section->id, 'subject_id' => $this->english->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject_id']);

        $otherMath = $this->subject($this->gradeLevel('Grade 2', $this->admin, ['Math']), 'Math');

        $this->postJson('/api/teaching-assignment-requests', ['section_id' => $this->section->id, 'subject_id' => $otherMath->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject_id']);

        $north = Branch::factory()->create(['organization_id' => $this->organization->id, 'name' => 'North']);
        $northGrade = $this->gradeLevel('Grade 1', $this->teacher($this->organization, [], $north), ['Math']);
        $northSection = $this->emptySection($northGrade);

        $this->postJson('/api/teaching-assignment-requests', ['section_id' => $northSection->id, 'subject_id' => $this->subject($northGrade, 'Math')->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['section_id']);

        $this->postJson('/api/teaching-assignment-requests', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['section_id', 'subject_id']);

        $this->getJson('/api/teaching-assignment-requests')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'pending');
        $this->getJson('/api/teaching-assignments')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/sections')->assertOk()->assertJsonCount(0, 'data');

        // The admin sees it and accepts.
        $this->forgetGuards();
        $this->signIn($this->admin);

        $list = $this->getJson('/api/admin/teaching-assignment-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.teacher.name', 'Ann Teacher')
            ->assertJsonPath('data.0.teacher.branch_name', $this->branchOf($this->ann)->name)
            ->assertJsonPath('data.0.section.name', $this->section->name)
            ->assertJsonPath('data.0.subject.name', 'Math');

        $requestId = $list->json('data.0.id');

        $this->getJson('/api/admin/teaching-assignment-requests?status=pending')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/teaching-assignment-requests?status=declined')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/teaching-assignment-requests?branch_id='.$north->id)->assertOk()->assertJsonCount(0, 'data');

        $this->postJson('/api/admin/teaching-assignment-requests/'.$requestId.'/accept')
            ->assertOk()
            ->assertJsonPath('data.teacher_id', $this->ann->id)
            ->assertJsonPath('data.section_id', $this->section->id)
            ->assertJsonPath('data.subject_id', $this->math->id)
            ->assertJsonPath('data.assigned_by', $this->admin->id);

        $this->assertDatabaseHas('teaching_assignments', ['section_id' => $this->section->id, 'subject_id' => $this->math->id, 'teacher_id' => $this->ann->id]);
        $this->assertDatabaseHas('teaching_assignment_requests', ['id' => $requestId, 'status' => 'accepted', 'decided_by' => $this->admin->id]);

        // Deciding twice is refused.
        $this->postJson('/api/admin/teaching-assignment-requests/'.$requestId.'/accept')->assertUnprocessable();

        // Ann now teaches the slot.
        $this->forgetGuards();
        $this->signIn($this->ann);

        $this->getJson('/api/teaching-assignments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject.name', 'Math')
            ->assertJsonPath('data.0.section.id', $this->section->id);

        $this->getJson('/api/teaching-assignment-requests')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'accepted')
            ->assertJsonPath('data.0.decider.id', $this->admin->id);

        $this->getJson('/api/sections')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_requests_can_be_declined_by_the_admin_or_withdrawn_by_the_teacher(): void
    {
        $this->signIn($this->ann);

        $requestId = $this->postJson('/api/teaching-assignment-requests', ['section_id' => $this->section->id, 'subject_id' => $this->math->id])
            ->assertCreated()
            ->json('data.id');

        $this->forgetGuards();
        $this->signIn($this->admin);

        $this->postJson('/api/admin/teaching-assignment-requests/'.$requestId.'/decline', ['reason' => 'Bob has it this year.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'declined')
            ->assertJsonPath('data.decline_reason', 'Bob has it this year.')
            ->assertJsonPath('data.decider.id', $this->admin->id);

        $this->postJson('/api/admin/teaching-assignment-requests/'.$requestId.'/decline')->assertUnprocessable();
        $this->assertDatabaseCount('teaching_assignments', 0);

        // Another school's request is out of reach.
        $this->postJson('/api/admin/teaching-assignment-requests/'.TeachingAssignmentRequest::factory()->create()->id.'/accept')->assertNotFound();

        // The teacher sees the reason and may ask again, then change their mind.
        $this->forgetGuards();
        $this->signIn($this->ann);

        $this->getJson('/api/teaching-assignment-requests')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'declined')
            ->assertJsonPath('data.0.decline_reason', 'Bob has it this year.');

        $again = $this->postJson('/api/teaching-assignment-requests', ['section_id' => $this->section->id, 'subject_id' => $this->math->id])
            ->assertCreated()
            ->json('data.id');

        $this->deleteJson('/api/teaching-assignment-requests/'.$again)
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->deleteJson('/api/teaching-assignment-requests/'.$again)->assertUnprocessable();
        $this->deleteJson('/api/teaching-assignment-requests/'.TeachingAssignmentRequest::factory()->create()->id)->assertNotFound();

        // A colleague cannot withdraw it either.
        $pending = $this->postJson('/api/teaching-assignment-requests', ['section_id' => $this->section->id, 'subject_id' => $this->math->id])
            ->assertCreated()
            ->json('data.id');

        $this->forgetGuards();
        $this->signIn($this->bob);

        $this->deleteJson('/api/teaching-assignment-requests/'.$pending)->assertNotFound();
        $this->assertDatabaseHas('teaching_assignment_requests', ['id' => $pending, 'status' => 'pending']);
    }

    public function test_assigning_a_slot_directly_closes_the_other_teachers_requests(): void
    {
        $this->signIn($this->ann);
        $annRequest = $this->postJson('/api/teaching-assignment-requests', ['section_id' => $this->section->id, 'subject_id' => $this->math->id])->assertCreated()->json('data.id');

        $this->forgetGuards();
        $this->signIn($this->bob);
        $bobRequest = $this->postJson('/api/teaching-assignment-requests', ['section_id' => $this->section->id, 'subject_id' => $this->math->id])->assertCreated()->json('data.id');

        $this->forgetGuards();
        $this->signIn($this->admin);

        $this->putJson($this->slot($this->section, $this->math), ['teacher_id' => $this->bob->id])->assertOk();

        $this->assertDatabaseHas('teaching_assignment_requests', ['id' => $bobRequest, 'status' => 'accepted', 'decided_by' => $this->admin->id]);
        $this->assertDatabaseHas('teaching_assignment_requests', [
            'id' => $annRequest,
            'status' => 'declined',
            'decline_reason' => 'The subject was assigned to another teacher.',
        ]);
    }

    public function test_a_teacher_who_leaves_the_branch_loses_their_assignments_and_returns_their_booklets(): void
    {
        $this->assign($this->ann, $this->section, $this->math);
        $this->assign($this->bob, $this->section, $this->english);
        $pace = $this->pace(['number' => '1001', 'name' => 'Math', 'pool_stock' => 2, 'my_stock' => 3], $this->ann);
        $this->holding($pace, $this->bob, 1);
        $north = Branch::factory()->create(['organization_id' => $this->organization->id, 'name' => 'North']);

        $this->signIn($this->admin);

        // Moving to another branch.
        $this->putJson('/api/admin/users/'.$this->ann->id.'/branch', ['branch_id' => $north->id])
            ->assertOk()
            ->assertJsonPath('data.branch_id', $north->id);

        $this->assertDatabaseMissing('teaching_assignments', ['teacher_id' => $this->ann->id]);
        $this->assertDatabaseHas('paces', ['id' => $pace->id, 'pool_stock' => 5]);
        $this->assertDatabaseHas('pace_stock_holdings', ['pace_id' => $pace->id, 'user_id' => $this->ann->id, 'quantity' => 0]);
        $this->assertDatabaseHas('pace_stock_movements', ['pace_id' => $pace->id, 'type' => 'returned', 'quantity' => 3, 'from_user_id' => $this->ann->id, 'performed_by' => $this->admin->id]);

        // Leaving the school.
        $this->deleteJson('/api/admin/users/'.$this->bob->id.'/membership')->assertNoContent();

        $this->assertDatabaseMissing('teaching_assignments', ['teacher_id' => $this->bob->id]);
        $this->assertDatabaseHas('paces', ['id' => $pace->id, 'pool_stock' => 6]);
        $this->assertDatabaseHas('pace_stock_holdings', ['pace_id' => $pace->id, 'user_id' => $this->bob->id, 'quantity' => 0]);
        $this->assertDatabaseHas('users', ['id' => $this->bob->id, 'organization_id' => null, 'branch_id' => null]);
    }

    public function test_roles_are_enforced_on_teaching_assignments(): void
    {
        $this->getJson('/api/teaching-assignments')->assertUnauthorized();
        $this->getJson('/api/admin/teaching-assignments')->assertUnauthorized();

        $this->signIn($this->admin);
        $this->getJson('/api/teaching-assignments')->assertForbidden();
        $this->postJson('/api/teaching-assignment-requests', ['section_id' => $this->section->id, 'subject_id' => $this->math->id])->assertForbidden();

        $this->forgetGuards();
        $this->signIn($this->ann);
        $this->getJson('/api/admin/teaching-assignments')->assertForbidden();
        $this->putJson($this->slot($this->section, $this->math), ['teacher_id' => $this->ann->id])->assertForbidden();
        $this->getJson('/api/admin/teaching-assignment-requests')->assertForbidden();

        $this->assertDatabaseCount('teaching_assignments', 0);
        $this->assertDatabaseCount('teaching_assignment_requests', 0);
    }
}
