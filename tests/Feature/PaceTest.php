<?php

namespace Tests\Feature;

use App\Models\PaceAssignment;
use App\Models\User;

class PaceTest extends ApiTestCase
{
    public function test_a_user_can_register_a_pace(): void
    {
        $user = $this->signIn();

        $this->postJson('/api/paces', ['name' => 'Math 1001', 'number' => '1001'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Math 1001')
            ->assertJsonPath('data.number', '1001')
            ->assertJsonPath('data.assignments_count', 0)
            ->assertJsonStructure(['data' => ['id', 'name', 'number', 'created_at', 'updated_at']]);

        $this->assertDatabaseHas('paces', [
            'name' => 'Math 1001',
            'number' => '1001',
            'created_by' => $user->id,
        ]);
    }

    public function test_pace_creation_is_validated(): void
    {
        $this->signIn();

        $this->postJson('/api/paces', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'number']);

        $this->postJson('/api/paces', ['name' => 'Too long', 'number' => str_repeat('9', 51)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['number']);
    }

    public function test_pace_numbers_do_not_need_to_be_unique(): void
    {
        $this->signIn();
        $this->pace(['number' => '1001', 'name' => 'Math']);

        $this->postJson('/api/paces', ['name' => 'Word Building', 'number' => '1001'])
            ->assertCreated()
            ->assertJsonPath('data.number', '1001');

        $this->assertDatabaseCount('paces', 2);
        $this->assertDatabaseHas('paces', ['number' => '1001', 'name' => 'Math']);
        $this->assertDatabaseHas('paces', ['number' => '1001', 'name' => 'Word Building']);
    }

    public function test_a_user_can_register_a_range_of_paces_with_one_name(): void
    {
        $this->signIn();

        $this->postJson('/api/paces/range', ['name' => 'Filipino', 'start' => '1001', 'end' => '1005'])
            ->assertCreated()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.number', '1001')
            ->assertJsonPath('data.0.name', 'Filipino')
            ->assertJsonPath('data.4.number', '1005')
            ->assertJsonPath('data.4.name', 'Filipino');

        $this->assertDatabaseCount('paces', 5);

        foreach (range(1001, 1005) as $number) {
            $this->assertDatabaseHas('paces', ['number' => (string) $number, 'name' => 'Filipino']);
        }
    }

    public function test_a_pace_range_keeps_leading_zeros_from_the_start_number(): void
    {
        $this->signIn();

        $this->postJson('/api/paces/range', ['name' => 'Math', 'start' => '001', 'end' => '003'])
            ->assertCreated()
            ->assertJsonPath('data.0.number', '001')
            ->assertJsonPath('data.1.number', '002')
            ->assertJsonPath('data.2.number', '003');
    }

    public function test_pace_range_registration_is_validated(): void
    {
        $this->signIn();

        $this->postJson('/api/paces/range', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'start', 'end']);

        $this->postJson('/api/paces/range', ['name' => 'Backwards', 'start' => '1010', 'end' => '1001'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end']);

        $this->postJson('/api/paces/range', ['name' => 'Too big', 'start' => '1', 'end' => '502'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end']);

        $this->assertDatabaseCount('paces', 0);
    }

    public function test_pace_range_numbers_do_not_need_to_be_unique(): void
    {
        $this->signIn();
        $this->pace(['number' => '1002', 'name' => 'Existing']);

        $this->postJson('/api/paces/range', ['name' => 'Filipino', 'start' => '1001', 'end' => '1003'])
            ->assertCreated()
            ->assertJsonCount(3, 'data');

        $this->assertDatabaseCount('paces', 4);
        $this->assertDatabaseHas('paces', ['number' => '1002', 'name' => 'Existing']);
        $this->assertDatabaseHas('paces', ['number' => '1002', 'name' => 'Filipino']);
    }

    public function test_paces_are_listed_in_natural_number_order_with_counts(): void
    {
        $this->signIn();

        $p10 = $this->pace(['number' => '10', 'name' => 'Ten']);
        $p9 = $this->pace(['number' => '9', 'name' => 'Nine']);
        $p100 = $this->pace(['number' => '100', 'name' => 'Hundred']);

        PaceAssignment::factory()->count(3)->create(['pace_id' => $p9->id]);

        $response = $this->getJson('/api/paces')->assertOk()->assertJsonCount(3, 'data');

        $this->assertSame([$p9->id, $p10->id, $p100->id], array_column($response->json('data'), 'id'));

        $response->assertJsonPath('data.0.assignments_count', 3)
            ->assertJsonPath('data.1.assignments_count', 0);
    }

    public function test_paces_report_the_assigned_issued_and_tested_breakdown(): void
    {
        $this->signIn();
        $pace = $this->pace(['number' => '1001']);

        // One of each stage: assigned-only, issued-only, and two tested.
        PaceAssignment::factory()->create(['pace_id' => $pace->id]);
        PaceAssignment::factory()->issued()->create(['pace_id' => $pace->id]);
        PaceAssignment::factory()->tested()->count(2)->create(['pace_id' => $pace->id]);

        $this->getJson('/api/paces')
            ->assertOk()
            ->assertJsonPath('data.0.assignments_count', 4)
            ->assertJsonPath('data.0.tested_count', 2)
            ->assertJsonPath('data.0.issued_count', 1)
            ->assertJsonPath('data.0.assigned_count', 1);

        // The PACEs list upserts the updated PACE, so the update response needs the breakdown too.
        $this->putJson('/api/paces/'.$pace->id, ['name' => 'Renamed', 'number' => '1001'])
            ->assertOk()
            ->assertJsonPath('data.assignments_count', 4)
            ->assertJsonPath('data.tested_count', 2)
            ->assertJsonPath('data.issued_count', 1)
            ->assertJsonPath('data.assigned_count', 1);
    }

    public function test_paces_can_be_searched_by_number_or_name(): void
    {
        $this->signIn();

        $this->pace(['number' => '1042', 'name' => 'Word Building']);
        $this->pace(['number' => '2042', 'name' => 'Science']);
        $this->pace(['number' => '3001', 'name' => 'Building Blocks']);

        $this->getJson('/api/paces?search=042')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/paces?search=building')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/paces?search=nothing')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_user_only_sees_their_own_paces(): void
    {
        $this->signIn();
        $mine = $this->pace(['number' => '1001', 'name' => 'Mine']);

        $stranger = User::factory()->create();
        $theirs = $this->pace(['number' => '1001', 'name' => 'Theirs'], $stranger);

        $this->getJson('/api/paces')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);

        $this->getJson('/api/paces/'.$theirs->id)->assertNotFound();
        $this->putJson('/api/paces/'.$theirs->id, ['name' => 'Hijacked', 'number' => '1'])->assertNotFound();
        $this->deleteJson('/api/paces/'.$theirs->id)->assertNotFound();

        $this->assertDatabaseHas('paces', ['id' => $theirs->id, 'name' => 'Theirs']);
    }

    public function test_a_pace_detail_includes_its_assignments_with_student_and_subject(): void
    {
        $this->signIn();

        $pace = $this->pace();
        $student = $this->student(['first_name' => 'Ana', 'last_name' => 'Cruz']);
        $assignment = PaceAssignment::factory()->create(['pace_id' => $pace->id, 'student_id' => $student->id]);

        $this->getJson('/api/paces/'.$pace->id)
            ->assertOk()
            ->assertJsonPath('data.id', $pace->id)
            ->assertJsonPath('data.assignments_count', 1)
            ->assertJsonCount(1, 'data.assignments')
            ->assertJsonPath('data.assignments.0.id', $assignment->id)
            ->assertJsonPath('data.assignments.0.student.id', $student->id)
            ->assertJsonPath('data.assignments.0.student.full_name', 'Cruz, Ana'.($student->middle_name ? ' '.$student->middle_name : '').($student->extension ? ' '.$student->extension : ''))
            ->assertJsonPath('data.assignments.0.subject.id', $assignment->subject_id);
    }

    public function test_a_user_can_update_a_pace(): void
    {
        $this->signIn();
        $pace = $this->pace(['number' => '1001', 'name' => 'Old']);

        $this->putJson('/api/paces/'.$pace->id, ['name' => 'New name', 'number' => '1002'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New name')
            ->assertJsonPath('data.number', '1002');

        $this->assertDatabaseHas('paces', ['id' => $pace->id, 'name' => 'New name', 'number' => '1002']);
    }

    public function test_updating_a_pace_may_reuse_another_paces_number(): void
    {
        $this->signIn();
        $pace = $this->pace(['number' => '1001']);
        $this->pace(['number' => '1002']);

        $this->putJson('/api/paces/'.$pace->id, ['name' => 'Same number', 'number' => '1001'])
            ->assertOk();

        $this->putJson('/api/paces/'.$pace->id, ['name' => 'Reused number', 'number' => '1002'])
            ->assertOk()
            ->assertJsonPath('data.number', '1002');
    }

    public function test_a_user_can_delete_a_pace_and_its_assignments(): void
    {
        $this->signIn();
        $pace = $this->pace();
        PaceAssignment::factory()->count(2)->create(['pace_id' => $pace->id]);

        $this->deleteJson('/api/paces/'.$pace->id)->assertNoContent();

        $this->assertDatabaseMissing('paces', ['id' => $pace->id]);
        $this->assertDatabaseCount('pace_assignments', 0);
    }

    public function test_guests_cannot_manage_paces(): void
    {
        $this->getJson('/api/paces')->assertUnauthorized();
        $this->postJson('/api/paces', ['name' => 'X', 'number' => '1'])->assertUnauthorized();

        $this->assertDatabaseCount('paces', 0);
    }
}
