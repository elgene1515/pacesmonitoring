<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\JoinRequest;
use App\Models\PaceRequest;
use App\Models\User;

class UnassignedUserTest extends ApiTestCase
{
    public function test_the_owner_lists_accounts_without_an_organization_longest_waiting_first(): void
    {
        $organization = $this->organization();
        $this->teacher($organization);
        $this->admin($organization);

        $newer = User::factory()->unassigned()->create(['name' => 'Newer Nora', 'created_at' => now()->subDays(2)]);
        $older = User::factory()->unassigned()->admin()->create(['name' => 'Older Omar', 'created_at' => now()->subDays(40)]);

        $this->signIn($this->platformOwner());

        $response = $this->getJson('/api/owner/unassigned-users')->assertOk();

        // Organization members and platform accounts are not listed.
        $this->assertSame([$older->id, $newer->id], array_column($response->json('data'), 'id'));

        $response->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.overall', 2)
            ->assertJsonPath('data.0.role', 'admin')
            ->assertJsonPath('data.1.role', 'teacher')
            ->assertJsonPath('data.0.left_organization_at', null)
            ->assertJsonPath('data.0.records.total', 0)
            ->assertJsonStructure(['data' => [['id', 'name', 'email', 'role', 'created_at', 'unassigned_since', 'join_request', 'records']]]);

        $this->assertSame($response->json('data.0.created_at'), $response->json('data.0.unassigned_since'));
    }

    public function test_the_list_can_be_searched_and_limited_to_a_date_range(): void
    {
        $january = User::factory()->unassigned()->create(['name' => 'Jan Jones', 'email' => 'jan@example.com', 'created_at' => '2026-01-15 09:00:00']);
        $march = User::factory()->unassigned()->create(['name' => 'Mara Lopez', 'email' => 'mara@example.com', 'created_at' => '2026-03-20 18:30:00']);
        $june = User::factory()->unassigned()->create(['name' => 'Juno Park', 'email' => 'park@sample.org', 'created_at' => '2026-06-01 00:00:00']);

        $this->signIn($this->platformOwner());

        $ids = fn (string $query): array => array_column($this->getJson('/api/owner/unassigned-users'.$query)->assertOk()->json('data'), 'id');

        $this->assertEqualsCanonicalizing([$january->id, $march->id, $june->id], $ids(''));

        // Name or email.
        $this->assertSame([$march->id], $ids('?search=mara'));
        $this->assertSame([$june->id], $ids('?search=sample.org'));
        $this->assertSame([], $ids('?search=nobody'));

        // The day the account was created, inclusive on both ends.
        $this->assertEqualsCanonicalizing([$march->id, $june->id], $ids('?from=2026-03-20'));
        $this->assertSame([$january->id, $march->id], $ids('?to=2026-03-20'));
        $this->assertSame([$march->id], $ids('?from=2026-03-20&to=2026-03-20'));

        // A reversed range is put in order; filters combine.
        $this->assertSame([$january->id, $march->id], $ids('?from=2026-03-20&to=2026-01-01'));
        $this->assertSame([$march->id], $ids('?from=2026-03-01&to=2026-06-30&search=lopez'));

        $this->getJson('/api/owner/unassigned-users?from=2026-03-20&search=mara')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.overall', 3);

        $this->getJson('/api/owner/unassigned-users?from=yesterday')->assertUnprocessable()->assertJsonValidationErrors(['from']);
    }

    public function test_the_list_can_be_filtered_by_role(): void
    {
        $teacher = User::factory()->unassigned()->create(['name' => 'Tess Teacher', 'created_at' => '2026-02-01 08:00:00']);
        $admin = User::factory()->unassigned()->admin()->create(['name' => 'Adam Admin', 'created_at' => '2026-03-01 08:00:00']);
        $this->platformOwner(['name' => 'Platform Pat']);

        $this->signIn($this->platformOwner());

        $ids = fn (string $query): array => array_column($this->getJson('/api/owner/unassigned-users'.$query)->assertOk()->json('data'), 'id');

        $this->assertSame([$teacher->id, $admin->id], $ids(''));
        $this->assertSame([$teacher->id], $ids('?role=teacher'));
        $this->assertSame([$admin->id], $ids('?role=admin'));

        // Combines with the other filters; the overall count ignores them.
        $this->assertSame([], $ids('?role=admin&search=tess'));
        $this->assertSame([$admin->id], $ids('?role=admin&from=2026-03-01'));

        $this->getJson('/api/owner/unassigned-users?role=admin')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.overall', 2);

        // Platform roles are never in this list, so they are not a valid filter.
        $this->getJson('/api/owner/unassigned-users?role=owner')->assertUnprocessable()->assertJsonValidationErrors(['role']);
        $this->getJson('/api/owner/unassigned-users?role=vice_admin')->assertUnprocessable()->assertJsonValidationErrors(['role']);
    }

    public function test_a_member_removed_from_a_school_is_without_one_since_then(): void
    {
        $organization = $this->organization();
        $teacher = $this->teacher($organization, ['created_at' => now()->subDays(90)]);

        $this->assertNull($teacher->fresh()->left_organization_at);

        // The school removes the teacher.
        $this->signIn($this->admin($organization));
        $this->deleteJson('/api/admin/users/'.$teacher->id.'/membership')->assertNoContent();

        $this->assertNotNull($teacher->fresh()->left_organization_at);

        $this->forgetGuards();
        $this->signIn($this->platformOwner());

        $row = $this->getJson('/api/owner/unassigned-users')->assertOk()->json('data.0');

        $this->assertSame($teacher->id, $row['id']);
        $this->assertNotNull($row['left_organization_at']);
        $this->assertSame($row['left_organization_at'], $row['unassigned_since']);
        $this->assertNotSame($row['created_at'], $row['unassigned_since']);

        // Joining a school again forgets it.
        $returning = $teacher->fresh();
        $returning->organization_id = $organization->id;
        $returning->save();

        $this->assertNull($teacher->fresh()->left_organization_at);
    }

    public function test_the_list_shows_the_latest_join_request(): void
    {
        $organization = $this->organization(['name' => 'Sunrise School']);
        $waiting = User::factory()->unassigned()->create();
        $asked = JoinRequest::factory()->create(['user_id' => $waiting->id, 'organization_id' => $organization->id]);
        $stranger = User::factory()->unassigned()->create(['created_at' => now()->addMinute()]);

        $this->signIn($this->platformOwner());

        $rows = collect($this->getJson('/api/owner/unassigned-users')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame($asked->id, $rows[$waiting->id]['join_request']['id']);
        $this->assertSame('pending', $rows[$waiting->id]['join_request']['status']);
        $this->assertSame('Sunrise School', $rows[$waiting->id]['join_request']['organization_name']);
        $this->assertNull($rows[$stranger->id]['join_request']);
    }

    public function test_an_account_that_owns_records_reports_them(): void
    {
        // Students, grade levels and PACEs belong to the school now; what an account
        // still owns is the school years it registered and its stock requests.
        $teacher = User::factory()->unassigned()->create();
        PaceRequest::factory()->count(2)->create(['user_id' => $teacher->id]);

        $this->signIn($this->platformOwner());

        $this->getJson('/api/owner/unassigned-users')
            ->assertOk()
            ->assertJsonPath('data.0.id', $teacher->id)
            ->assertJsonPath('data.0.records.pace_requests', 2)
            ->assertJsonPath('data.0.records.school_years', 0)
            ->assertJsonPath('data.0.records.total', 2);
    }

    public function test_the_owner_can_delete_an_account_with_their_password(): void
    {
        $stray = User::factory()->unassigned()->create();
        $request = JoinRequest::factory()->create(['user_id' => $stray->id]);
        $stray->createToken('test');

        $this->signIn($this->platformOwner());

        $this->deleteJson('/api/owner/unassigned-users/'.$stray->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['admin_password']);

        $this->deleteJson('/api/owner/unassigned-users/'.$stray->id, ['admin_password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['admin_password']);

        $this->assertDatabaseHas('users', ['id' => $stray->id]);

        $this->deleteJson('/api/owner/unassigned-users/'.$stray->id, ['admin_password' => 'password'])->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $stray->id]);
        $this->assertDatabaseMissing('join_requests', ['id' => $request->id]);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->getJson('/api/owner/unassigned-users')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_an_account_that_still_owns_records_cannot_be_deleted(): void
    {
        $teacher = User::factory()->unassigned()->create();
        PaceRequest::factory()->count(2)->create(['user_id' => $teacher->id]);

        $this->signIn($this->platformOwner());

        $response = $this->deleteJson('/api/owner/unassigned-users/'.$teacher->id, ['admin_password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user']);

        $this->assertStringContainsString('2 pace requests', $response->json('errors.user.0'));
        $this->assertDatabaseHas('users', ['id' => $teacher->id]);
        $this->assertDatabaseCount('pace_requests', 2);
    }

    public function test_only_unassigned_non_platform_accounts_can_be_deleted_here(): void
    {
        $organization = $this->organization();
        $member = $this->teacher($organization);
        $staff = $this->platformOwner();

        $this->signIn($this->platformOwner());

        $this->deleteJson('/api/owner/unassigned-users/'.$member->id, ['admin_password' => 'password'])->assertNotFound();
        $this->deleteJson('/api/owner/unassigned-users/'.$staff->id, ['admin_password' => 'password'])->assertNotFound();

        $this->assertDatabaseHas('users', ['id' => $member->id]);
        $this->assertDatabaseHas('users', ['id' => $staff->id]);
    }

    public function test_vice_admins_may_use_it_but_schools_and_guests_may_not(): void
    {
        $stray = User::factory()->unassigned()->create();

        $this->getJson('/api/owner/unassigned-users')->assertUnauthorized();
        $this->deleteJson('/api/owner/unassigned-users/'.$stray->id)->assertUnauthorized();

        $organization = $this->organization();

        foreach ([$this->teacher($organization), $this->admin($organization)] as $user) {
            $this->forgetGuards();
            $this->signIn($user);

            $this->getJson('/api/owner/unassigned-users')->assertForbidden();
            $this->deleteJson('/api/owner/unassigned-users/'.$stray->id, ['admin_password' => 'password'])->assertForbidden();
        }

        $this->forgetGuards();
        $this->signIn($this->platformOwner(['role' => Role::ViceAdmin]));

        $this->getJson('/api/owner/unassigned-users')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson('/api/owner/unassigned-users/'.$stray->id, ['admin_password' => 'password'])->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $stray->id]);
    }
}
