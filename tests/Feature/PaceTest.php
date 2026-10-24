<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\Pace;
use App\Models\PaceAssignment;
use App\Models\User;

/**
 * The PACE catalog is per branch: admins register PACEs in it (singly or
 * as a range), teachers read their branch's catalog.
 */
class PaceTest extends ApiTestCase
{
    private Organization $organization;

    private User $admin;

    private User $teacher;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->organization();
        $this->admin = $this->admin($this->organization);
        $this->teacher = $this->teacher($this->organization);
        $this->branch = $this->branchOf($this->admin);
    }

    public function test_an_admin_registers_a_pace_in_a_branch_catalog(): void
    {
        $this->signIn($this->admin);

        $this->postJson('/api/admin/paces', ['branch_id' => $this->branch->id, 'name' => 'Math 1001', 'number' => '1001'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Math 1001')
            ->assertJsonPath('data.number', '1001')
            ->assertJsonPath('data.branch_id', $this->branch->id)
            ->assertJsonPath('data.branch.name', $this->branch->name)
            ->assertJsonPath('data.pool_stock', 0)
            ->assertJsonPath('data.holdings_total', 0)
            ->assertJsonPath('data.holdings', [])
            ->assertJsonPath('data.assignments_count', 0)
            ->assertJsonPath('data.pending_requests_count', 0)
            ->assertJsonStructure(['data' => ['id', 'name', 'number', 'created_at', 'updated_at']]);

        $this->assertDatabaseHas('paces', [
            'name' => 'Math 1001',
            'number' => '1001',
            'branch_id' => $this->branch->id,
            'pool_stock' => 0,
            'created_by' => $this->admin->id,
        ]);

        // Booklets that arrived with it go into the pool, through the ledger.
        $withStock = $this->postJson('/api/admin/paces', ['branch_id' => $this->branch->id, 'name' => 'Math 1002', 'number' => '1002', 'pool_stock' => 12])
            ->assertCreated()
            ->assertJsonPath('data.pool_stock', 12);

        $this->assertDatabaseHas('pace_stock_movements', [
            'pace_id' => $withStock->json('data.id'),
            'type' => 'received',
            'quantity' => 12,
            'performed_by' => $this->admin->id,
        ]);
    }

    public function test_pace_creation_is_validated(): void
    {
        $this->signIn($this->admin);

        $this->postJson('/api/admin/paces', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch_id', 'name', 'number']);

        $this->postJson('/api/admin/paces', ['branch_id' => $this->branch->id, 'name' => 'Too long', 'number' => str_repeat('9', 51)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['number']);

        $this->postJson('/api/admin/paces', ['branch_id' => $this->branch->id, 'name' => 'Letters', 'number' => '10a1', 'pool_stock' => -1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['number', 'pool_stock']);

        // Another school's branch is out of reach.
        $this->postJson('/api/admin/paces', ['branch_id' => $this->admin()->branch_id, 'name' => 'Math', 'number' => '1001'])
            ->assertNotFound();

        $this->assertDatabaseCount('paces', 0);
    }

    public function test_a_number_may_repeat_under_another_name_but_not_twice_in_one_catalog(): void
    {
        $this->signIn($this->admin);
        $this->pace(['number' => '1001', 'name' => 'Math'], $this->admin);

        $this->postJson('/api/admin/paces', ['branch_id' => $this->branch->id, 'name' => 'Word Building', 'number' => '1001'])
            ->assertCreated()
            ->assertJsonPath('data.number', '1001');

        $this->postJson('/api/admin/paces', ['branch_id' => $this->branch->id, 'name' => 'Math', 'number' => '1001'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['number']);

        $this->assertDatabaseCount('paces', 2);

        // Each branch has its own catalog.
        $north = Branch::factory()->create(['organization_id' => $this->organization->id, 'name' => 'North']);

        $this->postJson('/api/admin/paces', ['branch_id' => $north->id, 'name' => 'Math', 'number' => '1001'])
            ->assertCreated()
            ->assertJsonPath('data.branch.name', 'North');
    }

    public function test_an_admin_registers_a_range_of_paces_with_one_name(): void
    {
        $this->signIn($this->admin);

        $this->postJson('/api/admin/paces/range', ['branch_id' => $this->branch->id, 'name' => 'Filipino', 'start' => '1001', 'end' => '1005', 'pool_stock' => 4])
            ->assertCreated()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.number', '1001')
            ->assertJsonPath('data.0.name', 'Filipino')
            ->assertJsonPath('data.0.pool_stock', 4)
            ->assertJsonPath('data.4.number', '1005')
            ->assertJsonPath('data.4.pool_stock', 4);

        $this->assertDatabaseCount('paces', 5);

        foreach (range(1001, 1005) as $number) {
            $this->assertDatabaseHas('paces', ['number' => (string) $number, 'name' => 'Filipino', 'branch_id' => $this->branch->id, 'pool_stock' => 4]);
        }

        // Stock is optional and starts at zero.
        $this->postJson('/api/admin/paces/range', ['branch_id' => $this->branch->id, 'name' => 'Science', 'start' => '2001', 'end' => '2002'])
            ->assertCreated()
            ->assertJsonPath('data.0.pool_stock', 0);
    }

    public function test_a_pace_range_keeps_leading_zeros_from_the_start_number(): void
    {
        $this->signIn($this->admin);

        $this->postJson('/api/admin/paces/range', ['branch_id' => $this->branch->id, 'name' => 'Math', 'start' => '001', 'end' => '003'])
            ->assertCreated()
            ->assertJsonPath('data.0.number', '001')
            ->assertJsonPath('data.1.number', '002')
            ->assertJsonPath('data.2.number', '003');
    }

    public function test_pace_range_registration_is_validated(): void
    {
        $this->signIn($this->admin);

        $this->postJson('/api/admin/paces/range', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch_id', 'name', 'start', 'end']);

        $this->postJson('/api/admin/paces/range', ['branch_id' => $this->branch->id, 'name' => 'Backwards', 'start' => '1010', 'end' => '1001'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end']);

        $this->postJson('/api/admin/paces/range', ['branch_id' => $this->branch->id, 'name' => 'Too big', 'start' => '1', 'end' => '502'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end']);

        $this->assertDatabaseCount('paces', 0);
    }

    public function test_a_range_leaves_numbers_already_in_the_catalog_under_that_name_alone(): void
    {
        $this->signIn($this->admin);
        $this->pace(['number' => '1002', 'name' => 'Existing'], $this->admin);
        $already = $this->pace(['number' => '1003', 'name' => 'Filipino', 'pool_stock' => 7], $this->admin);

        $this->postJson('/api/admin/paces/range', ['branch_id' => $this->branch->id, 'name' => 'Filipino', 'start' => '1001', 'end' => '1003', 'pool_stock' => 1])
            ->assertCreated()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.2.id', $already->id)
            ->assertJsonPath('data.2.pool_stock', 7);

        $this->assertDatabaseCount('paces', 4);
        $this->assertDatabaseHas('paces', ['number' => '1002', 'name' => 'Existing', 'pool_stock' => 0]);
        $this->assertDatabaseHas('paces', ['number' => '1002', 'name' => 'Filipino', 'pool_stock' => 1]);
        $this->assertDatabaseHas('paces', ['id' => $already->id, 'pool_stock' => 7]);
    }

    public function test_paces_are_listed_in_natural_number_order_with_counts(): void
    {
        $p10 = $this->pace(['number' => '10', 'name' => 'Ten'], $this->teacher);
        $p9 = $this->pace(['number' => '9', 'name' => 'Nine', 'my_stock' => 2], $this->teacher);
        $p100 = $this->pace(['number' => '100', 'name' => 'Hundred'], $this->teacher);

        PaceAssignment::factory()->count(3)->create(['pace_id' => $p9->id]);

        $this->signIn($this->teacher);

        $response = $this->getJson('/api/paces')->assertOk()->assertJsonCount(3, 'data');

        $this->assertSame([$p9->id, $p10->id, $p100->id], array_column($response->json('data'), 'id'));

        $response->assertJsonPath('data.0.assignments_count', 3)
            ->assertJsonPath('data.0.my_stock', 2)
            ->assertJsonPath('data.0.pool_stock', 0)
            ->assertJsonPath('data.1.assignments_count', 0)
            ->assertJsonPath('data.1.my_stock', 0);

        $this->forgetGuards();
        $this->signIn($this->admin);

        $response = $this->getJson('/api/admin/paces')->assertOk()->assertJsonCount(3, 'data');

        $this->assertSame([$p9->id, $p10->id, $p100->id], array_column($response->json('data'), 'id'));

        $response->assertJsonPath('data.0.assignments_count', 3)
            ->assertJsonPath('data.0.holdings_total', 2)
            ->assertJsonPath('data.0.holdings.0.teacher_id', $this->teacher->id);
    }

    public function test_paces_report_the_assigned_issued_and_tested_breakdown(): void
    {
        $this->signIn($this->admin);
        $pace = $this->pace(['number' => '1001'], $this->admin);

        // One of each stage: assigned-only, issued-only, and two tested.
        PaceAssignment::factory()->create(['pace_id' => $pace->id]);
        PaceAssignment::factory()->issued()->create(['pace_id' => $pace->id]);
        PaceAssignment::factory()->tested()->count(2)->create(['pace_id' => $pace->id]);

        $this->getJson('/api/admin/paces')
            ->assertOk()
            ->assertJsonPath('data.0.assignments_count', 4)
            ->assertJsonPath('data.0.tested_count', 2)
            ->assertJsonPath('data.0.issued_count', 1)
            ->assertJsonPath('data.0.assigned_count', 1);

        // The PACEs list upserts the updated PACE, so the update response needs the breakdown too.
        $this->putJson('/api/admin/paces/'.$pace->id, ['name' => 'Renamed', 'number' => '1001'])
            ->assertOk()
            ->assertJsonPath('data.assignments_count', 4)
            ->assertJsonPath('data.tested_count', 2)
            ->assertJsonPath('data.issued_count', 1)
            ->assertJsonPath('data.assigned_count', 1);
    }

    public function test_paces_can_be_searched_by_number_or_name(): void
    {
        $this->pace(['number' => '1042', 'name' => 'Word Building'], $this->teacher);
        $this->pace(['number' => '2042', 'name' => 'Science'], $this->teacher);
        $this->pace(['number' => '3001', 'name' => 'Building Blocks'], $this->teacher);

        $this->signIn($this->teacher);

        $this->getJson('/api/paces?search=042')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/paces?search=building')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/paces?search=nothing')->assertOk()->assertJsonCount(0, 'data');

        $this->forgetGuards();
        $this->signIn($this->admin);

        $this->getJson('/api/admin/paces?search=042')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/admin/paces?search=nothing')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_teacher_only_sees_their_branchs_catalog(): void
    {
        $mine = $this->pace(['number' => '1001', 'name' => 'Mine'], $this->teacher);

        $north = Branch::factory()->create(['organization_id' => $this->organization->id, 'name' => 'North']);
        $northPace = Pace::factory()->forBranch($north)->create(['number' => '1001', 'name' => 'North']);

        $stranger = User::factory()->create();
        $theirs = $this->pace(['number' => '1001', 'name' => 'Theirs'], $stranger);

        $this->signIn($this->teacher);

        $this->getJson('/api/paces')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);

        $this->getJson('/api/paces/'.$northPace->id)->assertNotFound();
        $this->getJson('/api/paces/'.$theirs->id)->assertNotFound();

        // The admin sees every branch of the school, and may narrow to one.
        $this->forgetGuards();
        $this->signIn($this->admin);

        $this->getJson('/api/admin/paces')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/admin/paces?branch_id='.$north->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $northPace->id);

        $this->getJson('/api/admin/paces/'.$theirs->id)->assertNotFound();
        $this->putJson('/api/admin/paces/'.$theirs->id, ['name' => 'Hijacked', 'number' => '1'])->assertNotFound();
        $this->deleteJson('/api/admin/paces/'.$theirs->id)->assertNotFound();

        $this->assertDatabaseHas('paces', ['id' => $theirs->id, 'name' => 'Theirs']);
    }

    public function test_a_pace_detail_includes_its_assignments_with_student_and_subject(): void
    {
        $pace = $this->pace(['my_stock' => 2], $this->teacher);
        $student = $this->student(['first_name' => 'Ana', 'last_name' => 'Cruz'], $this->teacher);
        $assignment = PaceAssignment::factory()->create(['pace_id' => $pace->id, 'student_id' => $student->id]);

        // A colleague's student (a section the teacher does not teach in).
        $colleague = $this->teacher($this->organization);
        PaceAssignment::factory()->create(['pace_id' => $pace->id, 'student_id' => $this->student([], $colleague)->id]);

        $this->signIn($this->teacher);

        $this->getJson('/api/paces/'.$pace->id)
            ->assertOk()
            ->assertJsonPath('data.id', $pace->id)
            ->assertJsonPath('data.my_stock', 2)
            ->assertJsonPath('data.assignments_count', 1)
            ->assertJsonCount(1, 'data.assignments')
            ->assertJsonPath('data.assignments.0.id', $assignment->id)
            ->assertJsonPath('data.assignments.0.student.id', $student->id)
            ->assertJsonPath('data.assignments.0.student.full_name', 'Cruz, Ana'.($student->middle_name ? ' '.$student->middle_name : '').($student->extension ? ' '.$student->extension : ''))
            ->assertJsonPath('data.assignments.0.subject.id', $assignment->subject_id)
            ->assertJsonMissingPath('data.holdings');

        $this->forgetGuards();
        $this->signIn($this->admin);

        $this->getJson('/api/admin/paces/'.$pace->id)
            ->assertOk()
            ->assertJsonPath('data.assignments_count', 2)
            ->assertJsonCount(2, 'data.assignments')
            ->assertJsonPath('data.branch.id', $this->branch->id)
            ->assertJsonPath('data.holdings_total', 2)
            ->assertJsonCount(1, 'data.holdings')
            ->assertJsonPath('data.holdings.0.teacher_id', $this->teacher->id)
            ->assertJsonPath('data.holdings.0.quantity', 2)
            ->assertJsonPath('data.holdings.0.teacher.name', $this->teacher->name)
            ->assertJsonMissingPath('data.my_stock');
    }

    public function test_an_admin_can_update_a_pace(): void
    {
        $this->signIn($this->admin);
        $pace = $this->pace(['number' => '1001', 'name' => 'Old', 'pool_stock' => 3], $this->admin);

        $this->putJson('/api/admin/paces/'.$pace->id, ['name' => 'New name', 'number' => '1002', 'pool_stock' => 99])
            ->assertOk()
            ->assertJsonPath('data.name', 'New name')
            ->assertJsonPath('data.number', '1002')
            // The pool only changes through the ledger.
            ->assertJsonPath('data.pool_stock', 3);

        $this->assertDatabaseHas('paces', ['id' => $pace->id, 'name' => 'New name', 'number' => '1002', 'pool_stock' => 3]);
    }

    public function test_renumbering_may_reuse_another_paces_number_under_another_name(): void
    {
        $this->signIn($this->admin);
        $pace = $this->pace(['number' => '1001', 'name' => 'Math'], $this->admin);
        $this->pace(['number' => '1002', 'name' => 'Science'], $this->admin);

        $this->putJson('/api/admin/paces/'.$pace->id, ['name' => 'Math', 'number' => '1001'])->assertOk();

        $this->putJson('/api/admin/paces/'.$pace->id, ['name' => 'Math', 'number' => '1002'])
            ->assertOk()
            ->assertJsonPath('data.number', '1002');

        $this->putJson('/api/admin/paces/'.$pace->id, ['name' => 'Science', 'number' => '1002'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['number']);
    }

    public function test_an_admin_can_delete_a_pace_with_its_records_holdings_and_ledger(): void
    {
        $this->signIn($this->admin);
        $pace = $this->pace(['my_stock' => 2], $this->teacher);
        PaceAssignment::factory()->count(2)->create(['pace_id' => $pace->id]);
        $this->postJson('/api/admin/paces/'.$pace->id.'/stock/receive', ['quantity' => 3])->assertOk();

        $this->deleteJson('/api/admin/paces/'.$pace->id)->assertNoContent();

        $this->assertDatabaseMissing('paces', ['id' => $pace->id]);
        $this->assertDatabaseCount('pace_assignments', 0);
        $this->assertDatabaseCount('pace_stock_holdings', 0);
        $this->assertDatabaseCount('pace_stock_movements', 0);
    }

    public function test_teachers_and_guests_cannot_manage_the_catalog(): void
    {
        $pace = $this->pace([], $this->teacher);

        $this->getJson('/api/paces')->assertUnauthorized();
        $this->postJson('/api/admin/paces', ['branch_id' => $this->branch->id, 'name' => 'X', 'number' => '1'])->assertUnauthorized();

        $this->signIn($this->teacher);

        $this->postJson('/api/admin/paces', ['branch_id' => $this->branch->id, 'name' => 'X', 'number' => '1'])->assertForbidden();
        $this->putJson('/api/admin/paces/'.$pace->id, ['name' => 'X', 'number' => '1'])->assertForbidden();
        $this->deleteJson('/api/admin/paces/'.$pace->id)->assertForbidden();

        $this->forgetGuards();
        $this->signIn($this->admin);

        $this->getJson('/api/paces')->assertForbidden();

        $this->assertDatabaseCount('paces', 1);
    }
}
