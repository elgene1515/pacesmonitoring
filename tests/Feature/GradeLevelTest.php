<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\PaceAssignment;
use App\Models\Section;
use App\Models\User;

/**
 * The curriculum: admins set up grade levels (per branch and school year)
 * with their subjects and sections; teachers see the part their teaching
 * assignments cover, read-only.
 */
class GradeLevelTest extends ApiTestCase
{
    public function test_an_admin_sets_up_grade_levels_subjects_and_sections_under_a_school_year(): void
    {
        $admin = $this->signIn($this->admin());
        $branch = $this->branchOf($admin);
        $year = $this->schoolYear('2026-2027', $admin);

        $first = $this->postJson('/api/admin/grade-levels', ['branch_id' => $branch->id, 'school_year_id' => $year->id, 'name' => 'Grade 1'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Grade 1')
            ->assertJsonPath('data.sort_order', 0)
            ->assertJsonPath('data.branch_id', $branch->id)
            ->assertJsonPath('data.branch.name', $branch->name)
            ->assertJsonPath('data.school_year_id', $year->id)
            ->assertJsonPath('data.school_year.name', '2026-2027')
            ->assertJsonPath('data.students_count', 0)
            ->assertJsonPath('data.subjects_count', 0)
            ->assertJsonPath('data.sections_count', 0)
            ->assertJsonPath('data.subjects', [])
            ->assertJsonPath('data.sections', []);

        $this->postJson('/api/admin/grade-levels', ['branch_id' => $branch->id, 'school_year_id' => $year->id, 'name' => 'Grade 2'])
            ->assertCreated()
            ->assertJsonPath('data.sort_order', 1);

        $gradeLevelId = $first->json('data.id');

        $this->assertDatabaseHas('grade_levels', [
            'id' => $gradeLevelId,
            'branch_id' => $branch->id,
            'school_year_id' => $year->id,
            'created_by' => $admin->id,
        ]);

        $this->postJson('/api/admin/grade-levels/'.$gradeLevelId.'/subjects', ['name' => 'Math'])
            ->assertCreated()
            ->assertJsonPath('data.grade_level_id', $gradeLevelId)
            ->assertJsonPath('data.name', 'Math')
            ->assertJsonPath('data.sort_order', 0)
            ->assertJsonPath('data.assignments_count', 0);

        $this->postJson('/api/admin/grade-levels/'.$gradeLevelId.'/subjects', ['name' => 'English'])
            ->assertCreated()
            ->assertJsonPath('data.sort_order', 1);

        $this->postJson('/api/admin/grade-levels/'.$gradeLevelId.'/sections', ['name' => 'Section 1'])
            ->assertCreated()
            ->assertJsonPath('data.grade_level_id', $gradeLevelId)
            ->assertJsonPath('data.name', 'Section 1')
            ->assertJsonPath('data.sort_order', 0)
            ->assertJsonPath('data.students_count', 0)
            ->assertJsonPath('data.teaching_assignments', []);

        $this->getJson('/api/admin/grade-levels')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Grade 1')
            ->assertJsonPath('data.0.subjects_count', 2)
            ->assertJsonPath('data.0.sections_count', 1)
            ->assertJsonCount(2, 'data.0.subjects')
            ->assertJsonPath('data.0.subjects.0.name', 'Math')
            ->assertJsonPath('data.0.subjects.1.name', 'English')
            ->assertJsonCount(1, 'data.0.sections')
            ->assertJsonPath('data.0.sections.0.name', 'Section 1')
            ->assertJsonPath('data.1.name', 'Grade 2')
            ->assertJsonCount(0, 'data.1.subjects');

        $this->getJson('/api/admin/grade-levels?branch_id='.$branch->id)->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_grade_level_requires_a_branch_and_a_school_year_of_the_organization(): void
    {
        $admin = $this->signIn($this->admin());
        $branch = $this->branchOf($admin);
        $year = $this->schoolYear('2026-2027', $admin);

        $this->postJson('/api/admin/grade-levels', ['name' => 'Grade 1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch_id', 'school_year_id']);

        $stranger = $this->admin();
        $foreignYear = $this->schoolYear('2026-2027', $stranger);

        $this->postJson('/api/admin/grade-levels', ['branch_id' => $branch->id, 'school_year_id' => $foreignYear->id, 'name' => 'Grade 1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_year_id']);

        // Another school's branch is out of reach.
        $this->postJson('/api/admin/grade-levels', ['branch_id' => $stranger->branch_id, 'school_year_id' => $year->id, 'name' => 'Grade 1'])
            ->assertNotFound();

        $this->assertDatabaseCount('grade_levels', 0);
    }

    public function test_each_school_year_has_its_own_set_of_grade_levels(): void
    {
        $admin = $this->signIn($this->admin());
        $old = $this->schoolYear('2025-2026', $admin, ['is_current' => false]);
        $current = $this->schoolYear('2026-2027', $admin, ['is_current' => true]);

        $this->gradeLevel('Grade 1', $admin, ['Math', 'English'], $old);
        $this->gradeLevel('Grade 2', $admin, ['Math'], $old);
        $this->gradeLevel('Grade 1', $admin, ['Math', 'Science', 'Bible'], $current);

        // All school years, newest first.
        $all = $this->getJson('/api/admin/grade-levels')->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame(['2026-2027', '2025-2026', '2025-2026'], array_column(array_column($all->json('data'), 'school_year'), 'name'));
        $this->assertSame(['Grade 1', 'Grade 1', 'Grade 2'], array_column($all->json('data'), 'name'));

        // One school year only.
        $this->getJson('/api/admin/grade-levels?school_year_id='.$current->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Grade 1')
            ->assertJsonCount(3, 'data.0.subjects');

        $this->getJson('/api/admin/grade-levels?school_year_id='.$old->id)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonCount(2, 'data.0.subjects');

        $foreign = $this->schoolYear('2026-2027', User::factory()->create());

        $this->getJson('/api/admin/grade-levels?school_year_id='.$foreign->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_year_id']);
    }

    public function test_an_explicit_sort_order_is_respected(): void
    {
        $admin = $this->signIn($this->admin());
        $branch = $this->branchOf($admin);
        $year = $this->schoolYear('2026-2027', $admin);
        $base = ['branch_id' => $branch->id, 'school_year_id' => $year->id];

        $this->postJson('/api/admin/grade-levels', $base + ['name' => 'Kindergarten', 'sort_order' => 0])->assertCreated();
        $this->postJson('/api/admin/grade-levels', $base + ['name' => 'Grade 1', 'sort_order' => 1])->assertCreated();
        $this->postJson('/api/admin/grade-levels', $base + ['name' => 'Nursery', 'sort_order' => 0])->assertCreated();

        $response = $this->getJson('/api/admin/grade-levels')->assertOk();

        $this->assertSame(['Kindergarten', 'Nursery', 'Grade 1'], array_column($response->json('data'), 'name'));
    }

    public function test_grade_level_names_are_unique_per_branch_within_a_school_year(): void
    {
        $admin = $this->signIn($this->admin());
        $branch = $this->branchOf($admin);
        $current = $this->schoolYear('2026-2027', $admin);
        $this->gradeLevel('Grade 1', $admin);

        $this->postJson('/api/admin/grade-levels', ['branch_id' => $branch->id, 'school_year_id' => $current->id, 'name' => 'Grade 1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        // The same name is fine in another school year ...
        $next = $this->schoolYear('2027-2028', $admin, ['is_current' => false]);

        $this->postJson('/api/admin/grade-levels', ['branch_id' => $branch->id, 'school_year_id' => $next->id, 'name' => 'Grade 1'])->assertCreated();

        // ... and in another branch of the school.
        $north = Branch::factory()->create(['organization_id' => $admin->organization_id, 'name' => 'North']);

        $this->postJson('/api/admin/grade-levels', ['branch_id' => $north->id, 'school_year_id' => $current->id, 'name' => 'Grade 1'])
            ->assertCreated()
            ->assertJsonPath('data.branch.name', 'North');
    }

    public function test_subject_and_section_names_are_unique_within_a_grade_level(): void
    {
        $admin = $this->signIn($this->admin());
        $grade1 = $this->gradeLevel('Grade 1', $admin);
        $grade2 = $this->gradeLevel('Grade 2', $admin, []);

        $this->postJson('/api/admin/grade-levels/'.$grade1->id.'/subjects', ['name' => 'Math'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->postJson('/api/admin/grade-levels/'.$grade2->id.'/subjects', ['name' => 'Math'])
            ->assertCreated();

        $this->postJson('/api/admin/grade-levels/'.$grade1->id.'/sections', ['name' => 'Section 1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->postJson('/api/admin/grade-levels/'.$grade1->id.'/sections', ['name' => 'Section 2'])
            ->assertCreated()
            ->assertJsonPath('data.sort_order', 1);
    }

    public function test_grade_level_subject_and_section_payloads_are_validated(): void
    {
        $admin = $this->signIn($this->admin());
        $branch = $this->branchOf($admin);
        $year = $this->schoolYear('2026-2027', $admin);

        $this->postJson('/api/admin/grade-levels', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'branch_id', 'school_year_id']);

        $this->postJson('/api/admin/grade-levels', ['branch_id' => $branch->id, 'school_year_id' => $year->id, 'name' => str_repeat('x', 101), 'sort_order' => -1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'sort_order']);

        $grade = $this->gradeLevel('Grade 1', $admin, []);

        $this->postJson('/api/admin/grade-levels/'.$grade->id.'/subjects', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->postJson('/api/admin/grade-levels/'.$grade->id.'/sections', ['name' => '', 'sort_order' => -1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'sort_order']);

        // Another school's grade level is out of reach.
        $foreignGrade = $this->gradeLevel('Grade 1', $this->admin(), []);

        $this->postJson('/api/admin/grade-levels/'.$foreignGrade->id.'/subjects', ['name' => 'Art'])->assertNotFound();
        $this->postJson('/api/admin/grade-levels/'.$foreignGrade->id.'/sections', ['name' => 'Section 9'])->assertNotFound();

        $this->assertDatabaseMissing('subjects', ['name' => 'Art']);
        $this->assertDatabaseMissing('sections', ['name' => 'Section 9']);
    }

    public function test_an_admin_can_rename_and_reorder_grade_levels_subjects_and_sections(): void
    {
        $admin = $this->signIn($this->admin());
        $grade = $this->gradeLevel('Grade 1', $admin);
        $math = $this->subject($grade, 'Math');
        $section = $this->section($grade, $admin);
        $other = $this->schoolYear('2027-2028', $admin, ['is_current' => false]);

        // The branch and the school year never change.
        $this->putJson('/api/admin/grade-levels/'.$grade->id, ['name' => 'First Grade', 'sort_order' => 5, 'school_year_id' => $other->id])
            ->assertOk()
            ->assertJsonPath('data.name', 'First Grade')
            ->assertJsonPath('data.sort_order', 5)
            ->assertJsonPath('data.school_year_id', $grade->school_year_id)
            ->assertJsonPath('data.branch_id', $grade->branch_id)
            ->assertJsonCount(7, 'data.subjects')
            ->assertJsonCount(1, 'data.sections');

        // Keeping the same name is allowed; sort order may be omitted.
        $this->putJson('/api/admin/grade-levels/'.$grade->id, ['name' => 'First Grade'])
            ->assertOk()
            ->assertJsonPath('data.sort_order', 5);

        $this->putJson('/api/admin/subjects/'.$math->id, ['name' => 'Mathematics', 'sort_order' => 9])
            ->assertOk()
            ->assertJsonPath('data.name', 'Mathematics')
            ->assertJsonPath('data.sort_order', 9);

        // Renaming to an existing sibling's name is rejected.
        $this->putJson('/api/admin/subjects/'.$math->id, ['name' => 'English'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->putJson('/api/admin/sections/'.$section->id, ['name' => 'Section A', 'sort_order' => 3])
            ->assertOk()
            ->assertJsonPath('data.name', 'Section A')
            ->assertJsonPath('data.sort_order', 3);

        $this->putJson('/api/admin/sections/'.$section->id, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_a_grade_level_or_section_with_students_cannot_be_deleted(): void
    {
        $admin = $this->signIn($this->admin());
        $grade = $this->gradeLevel('Grade 1', $admin);
        $section = $this->section($grade, $admin);
        $this->student(['section_id' => $section->id], $admin);

        $this->deleteJson('/api/admin/grade-levels/'.$grade->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['grade_level']);

        $this->deleteJson('/api/admin/sections/'.$section->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['section']);

        $this->assertDatabaseHas('grade_levels', ['id' => $grade->id]);
        $this->assertDatabaseHas('sections', ['id' => $section->id]);
    }

    public function test_deleting_a_grade_level_removes_its_subjects_and_sections(): void
    {
        $admin = $this->signIn($this->admin());
        $grade = $this->gradeLevel('Grade 1', $admin);

        $this->deleteJson('/api/admin/grade-levels/'.$grade->id)->assertNoContent();

        $this->assertDatabaseMissing('grade_levels', ['id' => $grade->id]);
        $this->assertDatabaseMissing('subjects', ['grade_level_id' => $grade->id]);
        $this->assertDatabaseMissing('sections', ['grade_level_id' => $grade->id]);
    }

    public function test_deleting_a_subject_removes_its_records_and_teaching_assignments(): void
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $teacher = $this->teacher($organization);
        $grade = $this->gradeLevel('Grade 1', $teacher);
        $math = $this->subject($grade, 'Math');
        $student = $this->student([], $teacher);

        $this->assignment(['student_id' => $student->id, 'subject_id' => $math->id], $teacher);
        $this->assertDatabaseHas('teaching_assignments', ['subject_id' => $math->id, 'teacher_id' => $teacher->id]);

        $this->signIn($admin);

        $this->deleteJson('/api/admin/subjects/'.$math->id)->assertNoContent();

        $this->assertDatabaseMissing('subjects', ['id' => $math->id]);
        $this->assertDatabaseMissing('teaching_assignments', ['subject_id' => $math->id]);
        $this->assertDatabaseCount('teaching_assignments', 6);
        $this->assertSame(0, PaceAssignment::query()->count());
        $this->assertDatabaseHas('students', ['id' => $student->id]);

        // Removing an empty section drops its teaching assignments as well.
        $section = $this->section($grade, $teacher);
        $this->student([], $teacher)->delete();
        $student->delete();

        $this->deleteJson('/api/admin/sections/'.$section->id)->assertNoContent();

        $this->assertDatabaseMissing('sections', ['id' => $section->id]);
        $this->assertDatabaseCount('teaching_assignments', 0);
    }

    public function test_a_teacher_sees_only_the_grade_levels_sections_and_subjects_they_teach(): void
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $ann = $this->teacher($organization);
        $bob = $this->teacher($organization);

        $grade = $this->gradeLevel('Grade 1', $admin, ['Math', 'English']);
        $math = $this->subject($grade, 'Math');
        $english = $this->subject($grade, 'English');
        $first = $this->section($grade, $admin);
        $second = $this->emptySection($grade);
        $this->assign($ann, $first, $math);
        $this->assign($bob, $first, $english);
        $this->assign($bob, $second, $math);

        // Nobody teaches in this one yet.
        $other = $this->gradeLevel('Grade 2', $admin, ['Math']);

        $this->signIn($ann);

        $this->getJson('/api/grade-levels')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $grade->id)
            ->assertJsonPath('data.0.subjects_count', 2)
            ->assertJsonCount(2, 'data.0.subjects')
            ->assertJsonCount(1, 'data.0.sections')
            ->assertJsonPath('data.0.sections.0.id', $first->id)
            ->assertJsonPath('data.0.sections.0.my_subject_ids', [$math->id])
            ->assertJsonCount(2, 'data.0.sections.0.teaching_assignments');

        $this->getJson('/api/subjects')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $math->id)
            ->assertJsonCount(1, 'data.0.teaching_assignments')
            ->assertJsonPath('data.0.teaching_assignments.0.section.id', $first->id);

        $this->getJson('/api/sections')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.0.grade_level.school_year.name', $grade->schoolYear->name)
            ->assertJsonPath('data.0.my_subject_ids', [$math->id]);

        $this->getJson('/api/sections?grade_level_id='.$other->id)->assertOk()->assertJsonCount(0, 'data');

        $this->getJson('/api/grade-levels/'.$other->id)->assertNotFound();
        $this->getJson('/api/sections/'.$second->id)->assertNotFound();
        $this->getJson('/api/subjects/'.$english->id)->assertNotFound();

        // Bob teaches in both sections.
        $this->forgetGuards();
        $this->signIn($bob);

        $this->getJson('/api/sections')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/subjects')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/grade-levels/'.$grade->id)->assertOk()->assertJsonCount(2, 'data.sections');
    }

    public function test_a_teacher_can_browse_their_branchs_whole_curriculum_to_find_open_slots(): void
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $ann = $this->teacher($organization);
        $bob = $this->teacher($organization);

        $grade = $this->gradeLevel('Grade 1', $admin, ['Math', 'English']);
        $first = $this->section($grade, $admin);
        $second = $this->emptySection($grade);
        $this->assign($bob, $first, $this->subject($grade, 'English'));
        $this->gradeLevel('Grade 2', $admin, ['Math']);

        // Another branch's curriculum is not part of it.
        $north = Branch::factory()->create(['organization_id' => $organization->id, 'name' => 'North']);
        $this->gradeLevel('Grade 1', $this->teacher($organization, [], $north), ['Math']);

        $this->signIn($ann);

        $this->getJson('/api/curriculum')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $grade->id)
            ->assertJsonCount(2, 'data.0.subjects')
            ->assertJsonCount(2, 'data.0.sections')
            ->assertJsonPath('data.0.sections.0.id', $first->id)
            ->assertJsonCount(1, 'data.0.sections.0.teaching_assignments')
            ->assertJsonPath('data.0.sections.0.teaching_assignments.0.teacher.id', $bob->id)
            ->assertJsonPath('data.0.sections.0.my_subject_ids', [])
            ->assertJsonPath('data.0.sections.1.id', $second->id)
            ->assertJsonCount(0, 'data.0.sections.1.teaching_assignments');

        $this->getJson('/api/curriculum?school_year_id='.$grade->school_year_id)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/curriculum?school_year_id='.$this->schoolYear('2026-2027', User::factory()->create())->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_year_id']);

        // The admin's organization payload counts the waiting requests.
        $this->postJson('/api/teaching-assignment-requests', ['section_id' => $second->id, 'subject_id' => $this->subject($grade, 'Math')->id])->assertCreated();
        $this->postJson('/api/students/'.$this->student(['section_id' => $first->id], $admin)->id.'/change-requests', ['first_name' => 'Changed'])->assertNotFound();

        $this->forgetGuards();
        $this->signIn($admin);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.organization.pending_teaching_assignment_requests_count', 1)
            ->assertJsonPath('data.organization.pending_student_change_requests_count', 0);

        $this->getJson('/api/admin/organization')
            ->assertOk()
            ->assertJsonPath('data.pending_teaching_assignment_requests_count', 1);
    }

    public function test_a_teachers_grade_level_detail_is_limited_to_their_sections(): void
    {
        [$ann, $bob, $grade, $math, $english, $pupilInFirst, $pupilInSecond] = $this->twoSectionsWithRecords();

        $this->signIn($ann);

        $this->getJson('/api/grade-levels/'.$grade->id)
            ->assertOk()
            ->assertJsonPath('data.id', $grade->id)
            ->assertJsonPath('data.school_year.id', $grade->school_year_id)
            ->assertJsonPath('data.students_count', 1)
            ->assertJsonPath('data.sections_count', 1)
            ->assertJsonPath('data.subjects_count', 2)
            // Every record of Ann's students, whoever teaches the subject.
            ->assertJsonPath('data.assignments_count', 2)
            ->assertJsonPath('data.tested_count', 1)
            ->assertJsonPath('data.issued_count', 1)
            ->assertJsonPath('data.assigned_count', 0)
            ->assertJsonCount(1, 'data.sections')
            ->assertJsonCount(2, 'data.subjects')
            ->assertJsonPath('data.subjects.0.name', 'Math')
            ->assertJsonPath('data.subjects.0.assignments_count', 1)
            ->assertJsonPath('data.subjects.0.tested_count', 1)
            ->assertJsonPath('data.subjects.1.assignments_count', 1)
            ->assertJsonPath('data.subjects.1.issued_count', 1)
            ->assertJsonCount(2, 'data.assignments')
            ->assertJsonStructure(['data' => ['assignments' => [
                '*' => ['id', 'school_year', 'quarter', 'student' => ['id', 'full_name'], 'subject' => ['id', 'name'], 'pace' => ['id', 'number', 'name']],
            ]]]);

        $this->forgetGuards();
        $this->signIn($bob);

        $this->getJson('/api/grade-levels/'.$grade->id)
            ->assertOk()
            ->assertJsonPath('data.students_count', 2)
            ->assertJsonPath('data.sections_count', 2)
            ->assertJsonPath('data.assignments_count', 3)
            ->assertJsonCount(2, 'data.sections');
    }

    public function test_a_subject_detail_includes_its_grade_level_teachers_and_records(): void
    {
        [$ann, $bob, $grade, $math, $english, $pupilInFirst, $pupilInSecond] = $this->twoSectionsWithRecords();

        $this->signIn($ann);

        $this->getJson('/api/subjects/'.$math->id)
            ->assertOk()
            ->assertJsonPath('data.id', $math->id)
            ->assertJsonPath('data.name', 'Math')
            ->assertJsonPath('data.grade_level.id', $grade->id)
            ->assertJsonPath('data.grade_level.school_year.id', $grade->school_year_id)
            ->assertJsonPath('data.grade_level.branch.id', $grade->branch_id)
            // Who teaches it in every section.
            ->assertJsonCount(2, 'data.teaching_assignments')
            ->assertJsonPath('data.teaching_assignments.0.teacher.name', $ann->name)
            ->assertJsonPath('data.teaching_assignments.1.teacher.name', $bob->name)
            // Only the records of Ann's students.
            ->assertJsonPath('data.assignments_count', 1)
            ->assertJsonPath('data.tested_count', 1)
            ->assertJsonCount(1, 'data.assignments')
            ->assertJsonPath('data.assignments.0.student.id', $pupilInFirst->id)
            ->assertJsonPath('data.assignments.0.subject_id', $math->id);
    }

    public function test_an_admin_opens_a_grade_level_subject_and_section_with_every_record(): void
    {
        [$ann, $bob, $grade, $math, $english, $pupilInFirst, $pupilInSecond] = $this->twoSectionsWithRecords();
        $admin = $this->admin($ann->organization);
        $first = $pupilInFirst->section;

        $this->signIn($admin);

        $this->getJson('/api/admin/grade-levels/'.$grade->id)
            ->assertOk()
            ->assertJsonPath('data.id', $grade->id)
            ->assertJsonPath('data.students_count', 2)
            ->assertJsonPath('data.sections_count', 2)
            ->assertJsonPath('data.assignments_count', 3)
            ->assertJsonPath('data.tested_count', 1)
            ->assertJsonPath('data.issued_count', 1)
            ->assertJsonPath('data.assigned_count', 1)
            ->assertJsonCount(2, 'data.sections')
            ->assertJsonCount(2, 'data.sections.0.teaching_assignments')
            ->assertJsonPath('data.sections.0.teaching_assignments.0.teacher.name', $ann->name)
            ->assertJsonCount(3, 'data.assignments');

        $this->getJson('/api/admin/subjects/'.$math->id)
            ->assertOk()
            ->assertJsonPath('data.id', $math->id)
            ->assertJsonPath('data.grade_level.id', $grade->id)
            ->assertJsonPath('data.assignments_count', 2)
            ->assertJsonCount(2, 'data.teaching_assignments')
            ->assertJsonCount(2, 'data.assignments');

        $this->getJson('/api/admin/sections/'.$first->id)
            ->assertOk()
            ->assertJsonPath('data.id', $first->id)
            ->assertJsonPath('data.students_count', 1)
            ->assertJsonPath('data.grade_level.school_year.name', $grade->schoolYear->name)
            ->assertJsonCount(2, 'data.grade_level.subjects')
            ->assertJsonCount(2, 'data.teaching_assignments')
            ->assertJsonCount(1, 'data.students')
            ->assertJsonPath('data.students.0.id', $pupilInFirst->id)
            ->assertJsonPath('data.students.0.assignments_count', 2);

        // Another school's grade level, subject and section are out of reach.
        $stranger = User::factory()->create();
        $foreignGrade = $this->gradeLevel('Grade 3', $stranger, ['Math']);
        $foreignSection = $this->section($foreignGrade, $stranger);

        $this->getJson('/api/admin/grade-levels/'.$foreignGrade->id)->assertNotFound();
        $this->getJson('/api/admin/subjects/'.$this->subject($foreignGrade, 'Math')->id)->assertNotFound();
        $this->getJson('/api/admin/sections/'.$foreignSection->id)->assertNotFound();

        // The platform staff see them all, or one organization at a time.
        $this->forgetGuards();
        $this->signIn($this->platformOwner());

        $this->getJson('/api/admin/grade-levels/'.$foreignGrade->id)->assertOk();
        $this->getJson('/api/admin/grade-levels/'.$foreignGrade->id.'?organization_id='.$ann->organization_id)->assertNotFound();
    }

    public function test_teachers_and_guests_cannot_manage_the_curriculum(): void
    {
        $this->getJson('/api/grade-levels')->assertUnauthorized();
        $this->getJson('/api/admin/grade-levels')->assertUnauthorized();
        $this->postJson('/api/admin/grade-levels', ['name' => 'Grade 1'])->assertUnauthorized();

        $this->signIn();

        $this->getJson('/api/admin/grade-levels')->assertForbidden();
        $this->postJson('/api/admin/grade-levels', ['name' => 'Grade 1'])->assertForbidden();
        $this->postJson('/api/admin/grade-levels/1/subjects', ['name' => 'Math'])->assertForbidden();
        $this->postJson('/api/admin/grade-levels/1/sections', ['name' => 'Section 1'])->assertForbidden();

        $this->forgetGuards();
        $this->signIn($this->admin());

        $this->getJson('/api/grade-levels')->assertForbidden();

        $this->assertDatabaseCount('grade_levels', 0);
    }

    /**
     * One grade level (Math, English) with two sections: Ann teaches Math in
     * the first; Bob teaches English in the first and Math in the second.
     * One pupil per section; the first pupil has a tested Math record and an
     * issued English record, the second an assigned-only Math record.
     *
     * @return array{0: \App\Models\User, 1: \App\Models\User, 2: \App\Models\GradeLevel, 3: \App\Models\Subject, 4: \App\Models\Subject, 5: \App\Models\Student, 6: \App\Models\Student}
     */
    private function twoSectionsWithRecords(): array
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $ann = $this->teacher($organization, ['name' => 'Ann']);
        $bob = $this->teacher($organization, ['name' => 'Bob']);

        $grade = $this->gradeLevel('Grade 1', $admin, ['Math', 'English']);
        $math = $this->subject($grade, 'Math');
        $english = $this->subject($grade, 'English');
        $first = $this->section($grade, $admin);
        $second = $this->emptySection($grade);
        $this->assign($ann, $first, $math);
        $this->assign($bob, $first, $english);
        $this->assign($bob, $second, $math);

        $pupilInFirst = $this->student(['section_id' => $first->id], $admin);
        $pupilInSecond = $this->student(['section_id' => $second->id], $admin);
        $pace = $this->pace(['number' => '1001'], $admin);

        PaceAssignment::factory()->tested()->create(['student_id' => $pupilInFirst->id, 'subject_id' => $math->id, 'pace_id' => $pace->id]);
        PaceAssignment::factory()->issued()->create(['student_id' => $pupilInFirst->id, 'subject_id' => $english->id, 'pace_id' => $pace->id]);
        PaceAssignment::factory()->create(['student_id' => $pupilInSecond->id, 'subject_id' => $math->id, 'pace_id' => $pace->id]);

        return [$ann, $bob, $grade, $math, $english, $pupilInFirst, $pupilInSecond];
    }
}
