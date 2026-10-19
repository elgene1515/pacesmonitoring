<?php

namespace Tests\Feature;

use App\Models\GradeLevel;
use App\Models\Pace;
use App\Models\PaceAssignment;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;

class AssignmentTest extends ApiTestCase
{
    private User $teacher;

    private GradeLevel $grade;

    private Student $student;

    private Subject $math;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();

        foreach (['2025-2026', '2026-2027', '2027-2028'] as $name) {
            $this->schoolYear($name, $this->teacher, ['is_current' => $name === '2026-2027']);
        }

        $this->grade = $this->gradeLevel('Grade 4', $this->teacher);
        $this->student = $this->student(['grade_level_id' => $this->grade->id], $this->teacher);
        $this->math = $this->subject($this->grade, 'Math');
    }

    public function test_a_user_can_assign_many_paces_to_a_student(): void
    {
        $this->signIn($this->teacher);
        $paces = Pace::factory()->count(3)->sequence(
            ['number' => '1040'],
            ['number' => '1041'],
            ['number' => '1042'],
        )->create([
            'created_by' => $this->teacher->id,
            // Issued on assignment, which takes one booklet from each PACE's stock.
            'stock' => 1,
        ]);

        $response = $this->postJson('/api/students/'.$this->student->id.'/assignments', [
            'subject_id' => $this->math->id,
            'pace_ids' => $paces->pluck('id')->all(),
            'school_year' => '2026-2027',
            'quarter' => 2,
            'issued_date' => '2026-09-01',
        ]);

        $response->assertCreated()->assertJsonCount(3, 'data');

        $this->assertSame(['1040', '1041', '1042'], array_column(array_column($response->json('data'), 'pace'), 'number'));

        $response->assertJsonPath('data.0.student_id', $this->student->id)
            ->assertJsonPath('data.0.subject_id', $this->math->id)
            ->assertJsonPath('data.0.subject.name', 'Math')
            ->assertJsonPath('data.0.school_year', '2026-2027')
            ->assertJsonPath('data.0.quarter', 2)
            ->assertJsonPath('data.0.issued_date', '2026-09-01')
            ->assertJsonPath('data.0.date_tested', null)
            ->assertJsonPath('data.0.score', null);

        $this->assertDatabaseCount('pace_assignments', 3);
        $this->assertSame(3, $this->student->assignments()->where('subject_id', $this->math->id)->count());
    }

    public function test_issued_date_is_optional_when_assigning(): void
    {
        $this->signIn($this->teacher);
        $pace = $this->pace([], $this->teacher);

        $this->postJson('/api/students/'.$this->student->id.'/assignments', [
            'subject_id' => $this->math->id,
            'pace_ids' => [$pace->id],
            'school_year' => '2026-2027',
            'quarter' => 1,
        ])
            ->assertCreated()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.issued_date', null);
    }

    public function test_assignment_payload_is_validated(): void
    {
        $this->signIn($this->teacher);

        $this->postJson('/api/students/'.$this->student->id.'/assignments', [
            'subject_id' => null,
            'pace_ids' => [],
            'school_year' => '2026',
            'quarter' => 5,
            'issued_date' => 'not-a-date',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject_id', 'pace_ids', 'school_year', 'quarter', 'issued_date']);

        $this->postJson('/api/students/'.$this->student->id.'/assignments', [
            'subject_id' => $this->math->id,
            'pace_ids' => [999999],
            'school_year' => '2026-2027',
            'quarter' => 1,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pace_ids.0']);

        $this->assertDatabaseCount('pace_assignments', 0);
    }

    public function test_an_unregistered_school_year_is_rejected(): void
    {
        $this->signIn($this->teacher);
        $pace = $this->pace([], $this->teacher);

        $this->postJson('/api/students/'.$this->student->id.'/assignments', [
            'subject_id' => $this->math->id,
            'pace_ids' => [$pace->id],
            'school_year' => '2030-2031',
            'quarter' => 1,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_year']);

        $assignment = $this->assignment(['student_id' => $this->student->id, 'subject_id' => $this->math->id], $this->teacher);

        $this->putJson('/api/assignments/'.$assignment->id, ['school_year' => '2030-2031'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_year']);

        $this->assertDatabaseCount('pace_assignments', 1);
    }

    public function test_a_subject_from_another_grade_level_is_rejected(): void
    {
        $this->signIn($this->teacher);
        $pace = $this->pace([], $this->teacher);
        $otherSubject = $this->subject($this->gradeLevel('Grade 9', $this->teacher), 'Math');

        $this->postJson('/api/students/'.$this->student->id.'/assignments', [
            'subject_id' => $otherSubject->id,
            'pace_ids' => [$pace->id],
            'school_year' => '2026-2027',
            'quarter' => 1,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject_id']);

        $this->assertDatabaseCount('pace_assignments', 0);
    }

    public function test_another_users_pace_cannot_be_assigned(): void
    {
        $this->signIn($this->teacher);
        $foreignPace = $this->pace([], User::factory()->create());

        $this->postJson('/api/students/'.$this->student->id.'/assignments', [
            'subject_id' => $this->math->id,
            'pace_ids' => [$foreignPace->id],
            'school_year' => '2026-2027',
            'quarter' => 1,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pace_ids.0']);

        $assignment = $this->assignment(['student_id' => $this->student->id, 'subject_id' => $this->math->id], $this->teacher);

        $this->putJson('/api/assignments/'.$assignment->id, ['pace_id' => $foreignPace->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pace_id']);
    }

    public function test_a_students_assignments_can_be_listed_and_filtered(): void
    {
        $this->signIn($this->teacher);
        $english = $this->subject($this->grade, 'English');

        PaceAssignment::factory()->count(2)->create([
            'student_id' => $this->student->id,
            'subject_id' => $this->math->id,
            'school_year' => '2026-2027',
            'quarter' => 1,
        ]);
        PaceAssignment::factory()->create([
            'student_id' => $this->student->id,
            'subject_id' => $english->id,
            'school_year' => '2026-2027',
            'quarter' => 1,
        ]);
        PaceAssignment::factory()->create([
            'student_id' => $this->student->id,
            'subject_id' => $this->math->id,
            'school_year' => '2025-2026',
            'quarter' => 3,
        ]);
        PaceAssignment::factory()->create();

        $base = '/api/students/'.$this->student->id.'/assignments';

        $this->getJson($base)->assertOk()->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.school_year', '2026-2027')
            ->assertJsonPath('data.3.school_year', '2025-2026')
            ->assertJsonStructure(['data' => [['pace' => ['id', 'number', 'name'], 'subject' => ['id', 'name']]]]);

        $this->getJson($base.'?school_year=2026-2027&quarter=1')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson($base.'?school_year=2026-2027&quarter=1&subject_id='.$this->math->id)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson($base.'?school_year=2026-2027&quarter=2')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($base.'?subject_id='.$english->id)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_user_can_record_a_score_and_test_date(): void
    {
        $this->signIn($this->teacher);
        $assignment = PaceAssignment::factory()->create([
            'student_id' => $this->student->id,
            'subject_id' => $this->math->id,
            'issued_date' => '2026-09-01',
        ]);

        $this->putJson('/api/assignments/'.$assignment->id, [
            'date_tested' => '2026-09-15',
            'score' => 92.5,
            'remarks' => 'Excellent',
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $assignment->id)
            ->assertJsonPath('data.issued_date', '2026-09-01')
            ->assertJsonPath('data.date_tested', '2026-09-15')
            ->assertJsonPath('data.score', 92.5)
            ->assertJsonPath('data.remarks', 'Excellent')
            ->assertJsonStructure(['data' => ['pace' => ['id', 'number'], 'subject' => ['id', 'name']]]);

        $this->assertDatabaseHas('pace_assignments', [
            'id' => $assignment->id,
            'date_tested' => '2026-09-15 00:00:00',
            'score' => 92.5,
            'remarks' => 'Excellent',
        ]);
    }

    public function test_a_user_can_flag_an_assignment_as_a_retake(): void
    {
        $this->signIn($this->teacher);
        $assignment = PaceAssignment::factory()->create([
            'student_id' => $this->student->id,
            'subject_id' => $this->math->id,
        ]);

        $this->getJson('/api/students/'.$this->student->id)
            ->assertOk()
            ->assertJsonPath('data.assignments.0.retake', false);

        $this->patchJson('/api/assignments/'.$assignment->id, ['retake' => true])
            ->assertOk()
            ->assertJsonPath('data.retake', true);

        $this->assertDatabaseHas('pace_assignments', ['id' => $assignment->id, 'retake' => 1]);

        $this->patchJson('/api/assignments/'.$assignment->id, ['retake' => false])
            ->assertOk()
            ->assertJsonPath('data.retake', false);

        $this->patchJson('/api/assignments/'.$assignment->id, ['retake' => 'maybe'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['retake']);
    }

    public function test_a_user_can_change_the_pace_and_clear_test_results(): void
    {
        $this->signIn($this->teacher);
        $assignment = PaceAssignment::factory()->tested()->create([
            'student_id' => $this->student->id,
            'subject_id' => $this->math->id,
        ]);
        // The record stays issued, so the new PACE needs a booklet in stock.
        $newPace = $this->pace(['stock' => 1], $this->teacher);

        $this->putJson('/api/assignments/'.$assignment->id, [
            'pace_id' => $newPace->id,
            'quarter' => 4,
            'school_year' => '2026-2027',
            'date_tested' => null,
            'score' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.pace_id', $newPace->id)
            ->assertJsonPath('data.pace.id', $newPace->id)
            ->assertJsonPath('data.quarter', 4)
            ->assertJsonPath('data.school_year', '2026-2027')
            ->assertJsonPath('data.date_tested', null)
            ->assertJsonPath('data.score', null);
    }

    public function test_an_assignments_school_year_must_match_the_subjects_grade_level(): void
    {
        $this->signIn($this->teacher);
        $pace = $this->pace([], $this->teacher);

        // The subject's grade level is registered in 2026-2027: filing the record
        // under another school-year label is rejected ...
        $this->postJson('/api/students/'.$this->student->id.'/assignments', [
            'subject_id' => $this->math->id,
            'pace_ids' => [$pace->id],
            'school_year' => '2027-2028',
            'quarter' => 1,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_year']);

        $this->assertDatabaseCount('pace_assignments', 0);

        // ... but a PACE itself is not tied to any school year, so the same PACE may be
        // assigned under any school year.
        $assignment = $this->assignment([
            'student_id' => $this->student->id,
            'subject_id' => $this->math->id,
            'pace_id' => $pace->id,
            'school_year' => '2026-2027',
        ], $this->teacher);

        $this->putJson('/api/assignments/'.$assignment->id, ['school_year' => '2027-2028'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_year']);

        // Recording a score does not re-check the school year.
        $this->putJson('/api/assignments/'.$assignment->id, ['score' => 90])->assertOk();
    }

    public function test_date_tested_cannot_precede_the_issued_date(): void
    {
        $this->signIn($this->teacher);
        $assignment = PaceAssignment::factory()->create([
            'student_id' => $this->student->id,
            'subject_id' => $this->math->id,
            'issued_date' => '2026-09-10',
        ]);

        // Only date_tested sent: compared against the stored issued_date.
        $this->putJson('/api/assignments/'.$assignment->id, ['date_tested' => '2026-09-05'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_tested']);

        // Both sent in the same payload.
        $this->putJson('/api/assignments/'.$assignment->id, [
            'issued_date' => '2026-10-01',
            'date_tested' => '2026-09-30',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_tested']);

        // Same day is allowed.
        $this->putJson('/api/assignments/'.$assignment->id, ['date_tested' => '2026-09-10'])
            ->assertOk()
            ->assertJsonPath('data.date_tested', '2026-09-10');
    }

    public function test_update_payload_is_validated(): void
    {
        $this->signIn($this->teacher);
        $assignment = PaceAssignment::factory()->create([
            'student_id' => $this->student->id,
            'subject_id' => $this->math->id,
        ]);
        $otherSubject = $this->gradeLevel('Grade 10', $this->teacher)->subjects()->firstOrFail();

        $this->putJson('/api/assignments/'.$assignment->id, [
            'score' => 101,
            'quarter' => 0,
            'school_year' => '26-27',
            'pace_id' => 999999,
            'subject_id' => $otherSubject->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['score', 'quarter', 'school_year', 'pace_id', 'subject_id']);
    }

    public function test_a_user_can_delete_an_assignment(): void
    {
        $this->signIn($this->teacher);
        $assignment = PaceAssignment::factory()->create([
            'student_id' => $this->student->id,
            'subject_id' => $this->math->id,
        ]);

        $this->deleteJson('/api/assignments/'.$assignment->id)->assertNoContent();

        $this->assertDatabaseMissing('pace_assignments', ['id' => $assignment->id]);
        $this->assertDatabaseHas('students', ['id' => $this->student->id]);
        $this->assertDatabaseHas('paces', ['id' => $assignment->pace_id]);
    }

    public function test_another_users_assignments_are_not_reachable(): void
    {
        $this->signIn($this->teacher);
        $stranger = User::factory()->create();
        $foreign = $this->assignment([], $stranger);

        $this->getJson('/api/students/'.$foreign->student_id.'/assignments')->assertNotFound();
        $this->postJson('/api/students/'.$foreign->student_id.'/assignments', [
            'subject_id' => $foreign->subject_id,
            'pace_ids' => [$this->pace([], $this->teacher)->id],
            'school_year' => '2026-2027',
            'quarter' => 1,
        ])->assertNotFound();
        $this->putJson('/api/assignments/'.$foreign->id, ['score' => 50])->assertNotFound();
        $this->deleteJson('/api/assignments/'.$foreign->id)->assertNotFound();

        $this->assertDatabaseHas('pace_assignments', ['id' => $foreign->id, 'score' => null]);
    }

    public function test_guests_cannot_touch_assignments(): void
    {
        $assignment = PaceAssignment::factory()->create(['student_id' => $this->student->id]);
        $pace = $this->pace([], $this->teacher);

        $this->getJson('/api/students/'.$this->student->id.'/assignments')->assertUnauthorized();
        $this->postJson('/api/students/'.$this->student->id.'/assignments', [
            'subject_id' => $this->math->id,
            'pace_ids' => [$pace->id],
            'school_year' => '2026-2027',
            'quarter' => 1,
        ])->assertUnauthorized();
        $this->putJson('/api/assignments/'.$assignment->id, ['score' => 50])->assertUnauthorized();
        $this->deleteJson('/api/assignments/'.$assignment->id)->assertUnauthorized();

        $this->assertDatabaseCount('pace_assignments', 1);
        $this->assertDatabaseHas('pace_assignments', ['id' => $assignment->id, 'score' => null]);
    }
}
