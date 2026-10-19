<?php

namespace Tests\Feature;

use App\Models\PaceAssignment;
use App\Models\User;

class GradeLevelTest extends ApiTestCase
{
    public function test_a_user_can_create_grade_levels_and_subjects_under_a_school_year(): void
    {
        $user = $this->signIn();
        $year = $this->schoolYear('2026-2027');

        $first = $this->postJson('/api/grade-levels', ['name' => 'Grade 1', 'school_year_id' => $year->id])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Grade 1')
            ->assertJsonPath('data.sort_order', 0)
            ->assertJsonPath('data.school_year_id', $year->id)
            ->assertJsonPath('data.school_year.name', '2026-2027')
            ->assertJsonPath('data.students_count', 0)
            ->assertJsonPath('data.subjects_count', 0)
            ->assertJsonPath('data.subjects', []);

        $this->postJson('/api/grade-levels', ['name' => 'Grade 2', 'school_year_id' => $year->id])
            ->assertCreated()
            ->assertJsonPath('data.sort_order', 1);

        $gradeLevelId = $first->json('data.id');

        $this->assertDatabaseHas('grade_levels', ['id' => $gradeLevelId, 'user_id' => $user->id, 'school_year_id' => $year->id]);

        $this->postJson('/api/subjects', ['grade_level_id' => $gradeLevelId, 'name' => 'Math'])
            ->assertCreated()
            ->assertJsonPath('data.grade_level_id', $gradeLevelId)
            ->assertJsonPath('data.name', 'Math')
            ->assertJsonPath('data.sort_order', 0)
            ->assertJsonPath('data.assignments_count', 0);

        $this->postJson('/api/subjects', ['grade_level_id' => $gradeLevelId, 'name' => 'English'])
            ->assertCreated()
            ->assertJsonPath('data.sort_order', 1);

        $this->getJson('/api/grade-levels')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Grade 1')
            ->assertJsonPath('data.0.subjects_count', 2)
            ->assertJsonCount(2, 'data.0.subjects')
            ->assertJsonPath('data.0.subjects.0.name', 'Math')
            ->assertJsonPath('data.0.subjects.1.name', 'English')
            ->assertJsonPath('data.1.name', 'Grade 2')
            ->assertJsonCount(0, 'data.1.subjects');

        $this->getJson('/api/subjects?grade_level_id='.$gradeLevelId)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Math');
    }

    public function test_subjects_report_the_assigned_issued_and_tested_breakdown(): void
    {
        $this->signIn();
        $grade = $this->gradeLevel('Grade 1', null, ['Math']);
        $math = $this->subject($grade, 'Math');
        $student = $this->student(['grade_level_id' => $grade->id]);

        // One of each stage: assigned-only, issued-only, and two tested.
        $this->assignment(['student_id' => $student->id, 'subject_id' => $math->id]);
        PaceAssignment::factory()->issued()->create(['student_id' => $student->id, 'subject_id' => $math->id]);
        PaceAssignment::factory()->tested()->create(['student_id' => $student->id, 'subject_id' => $math->id]);
        PaceAssignment::factory()->tested()->create(['student_id' => $student->id, 'subject_id' => $math->id]);

        $this->getJson('/api/grade-levels')
            ->assertOk()
            ->assertJsonPath('data.0.subjects.0.assignments_count', 4)
            ->assertJsonPath('data.0.subjects.0.tested_count', 2)
            ->assertJsonPath('data.0.subjects.0.issued_count', 1)
            ->assertJsonPath('data.0.subjects.0.assigned_count', 1);
    }

    public function test_a_grade_level_requires_one_of_the_users_school_years(): void
    {
        $this->signIn();

        $this->postJson('/api/grade-levels', ['name' => 'Grade 1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_year_id']);

        $foreign = $this->schoolYear('2026-2027', User::factory()->create());

        $this->postJson('/api/grade-levels', ['name' => 'Grade 1', 'school_year_id' => $foreign->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_year_id']);

        $this->assertDatabaseCount('grade_levels', 0);
    }

    public function test_each_school_year_has_its_own_set_of_grade_levels(): void
    {
        $this->signIn();
        $old = $this->schoolYear('2025-2026', null, ['is_current' => false]);
        $current = $this->schoolYear('2026-2027', null, ['is_current' => true]);

        $this->gradeLevel('Grade 1', null, ['Math', 'English'], $old);
        $this->gradeLevel('Grade 2', null, ['Math'], $old);
        $this->gradeLevel('Grade 1', null, ['Math', 'Science', 'Bible'], $current);

        // All school years, newest first.
        $all = $this->getJson('/api/grade-levels')->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame(['2026-2027', '2025-2026', '2025-2026'], array_column(array_column($all->json('data'), 'school_year'), 'name'));
        $this->assertSame(['Grade 1', 'Grade 1', 'Grade 2'], array_column($all->json('data'), 'name'));

        // One school year only.
        $this->getJson('/api/grade-levels?school_year_id='.$current->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Grade 1')
            ->assertJsonCount(3, 'data.0.subjects');

        $this->getJson('/api/grade-levels?school_year_id='.$old->id)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonCount(2, 'data.0.subjects');

        $foreign = $this->schoolYear('2026-2027', User::factory()->create());

        $this->getJson('/api/grade-levels?school_year_id='.$foreign->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_year_id']);
    }

    public function test_an_explicit_sort_order_is_respected(): void
    {
        $this->signIn();
        $year = $this->schoolYear('2026-2027');

        $this->postJson('/api/grade-levels', ['name' => 'Kindergarten', 'sort_order' => 0, 'school_year_id' => $year->id])->assertCreated();
        $this->postJson('/api/grade-levels', ['name' => 'Grade 1', 'sort_order' => 1, 'school_year_id' => $year->id])->assertCreated();
        $this->postJson('/api/grade-levels', ['name' => 'Nursery', 'sort_order' => 0, 'school_year_id' => $year->id])->assertCreated();

        $response = $this->getJson('/api/grade-levels')->assertOk();

        $this->assertSame(['Kindergarten', 'Nursery', 'Grade 1'], array_column($response->json('data'), 'name'));
    }

    public function test_grade_level_names_are_unique_per_user_within_a_school_year(): void
    {
        $this->signIn();
        $current = $this->schoolYear('2026-2027');
        $this->gradeLevel('Grade 1');

        $this->postJson('/api/grade-levels', ['name' => 'Grade 1', 'school_year_id' => $current->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        // The same name is fine in another school year ...
        $next = $this->schoolYear('2027-2028', null, ['is_current' => false]);

        $this->postJson('/api/grade-levels', ['name' => 'Grade 1', 'school_year_id' => $next->id])->assertCreated();

        // ... and for another user.
        $strangerYear = $this->schoolYear('2026-2027', User::factory()->create());
        $this->gradeLevel('Grade 7', $strangerYear->user, [], $strangerYear);

        $this->postJson('/api/grade-levels', ['name' => 'Grade 7', 'school_year_id' => $current->id])->assertCreated();
    }

    public function test_subject_names_are_unique_within_a_grade_level(): void
    {
        $this->signIn();
        $grade1 = $this->gradeLevel('Grade 1');
        $grade2 = $this->gradeLevel('Grade 2', null, []);

        $this->postJson('/api/subjects', ['grade_level_id' => $grade1->id, 'name' => 'Math'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->postJson('/api/subjects', ['grade_level_id' => $grade2->id, 'name' => 'Math'])
            ->assertCreated();
    }

    public function test_grade_level_and_subject_payloads_are_validated(): void
    {
        $this->signIn();
        $year = $this->schoolYear('2026-2027');

        $this->postJson('/api/grade-levels', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'school_year_id']);

        $this->postJson('/api/grade-levels', ['name' => str_repeat('x', 101), 'sort_order' => -1, 'school_year_id' => $year->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'sort_order']);

        $this->postJson('/api/subjects', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['grade_level_id', 'name']);

        $foreignGrade = $this->gradeLevel('Grade 1', User::factory()->create());

        $this->postJson('/api/subjects', ['grade_level_id' => $foreignGrade->id, 'name' => 'Art'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['grade_level_id']);

        $this->assertDatabaseCount('grade_levels', 1);
        $this->assertDatabaseMissing('subjects', ['name' => 'Art']);
    }

    public function test_a_user_can_rename_and_reorder_grade_levels_and_subjects(): void
    {
        $this->signIn();
        $grade = $this->gradeLevel('Grade 1');
        $math = $this->subject($grade, 'Math');
        $other = $this->schoolYear('2027-2028', null, ['is_current' => false]);

        $this->putJson('/api/grade-levels/'.$grade->id, ['name' => 'First Grade', 'sort_order' => 5, 'school_year_id' => $other->id])
            ->assertOk()
            ->assertJsonPath('data.name', 'First Grade')
            ->assertJsonPath('data.sort_order', 5)
            ->assertJsonPath('data.school_year_id', $grade->school_year_id)
            ->assertJsonCount(7, 'data.subjects');

        // Keeping the same name is allowed; sort order may be omitted.
        $this->putJson('/api/grade-levels/'.$grade->id, ['name' => 'First Grade'])
            ->assertOk()
            ->assertJsonPath('data.sort_order', 5);

        $this->putJson('/api/subjects/'.$math->id, ['name' => 'Mathematics', 'sort_order' => 9])
            ->assertOk()
            ->assertJsonPath('data.name', 'Mathematics')
            ->assertJsonPath('data.sort_order', 9);

        // Renaming to an existing sibling's name is rejected.
        $this->putJson('/api/subjects/'.$math->id, ['name' => 'English'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_a_grade_level_with_students_cannot_be_deleted(): void
    {
        $this->signIn();
        $grade = $this->gradeLevel('Grade 1');
        $this->student(['grade_level_id' => $grade->id]);

        $this->deleteJson('/api/grade-levels/'.$grade->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['grade_level']);

        $this->assertDatabaseHas('grade_levels', ['id' => $grade->id]);
    }

    public function test_deleting_a_grade_level_removes_its_subjects(): void
    {
        $this->signIn();
        $grade = $this->gradeLevel('Grade 1');

        $this->deleteJson('/api/grade-levels/'.$grade->id)->assertNoContent();

        $this->assertDatabaseMissing('grade_levels', ['id' => $grade->id]);
        $this->assertDatabaseMissing('subjects', ['grade_level_id' => $grade->id]);
    }

    public function test_deleting_a_subject_removes_its_assignments(): void
    {
        $this->signIn();
        $grade = $this->gradeLevel('Grade 1');
        $math = $this->subject($grade, 'Math');
        $student = $this->student(['grade_level_id' => $grade->id]);

        $this->assignment(['student_id' => $student->id, 'subject_id' => $math->id]);

        $this->deleteJson('/api/subjects/'.$math->id)->assertNoContent();

        $this->assertDatabaseMissing('subjects', ['id' => $math->id]);
        $this->assertSame(0, PaceAssignment::query()->count());
        $this->assertDatabaseHas('students', ['id' => $student->id]);
    }

    public function test_a_grade_level_detail_includes_its_subjects_and_pace_records(): void
    {
        $this->signIn();
        $grade = $this->gradeLevel('Grade 1', null, ['Math', 'English']);
        $other = $this->gradeLevel('Grade 2', null, ['Math']);
        $math = $this->subject($grade, 'Math');
        $english = $this->subject($grade, 'English');
        $student = $this->student(['grade_level_id' => $grade->id]);
        $pace = $this->pace(['number' => '1001']);

        PaceAssignment::factory()->tested()->create([
            'student_id' => $student->id,
            'subject_id' => $math->id,
            'pace_id' => $pace->id,
        ]);
        PaceAssignment::factory()->issued()->create(['student_id' => $student->id, 'subject_id' => $english->id]);
        // A record under another grade level's subject must not leak in.
        $this->assignment([
            'student_id' => $this->student(['grade_level_id' => $other->id])->id,
            'subject_id' => $this->subject($other, 'Math')->id,
        ]);

        $this->getJson('/api/grade-levels/'.$grade->id)
            ->assertOk()
            ->assertJsonPath('data.id', $grade->id)
            ->assertJsonPath('data.school_year.id', $grade->school_year_id)
            ->assertJsonPath('data.students_count', 1)
            ->assertJsonPath('data.subjects_count', 2)
            ->assertJsonPath('data.assignments_count', 2)
            ->assertJsonPath('data.tested_count', 1)
            ->assertJsonPath('data.issued_count', 1)
            ->assertJsonPath('data.assigned_count', 0)
            ->assertJsonCount(2, 'data.subjects')
            ->assertJsonPath('data.subjects.0.name', 'Math')
            ->assertJsonPath('data.subjects.0.assignments_count', 1)
            ->assertJsonPath('data.subjects.0.tested_count', 1)
            ->assertJsonCount(2, 'data.assignments')
            ->assertJsonStructure(['data' => ['assignments' => [
                '*' => ['id', 'school_year', 'quarter', 'student' => ['id', 'full_name'], 'subject' => ['id', 'name'], 'pace' => ['id', 'number', 'name']],
            ]]]);

        $stranger = User::factory()->create();

        $this->getJson('/api/grade-levels/'.$this->gradeLevel('Grade 1', $stranger)->id)->assertNotFound();
    }

    public function test_a_subject_detail_includes_its_grade_level_and_pace_records(): void
    {
        $this->signIn();
        $grade = $this->gradeLevel('Grade 1', null, ['Math', 'English']);
        $math = $this->subject($grade, 'Math');
        $english = $this->subject($grade, 'English');
        $student = $this->student(['first_name' => 'Ana', 'last_name' => 'Cruz', 'grade_level_id' => $grade->id]);
        $pace = $this->pace(['number' => '1001']);

        PaceAssignment::factory()->tested()->create([
            'student_id' => $student->id,
            'subject_id' => $math->id,
            'pace_id' => $pace->id,
        ]);
        $this->assignment(['student_id' => $student->id, 'subject_id' => $math->id]);
        // A record under another subject must not leak into Math's detail.
        $this->assignment(['student_id' => $student->id, 'subject_id' => $english->id]);

        $this->getJson('/api/subjects/'.$math->id)
            ->assertOk()
            ->assertJsonPath('data.id', $math->id)
            ->assertJsonPath('data.name', 'Math')
            ->assertJsonPath('data.grade_level.id', $grade->id)
            ->assertJsonPath('data.grade_level.school_year.id', $grade->school_year_id)
            ->assertJsonPath('data.assignments_count', 2)
            ->assertJsonPath('data.tested_count', 1)
            ->assertJsonPath('data.issued_count', 0)
            ->assertJsonPath('data.assigned_count', 1)
            ->assertJsonCount(2, 'data.assignments')
            ->assertJsonStructure(['data' => ['assignments' => [
                '*' => ['id', 'school_year', 'quarter', 'student' => ['id', 'full_name'], 'pace' => ['id', 'number', 'name']],
            ]]])
            ->assertJsonPath('data.assignments.0.student.id', $student->id)
            ->assertJsonPath('data.assignments.0.subject_id', $math->id)
            ->assertJsonPath('data.assignments.1.subject_id', $math->id);

        $other = User::factory()->create();
        $theirs = $this->gradeLevel('Grade 1', $other, ['Math']);

        $this->getJson('/api/subjects/'.$this->subject($theirs, 'Math')->id)->assertNotFound();
    }

    public function test_a_user_only_sees_their_own_grade_levels_and_subjects(): void
    {
        $this->signIn();
        $mine = $this->gradeLevel('Grade 1');

        $stranger = User::factory()->create();
        $theirs = $this->gradeLevel('Grade 1', $stranger);
        $theirMath = $this->subject($theirs, 'Math');

        $this->getJson('/api/grade-levels')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);

        $this->getJson('/api/subjects')
            ->assertOk()
            ->assertJsonCount(7, 'data')
            ->assertJsonPath('data.0.grade_level_id', $mine->id);

        $this->getJson('/api/subjects?grade_level_id='.$theirs->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['grade_level_id']);

        $this->putJson('/api/grade-levels/'.$theirs->id, ['name' => 'Hijacked'])->assertNotFound();
        $this->deleteJson('/api/grade-levels/'.$theirs->id)->assertNotFound();
        $this->putJson('/api/subjects/'.$theirMath->id, ['name' => 'Hijacked'])->assertNotFound();
        $this->deleteJson('/api/subjects/'.$theirMath->id)->assertNotFound();

        $this->assertDatabaseHas('grade_levels', ['id' => $theirs->id, 'name' => 'Grade 1']);
        $this->assertDatabaseHas('subjects', ['id' => $theirMath->id, 'name' => 'Math']);
    }

    public function test_guests_cannot_manage_grade_levels(): void
    {
        $this->getJson('/api/grade-levels')->assertUnauthorized();
        $this->postJson('/api/grade-levels', ['name' => 'Grade 1', 'school_year_id' => 1])->assertUnauthorized();
        $this->postJson('/api/subjects', ['grade_level_id' => 1, 'name' => 'Math'])->assertUnauthorized();

        $this->assertDatabaseCount('grade_levels', 0);
        $this->assertDatabaseCount('subjects', 0);
    }
}
