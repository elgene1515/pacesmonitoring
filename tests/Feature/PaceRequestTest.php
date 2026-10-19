<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\PaceRequest;
use App\Models\User;

class PaceRequestTest extends ApiTestCase
{
    public function test_a_teacher_requests_stock_for_one_of_their_paces(): void
    {
        $organization = $this->organization();
        $teacher = $this->teacher($organization);
        $pace = $this->pace(['number' => '1001', 'name' => 'Math', 'stock' => 0], $teacher);

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
            'user_id' => $teacher->id,
            'pace_id' => $pace->id,
            'quantity' => 10,
            'status' => 'pending',
        ]);

        $this->getJson('/api/pace-requests')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/pace-requests?status=given')->assertOk()->assertJsonCount(0, 'data');

        // The PACE shows the request waiting, and the admin is told through the organization's pending count.
        $this->getJson('/api/paces/'.$pace->id)->assertOk()->assertJsonPath('data.pending_requests_count', 1);

        $this->forgetGuards();
        $this->signIn($this->admin($organization));

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.organization.pending_pace_requests_count', 1);
    }

    public function test_a_request_may_name_a_pace_that_is_not_registered_yet(): void
    {
        $teacher = User::factory()->create();
        $this->signIn($teacher);

        $this->postJson('/api/pace-requests', ['pace_number' => '1050', 'pace_name' => 'Science', 'quantity' => 3])
            ->assertCreated()
            ->assertJsonPath('data.pace_id', null)
            ->assertJsonPath('data.pace', null)
            ->assertJsonPath('data.pace_number', '1050')
            ->assertJsonPath('data.pace_name', 'Science');

        $this->postJson('/api/pace-requests', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pace_number', 'pace_name', 'quantity']);

        $this->postJson('/api/pace-requests', ['pace_number' => 'abc', 'pace_name' => 'Science', 'quantity' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pace_number', 'quantity']);

        $foreign = $this->pace([], User::factory()->create());

        $this->postJson('/api/pace-requests', ['pace_id' => $foreign->id, 'quantity' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pace_id']);

        $this->assertDatabaseCount('pace_requests', 1);
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

        // Linking it to one of their PACEs copies that PACE's number and name.
        $pace = $this->pace(['number' => '1077', 'name' => 'Word Building'], $teacher);

        $this->putJson('/api/pace-requests/'.$request->id, ['pace_id' => $pace->id])
            ->assertOk()
            ->assertJsonPath('data.pace_id', $pace->id)
            ->assertJsonPath('data.pace_number', '1077')
            ->assertJsonPath('data.pace_name', 'Word Building');

        $this->putJson('/api/pace-requests/'.$someoneElses->id, ['quantity' => 1])->assertNotFound();
        $this->deleteJson('/api/pace-requests/'.$someoneElses->id)->assertNotFound();

        $this->deleteJson('/api/pace-requests/'.$request->id)->assertNoContent();
        $this->assertDatabaseHas('pace_requests', ['id' => $request->id, 'status' => 'cancelled']);

        // A decided request is history: no more edits or withdrawals.
        $this->putJson('/api/pace-requests/'.$request->id, ['quantity' => 1])->assertUnprocessable();
        $this->deleteJson('/api/pace-requests/'.$request->id)->assertUnprocessable();
        $this->assertDatabaseHas('pace_requests', ['id' => $someoneElses->id, 'status' => 'pending']);
    }

    public function test_an_admin_lists_edits_and_fulfils_their_teachers_requests(): void
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $teacher = $this->teacher($organization, ['name' => 'Tim Teacher']);
        $pace = $this->pace(['number' => '1001', 'name' => 'Math', 'stock' => 2], $teacher);
        $request = PaceRequest::factory()->forPace($pace)->create(['quantity' => 10]);
        $elsewhere = PaceRequest::factory()->create();

        $this->signIn($admin);

        $this->getJson('/api/admin/pace-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $request->id)
            ->assertJsonPath('data.0.teacher.name', 'Tim Teacher')
            ->assertJsonPath('data.0.pace.stock', 2);

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
            ->assertJsonPath('data.pace.stock', 10);

        $this->assertDatabaseHas('paces', ['id' => $pace->id, 'stock' => 10]);

        // Deciding twice is refused, and the pending count drops.
        $this->postJson('/api/admin/pace-requests/'.$request->id.'/give')->assertUnprocessable();
        $this->putJson('/api/admin/pace-requests/'.$request->id, ['quantity' => 1])->assertUnprocessable();
        $this->getJson('/api/user')->assertJsonPath('data.organization.pending_pace_requests_count', 0);

        // The teacher sees the outcome and the new stock.
        $this->forgetGuards();
        $this->signIn($teacher);

        $this->getJson('/api/pace-requests')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'given')
            ->assertJsonPath('data.0.decision_note', 'Handed over on Monday')
            ->assertJsonPath('data.0.decided_by.name', $admin->name);

        $this->getJson('/api/paces/'.$pace->id)
            ->assertOk()
            ->assertJsonPath('data.stock', 10)
            ->assertJsonPath('data.pending_requests_count', 0);
    }

    public function test_the_quantity_handed_over_may_differ_from_the_quantity_asked(): void
    {
        $organization = $this->organization();
        $teacher = $this->teacher($organization);
        $pace = $this->pace(['stock' => 1], $teacher);
        $request = PaceRequest::factory()->forPace($pace)->create(['quantity' => 10]);

        $this->signIn($this->admin($organization));

        $this->postJson('/api/admin/pace-requests/'.$request->id.'/give', ['quantity' => 4])
            ->assertOk()
            ->assertJsonPath('data.quantity', 4)
            ->assertJsonPath('data.pace.stock', 5);

        $this->assertDatabaseHas('paces', ['id' => $pace->id, 'stock' => 5]);
    }

    public function test_renaming_a_request_detaches_it_from_the_registered_pace(): void
    {
        $organization = $this->organization();
        $teacher = $this->teacher($organization);
        $pace = $this->pace(['number' => '1001', 'name' => 'Math', 'stock' => 2], $teacher);
        $request = PaceRequest::factory()->forPace($pace)->create(['quantity' => 3]);

        $this->signIn($this->admin($organization));

        $this->putJson('/api/admin/pace-requests/'.$request->id, ['pace_name' => 'Math (new edition)'])
            ->assertOk()
            ->assertJsonPath('data.pace_id', null)
            ->assertJsonPath('data.pace_name', 'Math (new edition)');

        // Nothing to top up any more: the stock of the original PACE is untouched.
        $this->postJson('/api/admin/pace-requests/'.$request->id.'/give')
            ->assertOk()
            ->assertJsonPath('data.status', 'given')
            ->assertJsonPath('data.pace', null);

        $this->assertDatabaseHas('paces', ['id' => $pace->id, 'stock' => 2]);
    }

    public function test_an_admin_can_decline_a_request_with_a_note(): void
    {
        $organization = $this->organization();
        $teacher = $this->teacher($organization);
        $pace = $this->pace(['stock' => 2], $teacher);
        $request = PaceRequest::factory()->forPace($pace)->create();

        $this->signIn($this->admin($organization));

        $this->postJson('/api/admin/pace-requests/'.$request->id.'/decline', ['decision_note' => 'No budget until next month'])
            ->assertOk()
            ->assertJsonPath('data.status', 'declined')
            ->assertJsonPath('data.decision_note', 'No budget until next month');

        $this->assertDatabaseHas('paces', ['id' => $pace->id, 'stock' => 2]);

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
