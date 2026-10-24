<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\GradeLevel;
use App\Models\Organization;
use App\Models\Pace;
use App\Models\PaceAssignment;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;

/**
 * The stock ledger: booklets arrive in the branch's pool, the admin hands
 * them to teachers (or takes them back), and issuing a PACE to a student
 * takes one from the teacher's own booklets.
 */
class PaceStockTest extends ApiTestCase
{
    private Organization $organization;

    private User $admin;

    private User $teacher;

    private GradeLevel $grade;

    private Student $student;

    private Subject $math;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->organization();
        $this->admin = $this->admin($this->organization);
        $this->teacher = $this->teacher($this->organization);
        $this->schoolYear('2026-2027', $this->admin, ['is_current' => true]);
        $this->grade = $this->gradeLevel('Grade 4', $this->teacher);
        $this->student = $this->student(['grade_level_id' => $this->grade->id], $this->teacher);
        $this->math = $this->subject($this->grade, 'Math');
    }

    public function test_an_admin_receives_booklets_into_the_pool_and_corrects_the_count(): void
    {
        $pace = $this->pace(['number' => '1001', 'name' => 'Math'], $this->admin);

        $this->signIn($this->admin);

        $this->postJson('/api/admin/paces/'.$pace->id.'/stock/receive', ['quantity' => 12, 'note' => 'Box from ACE'])
            ->assertOk()
            ->assertJsonPath('data.pool_stock', 12)
            ->assertJsonPath('data.holdings_total', 0);

        $this->postJson('/api/admin/paces/'.$pace->id.'/stock/receive', ['quantity' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity']);

        $this->patchJson('/api/admin/paces/'.$pace->id.'/stock/pool', ['delta' => -3])
            ->assertOk()
            ->assertJsonPath('data.pool_stock', 9);

        // The pool never drops below zero, and a zero change is meaningless.
        $this->patchJson('/api/admin/paces/'.$pace->id.'/stock/pool', ['delta' => -10])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['delta']);

        $this->patchJson('/api/admin/paces/'.$pace->id.'/stock/pool', ['delta' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['delta']);

        $this->patchJson('/api/admin/paces/'.$pace->id.'/stock/pool', ['delta' => 4, 'note' => 'Found a box'])
            ->assertOk()
            ->assertJsonPath('data.pool_stock', 13);

        $this->assertDatabaseHas('paces', ['id' => $pace->id, 'pool_stock' => 13]);

        // Every move is on the ledger, newest first.
        $this->getJson('/api/admin/paces/'.$pace->id.'/movements')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.type', 'adjusted')
            ->assertJsonPath('data.0.quantity', 4)
            ->assertJsonPath('data.0.note', 'Found a box')
            ->assertJsonPath('data.0.performer.id', $this->admin->id)
            ->assertJsonPath('data.1.type', 'adjusted')
            ->assertJsonPath('data.1.quantity', -3)
            ->assertJsonPath('data.2.type', 'received')
            ->assertJsonPath('data.2.quantity', 12)
            ->assertJsonPath('data.2.note', 'Box from ACE');

        // Another school's PACE is out of reach.
        $foreign = Pace::factory()->create();
        $this->postJson('/api/admin/paces/'.$foreign->id.'/stock/receive', ['quantity' => 5])->assertNotFound();
        $this->getJson('/api/admin/paces/'.$foreign->id.'/movements')->assertNotFound();
    }

    public function test_an_admin_hands_booklets_to_a_teacher_and_takes_them_back(): void
    {
        $pace = $this->pace(['number' => '1001', 'name' => 'Math', 'pool_stock' => 10], $this->admin);

        $this->signIn($this->admin);

        $this->postJson('/api/admin/paces/'.$pace->id.'/stock/distribute', ['teacher_id' => $this->teacher->id, 'quantity' => 4, 'note' => 'For Q1'])
            ->assertOk()
            ->assertJsonPath('data.pool_stock', 6)
            ->assertJsonPath('data.holdings_total', 4)
            ->assertJsonCount(1, 'data.holdings')
            ->assertJsonPath('data.holdings.0.teacher_id', $this->teacher->id)
            ->assertJsonPath('data.holdings.0.quantity', 4)
            ->assertJsonPath('data.holdings.0.teacher.name', $this->teacher->name);

        // Never more than the pool has; only teachers of the PACE's branch.
        $this->postJson('/api/admin/paces/'.$pace->id.'/stock/distribute', ['teacher_id' => $this->teacher->id, 'quantity' => 7])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity']);

        $this->postJson('/api/admin/paces/'.$pace->id.'/stock/distribute', ['teacher_id' => $this->teacher->id, 'quantity' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity']);

        $north = Branch::factory()->create(['organization_id' => $this->organization->id, 'name' => 'North']);

        $this->postJson('/api/admin/paces/'.$pace->id.'/stock/distribute', ['teacher_id' => $this->teacher($this->organization, [], $north)->id, 'quantity' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['teacher_id']);

        $this->postJson('/api/admin/paces/'.$pace->id.'/stock/distribute', ['teacher_id' => $this->admin->id, 'quantity' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['teacher_id']);

        $this->postJson('/api/admin/paces/'.$pace->id.'/stock/distribute', ['teacher_id' => User::factory()->create()->id, 'quantity' => 1])
            ->assertNotFound();

        $this->postJson('/api/admin/paces/'.$pace->id.'/stock/distribute', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['teacher_id', 'quantity']);

        // Setting the teacher's count moves the difference to or from the pool.
        $this->putJson('/api/admin/paces/'.$pace->id.'/stock/holdings/'.$this->teacher->id, ['quantity' => 1])
            ->assertOk()
            ->assertJsonPath('data.pool_stock', 9)
            ->assertJsonPath('data.holdings.0.quantity', 1);

        $this->putJson('/api/admin/paces/'.$pace->id.'/stock/holdings/'.$this->teacher->id, ['quantity' => 5])
            ->assertOk()
            ->assertJsonPath('data.pool_stock', 5)
            ->assertJsonPath('data.holdings.0.quantity', 5);

        $this->putJson('/api/admin/paces/'.$pace->id.'/stock/holdings/'.$this->teacher->id, ['quantity' => 5])
            ->assertOk()
            ->assertJsonPath('data.pool_stock', 5);

        $this->putJson('/api/admin/paces/'.$pace->id.'/stock/holdings/'.$this->teacher->id, ['quantity' => 20])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity']);

        $this->putJson('/api/admin/paces/'.$pace->id.'/stock/holdings/'.$this->teacher->id, ['quantity' => -1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity']);

        $this->putJson('/api/admin/paces/'.$pace->id.'/stock/holdings/'.User::factory()->create()->id, ['quantity' => 1])
            ->assertNotFound();

        $this->getJson('/api/admin/paces/'.$pace->id.'/movements')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.type', 'distributed')
            ->assertJsonPath('data.0.quantity', 4)
            ->assertJsonPath('data.0.to_teacher.id', $this->teacher->id)
            ->assertJsonPath('data.1.type', 'returned')
            ->assertJsonPath('data.1.quantity', 3)
            ->assertJsonPath('data.1.from_teacher.id', $this->teacher->id)
            ->assertJsonPath('data.2.type', 'distributed')
            ->assertJsonPath('data.2.note', 'For Q1');

        $this->assertDatabaseHas('paces', ['id' => $pace->id, 'pool_stock' => 5]);
        $this->assertDatabaseHas('pace_stock_holdings', ['pace_id' => $pace->id, 'user_id' => $this->teacher->id, 'quantity' => 5]);

        // The teacher sees their own booklets next to the pool.
        $this->forgetGuards();
        $this->signIn($this->teacher);

        $this->getJson('/api/paces')
            ->assertOk()
            ->assertJsonPath('data.0.pool_stock', 5)
            ->assertJsonPath('data.0.my_stock', 5);

        $this->getJson('/api/paces/'.$pace->id)
            ->assertOk()
            ->assertJsonPath('data.my_stock', 5);
    }

    public function test_issuing_paces_takes_them_from_the_teachers_booklets(): void
    {
        $this->signIn($this->teacher);
        $first = $this->pace(['number' => '1040', 'pool_stock' => 3, 'my_stock' => 2], $this->teacher);
        $second = $this->pace(['number' => '1041', 'my_stock' => 1], $this->teacher);

        $this->postJson('/api/students/'.$this->student->id.'/assignments', [
            'subject_id' => $this->math->id,
            'pace_ids' => [$first->id, $second->id],
            'school_year' => '2026-2027',
            'quarter' => 1,
            'issued_date' => '2026-09-01',
        ])
            ->assertCreated()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.pace.my_stock', 1)
            ->assertJsonPath('data.1.pace.my_stock', 0);

        $this->assertDatabaseHas('pace_stock_holdings', ['pace_id' => $first->id, 'user_id' => $this->teacher->id, 'quantity' => 1]);
        $this->assertDatabaseHas('pace_stock_holdings', ['pace_id' => $second->id, 'user_id' => $this->teacher->id, 'quantity' => 0]);
        // The pool is the admin's; issuing never touches it.
        $this->assertDatabaseHas('paces', ['id' => $first->id, 'pool_stock' => 3]);
    }

    public function test_assigning_without_an_issued_date_leaves_stock_alone(): void
    {
        $this->signIn($this->teacher);
        $pace = $this->pace(['number' => '1040', 'my_stock' => 0], $this->teacher);

        $this->postJson('/api/students/'.$this->student->id.'/assignments', [
            'subject_id' => $this->math->id,
            'pace_ids' => [$pace->id],
            'school_year' => '2026-2027',
            'quarter' => 1,
        ])
            ->assertCreated()
            ->assertJsonPath('data.0.issued_date', null)
            ->assertJsonPath('data.0.pace.my_stock', 0);

        $this->assertDatabaseHas('pace_stock_holdings', ['pace_id' => $pace->id, 'user_id' => $this->teacher->id, 'quantity' => 0]);
    }

    public function test_a_pace_the_teacher_has_no_booklet_of_cannot_be_issued(): void
    {
        $this->signIn($this->teacher);
        $inStock = $this->pace(['number' => '1040', 'name' => 'Math', 'my_stock' => 1], $this->teacher);
        // Booklets in the admin's pool do not count: they are not the teacher's yet.
        $outOfStock = $this->pace(['number' => '1041', 'name' => 'Math', 'pool_stock' => 5], $this->teacher);

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
            ->assertJsonPath('errors.issued_date.0', 'You have no booklet of PACE #1041 Math. Assign it without an issued date, or ask your administrator for stock first.');

        $this->assertDatabaseCount('pace_assignments', 0);
        $this->assertDatabaseHas('pace_stock_holdings', ['pace_id' => $inStock->id, 'user_id' => $this->teacher->id, 'quantity' => 1]);

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

    public function test_recording_an_issued_date_later_takes_one_booklet_and_clearing_it_gives_it_back(): void
    {
        $this->signIn($this->teacher);
        $pace = $this->pace(['number' => '1040', 'my_stock' => 1], $this->teacher);

        $first = $this->assignment(['student_id' => $this->student->id, 'subject_id' => $this->math->id, 'pace_id' => $pace->id], $this->teacher);
        $second = $this->assignment(['student_id' => $this->student->id, 'subject_id' => $this->math->id, 'pace_id' => $pace->id], $this->teacher);

        $this->putJson('/api/assignments/'.$first->id, ['issued_date' => '2026-09-01'])
            ->assertOk()
            ->assertJsonPath('data.issued_date', '2026-09-01')
            ->assertJsonPath('data.pace.my_stock', 0);

        $this->putJson('/api/assignments/'.$second->id, ['issued_date' => '2026-09-02'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['issued_date']);

        $this->assertDatabaseHas('pace_assignments', ['id' => $second->id, 'issued_date' => null]);

        // Scores and dates on an issued record do not touch the stock again.
        $this->putJson('/api/assignments/'.$first->id, ['date_tested' => '2026-09-15', 'score' => 90])
            ->assertOk()
            ->assertJsonPath('data.pace.my_stock', 0);

        $this->putJson('/api/assignments/'.$first->id, ['issued_date' => null, 'date_tested' => null, 'score' => null])
            ->assertOk()
            ->assertJsonPath('data.pace.my_stock', 1);

        $this->putJson('/api/assignments/'.$second->id, ['issued_date' => '2026-09-02'])
            ->assertOk()
            ->assertJsonPath('data.pace.my_stock', 0);
    }

    public function test_moving_an_issued_record_to_another_pace_moves_the_booklet(): void
    {
        $this->signIn($this->teacher);
        $from = $this->pace(['number' => '1040', 'name' => 'Math', 'my_stock' => 0], $this->teacher);
        $to = $this->pace(['number' => '1041', 'name' => 'Math', 'my_stock' => 1], $this->teacher);
        $empty = $this->pace(['number' => '1042', 'name' => 'Math', 'my_stock' => 0], $this->teacher);

        $assignment = PaceAssignment::factory()->issued()->create([
            'student_id' => $this->student->id,
            'subject_id' => $this->math->id,
            'pace_id' => $from->id,
        ]);

        $this->putJson('/api/assignments/'.$assignment->id, ['pace_id' => $to->id])
            ->assertOk()
            ->assertJsonPath('data.pace_id', $to->id)
            ->assertJsonPath('data.pace.my_stock', 0);

        $this->assertDatabaseHas('pace_stock_holdings', ['pace_id' => $from->id, 'user_id' => $this->teacher->id, 'quantity' => 1]);
        $this->assertDatabaseHas('pace_stock_holdings', ['pace_id' => $to->id, 'user_id' => $this->teacher->id, 'quantity' => 0]);

        // A PACE the teacher has no booklet of cannot take over an issued record.
        $this->putJson('/api/assignments/'.$assignment->id, ['pace_id' => $empty->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['issued_date']);

        $this->assertDatabaseHas('pace_assignments', ['id' => $assignment->id, 'pace_id' => $to->id]);
        $this->assertDatabaseHas('pace_stock_holdings', ['pace_id' => $to->id, 'user_id' => $this->teacher->id, 'quantity' => 0]);
        $this->assertDatabaseHas('pace_stock_holdings', ['pace_id' => $empty->id, 'user_id' => $this->teacher->id, 'quantity' => 0]);
    }

    public function test_deleting_an_issued_record_returns_the_booklet(): void
    {
        $this->signIn($this->teacher);
        $pace = $this->pace(['number' => '1040', 'my_stock' => 0], $this->teacher);

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
        $this->assertDatabaseHas('pace_stock_holdings', ['pace_id' => $pace->id, 'user_id' => $this->teacher->id, 'quantity' => 0]);

        $this->deleteJson('/api/assignments/'.$issued->id)->assertNoContent();
        $this->assertDatabaseHas('pace_stock_holdings', ['pace_id' => $pace->id, 'user_id' => $this->teacher->id, 'quantity' => 1]);
    }

    public function test_admins_see_the_pool_and_every_teachers_booklets(): void
    {
        $pace = $this->pace(['number' => '1001', 'pool_stock' => 7, 'my_stock' => 3], $this->teacher);
        $other = $this->teacher($this->organization);

        $this->signIn($this->admin);

        $this->getJson('/api/admin/paces')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.pool_stock', 7)
            ->assertJsonPath('data.0.holdings_total', 3)
            ->assertJsonCount(1, 'data.0.holdings')
            ->assertJsonPath('data.0.holdings.0.teacher.id', $this->teacher->id)
            ->assertJsonPath('data.0.pending_requests_count', 0);

        // Narrowed to the PACEs a teacher has booklets of.
        $this->getJson('/api/admin/paces?teacher_id='.$this->teacher->id)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/paces?teacher_id='.$other->id)->assertOk()->assertJsonCount(0, 'data');

        $this->getJson('/api/admin/paces/'.$pace->id)
            ->assertOk()
            ->assertJsonPath('data.pool_stock', 7)
            ->assertJsonPath('data.holdings_total', 3)
            ->assertJsonPath('data.holdings.0.quantity', 3);
    }

    public function test_the_stock_ledger_is_closed_to_teachers(): void
    {
        $pace = $this->pace(['number' => '1001'], $this->teacher);

        $this->signIn($this->teacher);

        $this->postJson('/api/admin/paces/'.$pace->id.'/stock/receive', ['quantity' => 5])->assertForbidden();
        $this->patchJson('/api/admin/paces/'.$pace->id.'/stock/pool', ['delta' => 5])->assertForbidden();
        $this->postJson('/api/admin/paces/'.$pace->id.'/stock/distribute', ['teacher_id' => $this->teacher->id, 'quantity' => 5])->assertForbidden();
        $this->putJson('/api/admin/paces/'.$pace->id.'/stock/holdings/'.$this->teacher->id, ['quantity' => 5])->assertForbidden();
        $this->getJson('/api/admin/paces/'.$pace->id.'/movements')->assertForbidden();

        $this->assertDatabaseHas('paces', ['id' => $pace->id, 'pool_stock' => 0]);
        $this->assertDatabaseCount('pace_stock_movements', 0);
    }
}
