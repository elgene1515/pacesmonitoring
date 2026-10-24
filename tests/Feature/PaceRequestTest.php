<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\PaceRequest;
use App\Models\User;

/**
 * Teachers ask their school admin for PACE booklets; the admin hands them
 * over from the branch's pool (registering a new PACE in the catalog when
 * the request names one) or declines.
 */
class PaceRequestTest extends ApiTestCase
{
    public function test_a_teacher_requests_booklets_for_a_pace_of_the_catalog(): void
    {
        $organization = $this->organization();
        $teacher = $this->teacher($organization);
        $pace = $this->pace(['number' => '1001', 'name' => 'Math'], $teacher);

        $this->signIn($teacher);

        $this->postJson('/api/pace-requests', [
            'pace_id' => $pace->id,
            'quantity' => 10,
            'needed_by' => '2026-11-01',
            'message' => 'Running out before the second quarter',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.pace_id', $pace->id)
            ->assertJsonPath('data.pace_number', '1001')
            ->assertJsonPath('data.pace_name', 'Math')
            ->assertJsonPath('data.quantity', 10)
            ->assertJsonPath('data.needed_by', '2026-11-01')
            ->assertJsonPath('data.message', 'Running out before the second quarter')
            ->assertJsonPath('data.pace.id', $pace->id)
            ->assertJsonPath('data.teacher.id', $teacher->id);

        $this->assertDatabaseHas('pace_requests', [
            'organization_id' => $organization->id,
            'branch_id' => $teacher->branch_id,
            'user_id' => $teacher->id,
            'pace_id' => $pace->id,
            'quantity' => 10,
            'status' => 'pending',
        ]);

        $this->getJson('/api/pace-requests')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/pace-requests?status=given')->assertOk()->assertJsonCount(0, 'data');

        // The PACE shows the request waiting, and the admin is told through the organization's pending count.
        $this->getJson('/api/paces/'.$pace->id)->assertOk()->assertJsonPath('data.pending_requests_count', 1);
        $this->getJson('/api/paces')->assertOk()->assertJsonPath('data.0.pending_requests_count', 1);

        $this->forgetGuards();
        $this->signIn($this->admin($organization));

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.organization.pending_pace_requests_count', 1);
    }

    public function test_a_request_may_name_a_pace_that_is_not_in_the_catalog_yet(): void
    {
        $teacher = User::factory()->create();
        $this->signIn($teacher);

        $this->postJson('/api/pace-requests', ['pace_number' => '1050', 'pace_name' => 'Science', 'quantity' => 3])
            ->assertCreated()
            ->assertJsonPath('data.pace_id', null)
            ->assertJsonPath('data.pace', null)
            ->assertJsonPath('data.pace_number', '1050')
            ->assertJsonPath('data.pace_name', 'Science');

        // A number + name already in the branch's catalog is linked automatically.
        $pace = $this->pace(['number' => '1060', 'name' => 'Math'], $teacher);

        $this->postJson('/api/pace-requests', ['pace_number' => '1060', 'pace_name' => 'Math', 'quantity' => 2])
            ->assertCreated()
            ->assertJsonPath('data.pace_id', $pace->id);

        $this->postJson('/api/pace-requests', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pace_number', 'pace_name', 'quantity']);

        $this->postJson('/api/pace-requests', ['pace_number' => 'abc', 'pace_name' => 'Science', 'quantity' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pace_number', 'quantity']);

        // A PACE of another branch's catalog cannot be asked for.
        $foreign = $this->pace([], User::factory()->create());

        $this->postJson('/api/pace-requests', ['pace_id' => $foreign->id, 'quantity' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pace_id']);

        $this->assertDatabaseCount('pace_requests', 2);
    }

    public function test_a_teacher_requests_several_paces_at_once(): void
    {
        $organization = $this->organization();
        $teacher = $this->teacher($organization);
        $math = $this->pace(['number' => '1001', 'name' => 'Math'], $teacher);
        $english = $this->pace(['number' => '1101', 'name' => 'English'], $teacher);

        $this->signIn($teacher);

        // One request per PACE, sharing the date and note; a free-form PACE is fine too.
        $response = $this->postJson('/api/pace-requests/batch', [
            'items' => [
                ['pace_id' => $math->id, 'quantity' => 5],
                ['pace_id' => $english->id, 'quantity' => 2],
                ['pace_number' => '1201', 'pace_name' => 'Science', 'quantity' => 4],
            ],
            'needed_by' => '2026-11-15',
            'message' => 'For the second quarter',
        ])
            ->assertCreated()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.pace_id', $math->id)
            ->assertJsonPath('data.0.quantity', 5)
            ->assertJsonPath('data.0.needed_by', '2026-11-15')
            ->assertJsonPath('data.0.message', 'For the second quarter')
            ->assertJsonPath('data.2.pace_id', null)
            ->assertJsonPath('data.2.pace_number', '1201')
            ->assertJsonPath('data.2.quantity', 4);

        $this->assertDatabaseCount('pace_requests', 3);
        $this->assertSame(3, PaceRequest::query()->where('user_id', $teacher->id)->pending()->count());
        $this->getJson('/api/paces/'.$english->id)->assertOk()->assertJsonPath('data.pending_requests_count', 1);

        // All or nothing: one bad row (a PACE of another branch) refuses the whole batch.
        $foreign = $this->pace([], User::factory()->create());

        $this->postJson('/api/pace-requests/batch', [
            'items' => [
                ['pace_id' => $math->id, 'quantity' => 1],
                ['pace_id' => $foreign->id, 'quantity' => 1],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.1.pace_id']);

        $this->postJson('/api/pace-requests/batch', ['items' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);

        $this->postJson('/api/pace-requests/batch', ['items' => [['pace_number' => '1300', 'quantity' => 0]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.pace_name', 'items.0.quantity']);

        $this->assertDatabaseCount('pace_requests', 3);

        // Admins do not file stock requests.
        $this->forgetGuards();
        $this->signIn($this->admin($organization));
        $this->postJson('/api/pace-requests/batch', ['items' => [['pace_id' => $math->id, 'quantity' => 1]]])->assertForbidden();
    }

    public function test_a_teacher_edits_and_withdraws_their_own_pending_request(): void
    {
        $teacher = User::factory()->create();
        $request = PaceRequest::factory()->create(['user_id' => $teacher->id, 'organization_id' => $teacher->organization_id, 'quantity' => 4]);
        $someoneElses = PaceRequest::factory()->create();

        $this->signIn($teacher);

        $this->putJson('/api/pace-requests/'.$request->id, ['quantity' => 7, 'needed_by' => '2026-12-01', 'message' => 'Make it seven'])
            ->assertOk()
            ->assertJsonPath('data.quantity', 7)
            ->assertJsonPath('data.needed_by', '2026-12-01')
            ->assertJsonPath('data.message', 'Make it seven');

        // Linking it to a catalog PACE copies that PACE's number and name.
        $pace = $this->pace(['number' => '1077', 'name' => 'Word Building'], $teacher);

        $this->putJson('/api/pace-requests/'.$request->id, ['pace_id' => $pace->id])
            ->assertOk()
            ->assertJsonPath('data.pace_id', $pace->id)
            ->assertJsonPath('data.pace_number', '1077')
            ->assertJsonPath('data.pace_name', 'Word Building');

        // Renaming it detaches it; naming a catalog entry again links it back.
        $this->putJson('/api/pace-requests/'.$request->id, ['pace_name' => 'Word Building 2'])
            ->assertOk()
            ->assertJsonPath('data.pace_id', null);

        $this->putJson('/api/pace-requests/'.$request->id, ['pace_name' => 'Word Building'])
            ->assertOk()
            ->assertJsonPath('data.pace_id', $pace->id);

        $this->putJson('/api/pace-requests/'.$someoneElses->id, ['quantity' => 1])->assertNotFound();
        $this->deleteJson('/api/pace-requests/'.$someoneElses->id)->assertNotFound();

        $this->deleteJson('/api/pace-requests/'.$request->id)->assertNoContent();
        $this->assertDatabaseHas('pace_requests', ['id' => $request->id, 'status' => 'cancelled']);

        // A decided request is history: no more edits or withdrawals.
        $this->putJson('/api/pace-requests/'.$request->id, ['quantity' => 1])->assertUnprocessable();
        $this->deleteJson('/api/pace-requests/'.$request->id)->assertUnprocessable();
        $this->assertDatabaseHas('pace_requests', ['id' => $someoneElses->id, 'status' => 'pending']);
    }

    public function test_an_admin_lists_edits_and_fulfils_their_teachers_requests_from_the_pool(): void
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $teacher = $this->teacher($organization, ['name' => 'Tim Teacher']);
        $pace = $this->pace(['number' => '1001', 'name' => 'Math', 'pool_stock' => 12], $teacher);
        $request = PaceRequest::factory()->forPace($pace, $teacher)->create(['quantity' => 10]);
        $elsewhere = PaceRequest::factory()->create();

        $this->signIn($admin);

        $this->getJson('/api/admin/pace-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $request->id)
            ->assertJsonPath('data.0.teacher.name', 'Tim Teacher')
            ->assertJsonPath('data.0.pace.pool_stock', 12);

        $this->getJson('/api/admin/pace-requests?status=given')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/pace-requests?teacher_id='.$teacher->id)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/pace-requests?teacher_id='.($teacher->id + 1000))->assertOk()->assertJsonCount(0, 'data');

        $this->putJson('/api/admin/pace-requests/'.$elsewhere->id, ['quantity' => 1])->assertNotFound();
        $this->postJson('/api/admin/pace-requests/'.$elsewhere->id.'/give')->assertNotFound();

        $this->putJson('/api/admin/pace-requests/'.$request->id, ['quantity' => 8, 'needed_by' => '2026-11-15'])
            ->assertOk()
            ->assertJsonPath('data.quantity', 8)
            ->assertJsonPath('data.needed_by', '2026-11-15')
            ->assertJsonPath('data.pace_id', $pace->id);

        $this->postJson('/api/admin/pace-requests/'.$request->id.'/give', ['decision_note' => 'Handed over on Monday'])
            ->assertOk()
            ->assertJsonPath('data.status', 'given')
            ->assertJsonPath('data.quantity', 8)
            ->assertJsonPath('data.decision_note', 'Handed over on Monday')
            ->assertJsonPath('data.decided_by.id', $admin->id)
            ->assertJsonPath('data.pace.pool_stock', 4);

        // The booklets moved from the pool to the teacher, on the ledger.
        $this->assertDatabaseHas('paces', ['id' => $pace->id, 'pool_stock' => 4]);
        $this->assertDatabaseHas('pace_stock_holdings', ['pace_id' => $pace->id, 'user_id' => $teacher->id, 'quantity' => 8]);
        $this->assertDatabaseHas('pace_stock_movements', [
            'pace_id' => $pace->id,
            'type' => 'distributed',
            'quantity' => 8,
            'to_user_id' => $teacher->id,
            'performed_by' => $admin->id,
            'pace_request_id' => $request->id,
        ]);

        // Deciding twice is refused, and the pending count drops.
        $this->postJson('/api/admin/pace-requests/'.$request->id.'/give')->assertUnprocessable();
        $this->putJson('/api/admin/pace-requests/'.$request->id, ['quantity' => 1])->assertUnprocessable();
        $this->getJson('/api/user')->assertJsonPath('data.organization.pending_pace_requests_count', 0);

        // The teacher sees the outcome and their new booklets.
        $this->forgetGuards();
        $this->signIn($teacher);

        $this->getJson('/api/pace-requests')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'given')
            ->assertJsonPath('data.0.decision_note', 'Handed over on Monday')
            ->assertJsonPath('data.0.decided_by.name', $admin->name);

        $this->getJson('/api/paces/'.$pace->id)
            ->assertOk()
            ->assertJsonPath('data.pool_stock', 4)
            ->assertJsonPath('data.my_stock', 8)
            ->assertJsonPath('data.pending_requests_count', 0);
    }

    public function test_an_admin_gives_several_requests_at_once_oldest_first_and_the_pool_decides(): void
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $ann = $this->teacher($organization, ['name' => 'Ann']);
        $bob = $this->teacher($organization, ['name' => 'Bob']);
        $cy = $this->teacher($organization, ['name' => 'Cy']);
        $math = $this->pace(['number' => '1001', 'name' => 'Math', 'pool_stock' => 10], $ann);
        $science = $this->pace(['number' => '1002', 'name' => 'Science', 'pool_stock' => 50], $ann);

        // Oldest first: Ann 6 of Math, Bob 6 of Math (the pool only covers one of them), Cy 5 of Science,
        // a free-form PACE that is not in the catalog, and one that was already decided.
        $first = PaceRequest::factory()->forPace($math, $ann)->create(['quantity' => 6, 'created_at' => now()->subDays(3)]);
        $second = PaceRequest::factory()->forPace($math, $bob)->create(['quantity' => 6, 'created_at' => now()->subDays(2)]);
        $third = PaceRequest::factory()->forPace($science, $cy)->create(['quantity' => 5, 'created_at' => now()->subDay()]);
        $free = PaceRequest::factory()->create(['user_id' => $ann->id, 'pace_number' => '1700', 'pace_name' => 'Art', 'quantity' => 2]);
        $decided = PaceRequest::factory()->forPace($science, $ann)->create(['quantity' => 1, 'status' => 'declined']);

        $this->signIn($admin);

        // The ids are listed newest first on purpose: the order of processing is by age, not by the list.
        $response = $this->postJson('/api/admin/pace-requests/batch', [
            'action' => 'give',
            'ids' => [$decided->id, $free->id, $third->id, $second->id, $first->id],
            'decision_note' => 'Handed over on Friday',
        ])->assertOk();

        $this->assertEqualsCanonicalizing([$first->id, $third->id], array_column($response->json('data.done'), 'id'));

        $skipped = collect($response->json('data.skipped'))->keyBy('id');
        $this->assertEqualsCanonicalizing([$second->id, $free->id, $decided->id], $skipped->keys()->all());
        $this->assertStringContainsString('Only 4 of PACE 1001 Math', $skipped[$second->id]['reason']);
        $this->assertStringContainsString('not in the catalog yet', $skipped[$free->id]['reason']);
        $this->assertStringContainsString('already been decided', $skipped[$decided->id]['reason']);
        $this->assertSame('Bob', $skipped[$second->id]['teacher_name']);

        // The two that fit were given with their quantity and the note; the pool and the teachers' stock moved.
        $this->assertDatabaseHas('pace_requests', ['id' => $first->id, 'status' => 'given', 'decision_note' => 'Handed over on Friday', 'decided_by' => $admin->id]);
        $this->assertDatabaseHas('pace_requests', ['id' => $third->id, 'status' => 'given']);
        $this->assertDatabaseHas('paces', ['id' => $math->id, 'pool_stock' => 4]);
        $this->assertDatabaseHas('paces', ['id' => $science->id, 'pool_stock' => 45]);
        $this->assertDatabaseHas('pace_stock_holdings', ['pace_id' => $math->id, 'user_id' => $ann->id, 'quantity' => 6]);
        $this->assertDatabaseHas('pace_stock_holdings', ['pace_id' => $science->id, 'user_id' => $cy->id, 'quantity' => 5]);
        $this->assertDatabaseMissing('pace_stock_holdings', ['pace_id' => $math->id, 'user_id' => $bob->id]);

        // The ones that were skipped are untouched (and the pending count says so).
        $this->assertDatabaseHas('pace_requests', ['id' => $second->id, 'status' => 'pending']);
        $this->assertDatabaseHas('pace_requests', ['id' => $free->id, 'status' => 'pending']);
        $this->assertDatabaseMissing('paces', ['number' => '1700']);
        $this->getJson('/api/user')->assertJsonPath('data.organization.pending_pace_requests_count', 2);

        // One ledger row per request that went through.
        $this->assertSame(2, \App\Models\PaceStockMovement::query()->where('type', 'distributed')->whereIn('pace_request_id', [$first->id, $second->id, $third->id])->count());
    }

    public function test_an_admin_declines_several_requests_at_once_with_one_note(): void
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $teacher = $this->teacher($organization);
        $pace = $this->pace(['number' => '1001', 'name' => 'Math', 'pool_stock' => 10], $teacher);

        $one = PaceRequest::factory()->forPace($pace, $teacher)->create(['quantity' => 3]);
        $two = PaceRequest::factory()->forPace($pace, $teacher)->create(['quantity' => 4]);
        $given = PaceRequest::factory()->forPace($pace, $teacher)->create(['quantity' => 1, 'status' => 'given']);

        $this->signIn($admin);

        $response = $this->postJson('/api/admin/pace-requests/batch', [
            'action' => 'decline',
            'ids' => [$one->id, $two->id, $given->id],
            'decision_note' => 'Order again after the next delivery',
        ])->assertOk();

        $this->assertEqualsCanonicalizing([$one->id, $two->id], array_column($response->json('data.done'), 'id'));
        $this->assertSame([$given->id], array_column($response->json('data.skipped'), 'id'));

        foreach ([$one, $two] as $declined) {
            $this->assertDatabaseHas('pace_requests', ['id' => $declined->id, 'status' => 'declined', 'decision_note' => 'Order again after the next delivery', 'decided_by' => $admin->id]);
        }

        // Declining moves no stock, and a decided request stays as it was.
        $this->assertDatabaseHas('paces', ['id' => $pace->id, 'pool_stock' => 10]);
        $this->assertDatabaseHas('pace_requests', ['id' => $given->id, 'status' => 'given']);
        $this->getJson('/api/user')->assertJsonPath('data.organization.pending_pace_requests_count', 0);
    }

    public function test_a_batch_is_validated_scoped_and_for_admins_only(): void
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $teacher = $this->teacher($organization);
        $pace = $this->pace(['number' => '1001', 'name' => 'Math', 'pool_stock' => 10], $teacher);
        $mine = PaceRequest::factory()->forPace($pace, $teacher)->create();
        $elsewhere = PaceRequest::factory()->create();

        $this->signIn($admin);

        $this->postJson('/api/admin/pace-requests/batch', ['action' => 'give', 'ids' => []])
            ->assertUnprocessable()->assertJsonValidationErrors(['ids']);
        $this->postJson('/api/admin/pace-requests/batch', ['action' => 'explode', 'ids' => [$mine->id]])
            ->assertUnprocessable()->assertJsonValidationErrors(['action']);
        $this->postJson('/api/admin/pace-requests/batch', ['action' => 'give', 'ids' => [$mine->id, $mine->id]])
            ->assertUnprocessable()->assertJsonValidationErrors(['ids.1']);

        // Another school's request (or an id that does not exist) makes the whole batch "not found": nothing is decided.
        $this->postJson('/api/admin/pace-requests/batch', ['action' => 'give', 'ids' => [$mine->id, $elsewhere->id]])->assertNotFound();
        $this->postJson('/api/admin/pace-requests/batch', ['action' => 'decline', 'ids' => [$mine->id, 999999]])->assertNotFound();
        $this->assertDatabaseHas('pace_requests', ['id' => $mine->id, 'status' => 'pending']);
        $this->assertDatabaseHas('pace_requests', ['id' => $elsewhere->id, 'status' => 'pending']);

        // Teachers cannot decide requests.
        $this->forgetGuards();
        $this->signIn($teacher);
        $this->postJson('/api/admin/pace-requests/batch', ['action' => 'decline', 'ids' => [$mine->id]])->assertForbidden();
        $this->assertDatabaseHas('pace_requests', ['id' => $mine->id, 'status' => 'pending']);
    }

    public function test_the_quantity_handed_over_may_differ_from_the_quantity_asked_but_never_exceeds_the_pool(): void
    {
        $organization = $this->organization();
        $teacher = $this->teacher($organization);
        $pace = $this->pace(['pool_stock' => 5], $teacher);
        $request = PaceRequest::factory()->forPace($pace, $teacher)->create(['quantity' => 10]);

        $this->signIn($this->admin($organization));

        $this->postJson('/api/admin/pace-requests/'.$request->id.'/give', ['quantity' => 4])
            ->assertOk()
            ->assertJsonPath('data.quantity', 4)
            ->assertJsonPath('data.pace.pool_stock', 1);

        $this->assertDatabaseHas('paces', ['id' => $pace->id, 'pool_stock' => 1]);
        $this->assertDatabaseHas('pace_stock_holdings', ['pace_id' => $pace->id, 'user_id' => $teacher->id, 'quantity' => 4]);

        // The pool is short: nothing is handed over and the request stays open.
        $second = PaceRequest::factory()->forPace($pace, $teacher)->create(['quantity' => 3]);

        $this->postJson('/api/admin/pace-requests/'.$second->id.'/give')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity']);

        $this->assertDatabaseHas('pace_requests', ['id' => $second->id, 'status' => 'pending']);
        $this->assertDatabaseHas('paces', ['id' => $pace->id, 'pool_stock' => 1]);
    }

    public function test_giving_a_request_for_a_new_pace_registers_it_in_the_catalog(): void
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $teacher = $this->teacher($organization);
        $request = PaceRequest::factory()->create([
            'user_id' => $teacher->id,
            'organization_id' => $organization->id,
            'pace_number' => '1050',
            'pace_name' => 'Science',
            'quantity' => 3,
        ]);

        $this->signIn($admin);

        // Nothing has arrived for it yet: there is nothing to hand over, and nothing changes.
        $this->postJson('/api/admin/pace-requests/'.$request->id.'/give')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity']);

        $this->assertDatabaseMissing('paces', ['number' => '1050', 'name' => 'Science']);
        $this->assertDatabaseHas('pace_requests', ['id' => $request->id, 'status' => 'pending']);

        // Registering the PACE for now, with no booklets.
        $this->postJson('/api/admin/pace-requests/'.$request->id.'/give', ['quantity' => 0, 'decision_note' => 'Ordered; in the catalog now'])
            ->assertOk()
            ->assertJsonPath('data.status', 'given')
            ->assertJsonPath('data.quantity', 0)
            ->assertJsonPath('data.pace.number', '1050')
            ->assertJsonPath('data.pace.name', 'Science')
            ->assertJsonPath('data.pace.pool_stock', 0);

        $this->assertDatabaseHas('paces', ['number' => '1050', 'name' => 'Science', 'branch_id' => $teacher->branch_id, 'created_by' => $admin->id, 'pool_stock' => 0]);
        $this->assertDatabaseCount('pace_stock_movements', 0);

        // Or registering it with the delivery first, then handing the booklets over.
        $another = PaceRequest::factory()->create([
            'user_id' => $teacher->id,
            'organization_id' => $organization->id,
            'pace_number' => '1051',
            'pace_name' => 'Science',
            'quantity' => 2,
        ]);

        $this->postJson('/api/admin/paces', ['branch_id' => $teacher->branch_id, 'number' => '1051', 'name' => 'Science', 'pool_stock' => 2])->assertCreated();

        $this->postJson('/api/admin/pace-requests/'.$another->id.'/give')
            ->assertOk()
            ->assertJsonPath('data.status', 'given')
            ->assertJsonPath('data.pace.number', '1051')
            ->assertJsonPath('data.pace.pool_stock', 0);

        $this->assertDatabaseCount('paces', 2);
        $this->assertDatabaseHas('pace_stock_holdings', ['pace_id' => $another->fresh()->pace_id, 'user_id' => $teacher->id, 'quantity' => 2]);
    }

    public function test_renaming_a_request_detaches_it_from_the_catalog_pace(): void
    {
        $organization = $this->organization();
        $teacher = $this->teacher($organization);
        $pace = $this->pace(['number' => '1001', 'name' => 'Math', 'pool_stock' => 2], $teacher);
        $request = PaceRequest::factory()->forPace($pace, $teacher)->create(['quantity' => 3]);

        $this->signIn($this->admin($organization));

        $this->putJson('/api/admin/pace-requests/'.$request->id, ['pace_name' => 'Math (new edition)'])
            ->assertOk()
            ->assertJsonPath('data.pace_id', null)
            ->assertJsonPath('data.pace_name', 'Math (new edition)');

        // It now names a new PACE: giving registers that one; the original's pool is untouched.
        $this->postJson('/api/admin/pace-requests/'.$request->id.'/give', ['quantity' => 0])
            ->assertOk()
            ->assertJsonPath('data.status', 'given')
            ->assertJsonPath('data.pace.name', 'Math (new edition)');

        $this->assertNotSame($pace->id, $request->fresh()->pace_id);
        $this->assertDatabaseHas('paces', ['id' => $pace->id, 'pool_stock' => 2]);
        $this->assertDatabaseHas('paces', ['number' => '1001', 'name' => 'Math (new edition)', 'branch_id' => $teacher->branch_id]);
    }

    public function test_an_admin_can_decline_a_request_with_a_note(): void
    {
        $organization = $this->organization();
        $teacher = $this->teacher($organization);
        $pace = $this->pace(['pool_stock' => 2], $teacher);
        $request = PaceRequest::factory()->forPace($pace, $teacher)->create();

        $this->signIn($this->admin($organization));

        $this->postJson('/api/admin/pace-requests/'.$request->id.'/decline', ['decision_note' => 'No budget until next month'])
            ->assertOk()
            ->assertJsonPath('data.status', 'declined')
            ->assertJsonPath('data.decision_note', 'No budget until next month');

        $this->assertDatabaseHas('paces', ['id' => $pace->id, 'pool_stock' => 2]);

        $this->postJson('/api/admin/pace-requests/'.$request->id.'/decline')->assertUnprocessable();

        $this->forgetGuards();
        $this->signIn($teacher);

        $this->getJson('/api/pace-requests')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'declined')
            ->assertJsonPath('data.0.decision_note', 'No budget until next month');
    }

    public function test_an_admin_can_filter_requests_by_branch(): void
    {
        $organization = $this->organization();
        $main = $organization->branches()->where('is_main', true)->firstOrFail();
        $campus = Branch::factory()->create(['organization_id' => $organization->id]);

        $mainTeacher = User::factory()->inOrganization($organization, $main)->create();
        $campusTeacher = User::factory()->inOrganization($organization, $campus)->create();
        $fromMain = PaceRequest::factory()->create(['user_id' => $mainTeacher->id, 'organization_id' => $organization->id]);
        $fromCampus = PaceRequest::factory()->create(['user_id' => $campusTeacher->id, 'organization_id' => $organization->id]);

        $this->signIn($this->admin($organization));

        $this->getJson('/api/admin/pace-requests')->assertOk()->assertJsonCount(2, 'data');

        $this->getJson('/api/admin/pace-requests?branch_id='.$campus->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $fromCampus->id)
            ->assertJsonPath('data.0.teacher.branch.id', $campus->id);

        $this->getJson('/api/admin/pace-requests?branch_id='.$main->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $fromMain->id);
    }

    public function test_an_admin_can_search_requests_by_teacher_or_pace(): void
    {
        $organization = $this->organization();
        $tim = $this->teacher($organization, ['name' => 'Tim Santos', 'email' => 'tim@school.test']);
        $ana = $this->teacher($organization, ['name' => 'Ana Reyes', 'email' => 'ana@school.test']);

        $math = PaceRequest::factory()->create(['user_id' => $tim->id, 'organization_id' => $organization->id, 'pace_number' => '1042', 'pace_name' => 'Word Building']);
        $science = PaceRequest::factory()->create(['user_id' => $ana->id, 'organization_id' => $organization->id, 'pace_number' => '3001', 'pace_name' => 'Science Basics']);

        $this->signIn($this->admin($organization));

        $ids = fn (string $query): array => array_column($this->getJson('/api/admin/pace-requests'.$query)->assertOk()->json('data'), 'id');

        $this->assertEqualsCanonicalizing([$math->id, $science->id], $ids(''));

        // The teacher's name or email ...
        $this->assertSame([$math->id], $ids('?search=santos'));
        $this->assertSame([$science->id], $ids('?search=ana@school'));

        // ... the PACE's number or name ...
        $this->assertSame([$math->id], $ids('?search=042'));
        $this->assertSame([$science->id], $ids('?search=basics'));

        // ... and it combines with the other filters.
        $this->assertSame([], $ids('?search=santos&teacher_id='.$ana->id));
        $this->assertSame([], $ids('?search=nothing-like-this'));

        // Another school's teacher with the same name never shows up.
        $elsewhere = PaceRequest::factory()->create(['user_id' => User::factory()->create(['name' => 'Tim Santos'])->id]);
        $this->assertNotContains($elsewhere->id, $ids('?search=santos'));
    }

    public function test_an_admin_can_filter_requests_by_the_needed_by_date(): void
    {
        $organization = $this->organization();
        $teacher = $this->teacher($organization);
        $make = fn (?string $date) => PaceRequest::factory()->create(['user_id' => $teacher->id, 'organization_id' => $organization->id, 'needed_by' => $date]);

        $october = $make('2026-10-05');
        $november = $make('2026-11-15');
        $december = $make('2026-12-24');
        $open = $make(null);

        $this->signIn($this->admin($organization));

        $ids = fn (string $query): array => array_column($this->getJson('/api/admin/pace-requests'.$query)->assertOk()->json('data'), 'id');

        $this->assertCount(4, $ids(''));

        // Inclusive on both ends; requests without a needed-by date drop out once a range is given.
        $this->assertEqualsCanonicalizing([$november->id, $december->id], $ids('?needed_from=2026-11-15'));
        $this->assertEqualsCanonicalizing([$october->id, $november->id], $ids('?needed_to=2026-11-15'));
        $this->assertSame([$november->id], $ids('?needed_from=2026-11-15&needed_to=2026-11-15'));
        $this->assertSame([], $ids('?needed_from=2027-01-01'));

        // A reversed range is put in order.
        $this->assertEqualsCanonicalizing([$october->id, $november->id], $ids('?needed_from=2026-11-30&needed_to=2026-10-01'));

        $this->getJson('/api/admin/pace-requests?needed_from=soon')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['needed_from']);
    }

    public function test_platform_staff_see_every_organizations_requests_or_one_at_a_time(): void
    {
        $sunrise = $this->organization();
        $hilltop = $this->organization();
        $mine = PaceRequest::factory()->create(['user_id' => $this->teacher($sunrise)->id, 'organization_id' => $sunrise->id]);
        PaceRequest::factory()->create(['user_id' => $this->teacher($hilltop)->id, 'organization_id' => $hilltop->id]);

        $this->signIn($this->platformOwner());

        $this->getJson('/api/admin/pace-requests')->assertOk()->assertJsonCount(2, 'data');

        $this->getJson('/api/admin/pace-requests?organization_id='.$sunrise->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.organization.id', $sunrise->id);

        $this->postJson('/api/admin/pace-requests/'.$mine->id.'/decline')->assertOk()->assertJsonPath('data.status', 'declined');
    }

    public function test_roles_are_enforced_on_pace_requests(): void
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $teacher = $this->teacher($organization);
        $request = PaceRequest::factory()->create(['user_id' => $teacher->id, 'organization_id' => $organization->id]);

        $this->getJson('/api/pace-requests')->assertUnauthorized();
        $this->getJson('/api/admin/pace-requests')->assertUnauthorized();

        $this->signIn($admin);
        $this->getJson('/api/pace-requests')->assertForbidden();
        $this->postJson('/api/pace-requests', ['pace_number' => '1', 'pace_name' => 'X', 'quantity' => 1])->assertForbidden();

        $this->forgetGuards();
        $this->signIn($teacher);
        $this->getJson('/api/admin/pace-requests')->assertForbidden();
        $this->postJson('/api/admin/pace-requests/'.$request->id.'/give')->assertForbidden();

        $this->assertDatabaseHas('pace_requests', ['id' => $request->id, 'status' => 'pending']);
    }
}
