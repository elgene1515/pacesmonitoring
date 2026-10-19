<?php

namespace Tests\Feature;

use App\Models\GradeLevel;
use App\Models\PaceAssignment;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;

class PaceStockTest extends ApiTestCase
{
    private User $teacher;

    private GradeLevel $grade;

    private Student $student;

    private Subject $math;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->schoolYear('2026-2027', $this->teacher, ['is_current' => true]);
        $this->grade = $this->gradeLevel('Grade 4', $this->teacher);
        $this->student = $this->student(['grade_level_id' => $this->grade->id], $this->teacher);
        $this->math = $this->subject($this->grade, 'Math');
    }

    public function test_a_pace_is_registered_with_a_stock_count(): void
    {
        $this->signIn($this->teacher);

        $this->postJson('/api/paces', ['name' => 'Math', 'number' => '1001', 'stock' => 12])
            ->assertCreated()
            ->assertJsonPath('data.stock', 12)
            ->assertJsonPath('data.pending_requests_count', 0);

        // Stock is optional and starts at zero.
        $this->postJson('/api/paces', ['name' => 'Math', 'number' => '1002'])
            ->assertCreated()
            ->assertJsonPath('data.stock', 0);

        $this->postJson('/api/paces', ['name' => 'Math', 'number' => '1003', 'stock' => -1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['stock']);

        // A range shares one starting stock.
        $this->postJson('/api/paces/range', ['name' => 'Science', 'start' => '2001', 'end' => '2003', 'stock' => 4])
            ->assertCreated()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.stock', 4)
            ->assertJsonPath('data.2.stock', 4);

        $this->assertDatabaseHas('paces', ['number' => '1001', 'stock' => 12]);
        $this->assertDatabaseHas('paces', ['number' => '2002', 'stock' => 4]);
    }

    public function test_a_teacher_can_edit_and_adjust_the_stock(): void
    {
        $this->signIn($this->teacher);
        $pace = $this->pace(['number' => '1001', 'name' => 'Math', 'stock' => 2], $this->teacher);

        $this->putJson('/api/paces/'.$pace->id, ['name' => 'Math', 'number' => '1001', 'stock' => 10])
            ->assertOk()
            ->assertJsonPath('data.stock', 10);

        // An update without a stock keeps the current count.
        $this->putJson('/api/paces/'.$pace->id, ['name' => 'Math 1001', 'number' => '1001'])
            ->assertOk()
            ->assertJsonPath('data.stock', 10);

        $this->patchJson('/api/paces/'.$pace->id.'/stock', ['delta' => -3])
            ->assertOk()
            ->assertJsonPath('data.stock', 7)
            ->assertJsonPath('data.assignments_count', 0);

        $this->patchJson('/api/paces/'.$pace->id.'/stock', ['delta' => 5])
            ->assertOk()
            ->assertJsonPath('data.stock', 12);

        // The count never drops below zero, and a zero change is meaningless.
        $this->patchJson('/api/paces/'.$pace->id.'/stock', ['delta' => -13])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['stock']);

        $this->patchJson('/api/paces/'.$pace->id.'/stock', ['delta' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['delta']);

        $this->assertDatabaseHas('paces', ['id' => $pace->id, 'stock' => 12]);

        $theirs = $this->pace(['stock' => 5], User::factory()->create());
        $this->patchJson('/api/paces/'.$theirs->id.'/stock', ['delta' => 1])->assertNotFound();
        $this->assertDatabaseHas('paces', ['id' => $theirs->id, 'stock' => 5]);
    }

    public function test_issuing_paces_takes_them_from_stock(): void
    {
        $this->signIn($this->teacher);
        $first = $this->pace(['number' => '1040', 'stock' => 2], $this->teacher);
        $second = $this->pace(['number' => '1041', 'stock' => 1], $this->teacher);

        $this->postJson('/api/students/'.$this->student->id.'/assignments', [
            'subject_id' => $this->math->id,
            'pace_ids' => [$first->id, $second->id],
            'school_year' => '2026-2027',
            'quarter' => 1,
            'issued_date' => '2026-09-01',
        ])
            ->assertCreated()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.pace.stock', 1)
            ->assertJsonPath('data.1.pace.stock', 0);

        $this->assertDatabaseHas('paces', ['id' => $first->id, 'stock' => 1]);
        $this->assertDatabaseHas('paces', ['id' => $second->id, 'stock' => 0]);
    }

    public function test_assigning_without_an_issued_date_leaves_stock_alone(): void
    {
        $this->signIn($this->teacher);
        $pace = $this->pace(['number' => '1040', 'stock' => 0], $this->teacher);

        $this->postJson('/api/students/'.$this->student->id.'/assignments', [
            'subject_id' => $this->math->id,
            'pace_ids' => [$pace->id],
            'school_year' => '2026-2027',
            'quarter' => 1,
        ])
            ->assertCreated()
            ->assertJsonPath('data.0.issued_date', null);

        $this->assertDatabaseHas('paces', ['id' => $pace->id, 'stock' => 0]);
    }

    public function test_a_pace_that_is_out_of_stock_cannot_be_issued(): void
    {
        $this->signIn($this->teacher);
        $inStock = $this->pace(['number' => '1040', 'name' => 'Math', 'stock' => 1], $this->teacher);
        $outOfStock = $this->pace(['number' => '1041', 'name' => 'Math', 'stock' => 0], $this->teacher);

        // All or nothing: the in-stock PACE is not issued either.
        $this->postJson('/api/students/'.$this->student->id.'/assignments', [
            'subject_id' => $this->math->id,
            'pace_ids' => [$inStock->id, $outOfStock->id],
            'school_year' => '2026-2027',
            'quarter' => 1,
            'issued_date' => '2026-09-01',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['issued_date'])
            ->assertJsonPath('errors.issued_date.0', 'PACE #1041 Math is out of stock. Assign it without an issued date, or add stock first.');

        $this->assertDatabaseCount('pace_assignments', 0);
        $this->assertDatabaseHas('paces', ['id' => $inStock->id, 'stock' => 1]);

        // Issuing the same PACE to two records at once needs two booklets.
        $other = $this->student(['grade_level_id' => $this->grade->id], $this->teacher);

        $this->postJson('/api/students/'.$this->student->id.'/assignments', [
            'subject_id' => $this->math->id,
            'pace_ids' => [$inStock->id],
            'school_year' => '2026-2027',
            'quarter' => 1,
            'issued_date' => '2026-09-01',
        ])->assertCreated();

        $this->postJson('/api/students/'.$other->id.'/assignments', [
            'subject_id' => $this->math->id,
            'pace_ids' => [$inStock->id],
            'school_year' => '2026-2027',
            'quarter' => 1,
            'issued_date' => '2026-09-01',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['issued_date']);

        $this->assertDatabaseCount('pace_assignments', 1);
    }

    public function test_recording_an_issued_date_later_takes_one_from_stock_and_clearing_it_gives_it_back(): void
    {
        $this->signIn($this->teacher);
        $pace = $this->pace(['number' => '1040', 'stock' => 1], $this->teacher);

        $first = $this->assignment(['student_id' => $this->student->id, 'subject_id' => $this->math->id, 'pace_id' => $pace->id], $this->teacher);
        $second = $this->assignment(['student_id' => $this->student->id, 'subject_id' => $this->math->id, 'pace_id' => $pace->id], $this->teacher);

        $this->putJson('/api/assignments/'.$first->id, ['issued_date' => '2026-09-01'])
            ->assertOk()
            ->assertJsonPath('data.issued_date', '2026-09-01')
            ->assertJsonPath('data.pace.stock', 0);

        $this->putJson('/api/assignments/'.$second->id, ['issued_date' => '2026-09-02'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['issued_date']);

        $this->assertDatabaseHas('pace_assignments', ['id' => $second->id, 'issued_date' => null]);

        // Scores and dates on an issued record do not touch the stock again.
        $this->putJson('/api/assignments/'.$first->id, ['date_tested' => '2026-09-15', 'score' => 90])
            ->assertOk()
            ->assertJsonPath('data.pace.stock', 0);

        $this->putJson('/api/assignments/'.$first->id, ['issued_date' => null, 'date_tested' => null, 'score' => null])
            ->assertOk()
            ->assertJsonPath('data.pace.stock', 1);

        $this->putJson('/api/assignments/'.$second->id, ['issued_date' => '2026-09-02'])
            ->assertOk()
            ->assertJsonPath('data.pace.stock', 0);
    }

    public function test_moving_an_issued_record_to_another_pace_moves_the_booklet(): void
    {
        $this->signIn($this->teacher);
        $from = $this->pace(['number' => '1040', 'name' => 'Math', 'stock' => 0], $this->teacher);
        $to = $this->pace(['number' => '1041', 'name' => 'Math', 'stock' => 1], $this->teacher);
        $empty = $this->pace(['number' => '1042', 'name' => 'Math', 'stock' => 0], $this->teacher);

        $assignment = PaceAssignment::factory()->issued()->create([
            'student_id' => $this->student->id,
            'subject_id' => $this->math->id,
            'pace_id' => $from->id,
        ]);

        $this->putJson('/api/assignments/'.$assignment->id, ['pace_id' => $to->id])
            ->assertOk()
            ->assertJsonPath('data.pace_id', $to->id);

        $this->assertDatabaseHas('paces', ['id' => $from->id, 'stock' => 1]);
        $this->assertDatabaseHas('paces', ['id' => $to->id, 'stock' => 0]);

        // A PACE without stock cannot take over an issued record.
        $this->putJson('/api/assignments/'.$assignment->id, ['pace_id' => $empty->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['issued_date']);

        $this->assertDatabaseHas('pace_assignments', ['id' => $assignment->id, 'pace_id' => $to->id]);
        $this->assertDatabaseHas('paces', ['id' => $to->id, 'stock' => 0]);
        $this->assertDatabaseHas('paces', ['id' => $empty->id, 'stock' => 0]);
    }

    public function test_deleting_an_issued_record_returns_the_booklet(): void
    {
        $this->signIn($this->teacher);
        $pace = $this->pace(['number' => '1040', 'stock' => 0], $this->teacher);

        $issued = PaceAssignment::factory()->issued()->create([
            'student_id' => $this->student->id,
            'subject_id' => $this->math->id,
            'pace_id' => $pace->id,
        ]);
        $assignedOnly = PaceAssignment::factory()->create([
            'student_id' => $this->student->id,
            'subject_id' => $this->math->id,
            'pace_id' => $pace->id,
        ]);

        $this->deleteJson('/api/assignments/'.$assignedOnly->id)->assertNoContent();
        $this->assertDatabaseHas('paces', ['id' => $pace->id, 'stock' => 0]);

        $this->deleteJson('/api/assignments/'.$issued->id)->assertNoContent();
        $this->assertDatabaseHas('paces', ['id' => $pace->id, 'stock' => 1]);
    }

    public function test_admins_see_the_stock_of_their_teachers_paces(): void
    {
        $organization = $this->organization();
        $teacher = $this->teacher($organization);
        $pace = $this->pace(['number' => '1001', 'stock' => 7], $teacher);

        $this->signIn($this->admin($organization));

        $this->getJson('/api/admin/paces')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.stock', 7)
            ->assertJsonPath('data.0.pending_requests_count', 0);

        $this->getJson('/api/admin/paces/'.$pace->id)
            ->assertOk()
            ->assertJsonPath('data.stock', 7);
    }
}
