<?php

namespace Tests\Feature;

use App\Models\JoinRequest;
use App\Models\User;

/**
 * A second (third, ...) administrator for a school that is already registered:
 * an admin-type account joins by the school code and asks for the administrator
 * role; the accepting admin picks the role within the plan's admin limit; and
 * the school's admins switch existing members between admin and teacher.
 */
class JoinAsAdminTest extends ApiTestCase
{
    /* ----------------------------------------------------------- asking */

    public function test_an_admin_account_asks_to_join_a_registered_school_as_an_administrator(): void
    {
        $organization = $this->organization();
        $branch = $organization->branches()->first();

        $this->postJson('/api/register', [
            'first_name' => 'Nora',
            'last_name' => 'Newadmin',
            'phone' => '0917 123 4567',
            'terms' => true,
            'email' => 'nora@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'role' => 'admin',
        ])->assertCreated();

        $this->forgetGuards();

        $this->postJson('/api/join-requests', [
            'organization_code' => $organization->code,
            'branch_id' => $branch->id,
            'message' => 'The principal asked me to help run the office.',
            'role' => 'admin',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.requested_role', 'admin');

        // The pending request is on the user's profile, with the requested role.
        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.organization_id', null)
            ->assertJsonPath('data.join_request.status', 'pending')
            ->assertJsonPath('data.join_request.requested_role', 'admin');

        // Still no admin area: they do not belong to a school yet.
        $this->getJson('/api/admin/dashboard/summary')->assertForbidden();
    }

    public function test_a_teacher_cannot_ask_for_the_administrator_role(): void
    {
        $organization = $this->organization();
        $teacher = User::factory()->unassigned()->create();

        $this->signIn($teacher);

        $this->postJson('/api/join-requests', [
            'organization_code' => $organization->code,
            'branch_id' => $organization->branches()->first()->id,
            'role' => 'admin',
        ])
            ->assertCreated()
            ->assertJsonPath('data.requested_role', 'teacher');
    }

    public function test_registering_a_school_withdraws_a_pending_request_to_join_another(): void
    {
        $organization = $this->organization();
        $admin = User::factory()->admin()->unassigned()->create();
        $request = JoinRequest::factory()->create([
            'user_id' => $admin->id,
            'organization_id' => $organization->id,
            'requested_role' => 'admin',
        ]);

        $this->signIn($admin);

        $this->postJson('/api/organizations', ['name' => 'Second School', 'branches' => [['name' => 'Main', 'is_main' => true]]])->assertCreated();

        $this->assertSame('cancelled', $request->refresh()->status->value);
    }

    /* -------------------------------------------------------- accepting */

    public function test_the_accepting_admin_admits_them_as_an_administrator(): void
    {
        $organization = $this->organization();
        $organization->currentSubscription->plan->update(['max_admins' => 2]);
        $admin = $this->admin($organization);
        $newcomer = User::factory()->admin()->unassigned()->create();
        $request = JoinRequest::factory()->create([
            'user_id' => $newcomer->id,
            'organization_id' => $organization->id,
            'requested_role' => 'admin',
        ]);

        $this->signIn($admin);

        $this->getJson('/api/admin/join-requests')
            ->assertOk()
            ->assertJsonPath('data.0.requested_role', 'admin');

        // Without a body the requested role stands.
        $this->postJson('/api/admin/join-requests/'.$request->id.'/accept')
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        $this->assertDatabaseHas('users', [
            'id' => $newcomer->id,
            'organization_id' => $organization->id,
            'branch_id' => $request->branch_id,
            'role' => 'admin',
        ]);

        // The new administrator has the admin area of that school.
        $this->forgetGuards();
        $this->signIn($newcomer->refresh());
        $this->getJson('/api/admin/dashboard/summary')->assertOk();
        $this->getJson('/api/user')
            ->assertJsonPath('data.organization.id', $organization->id)
            ->assertJsonPath('data.organization.admins_count', 2);
    }

    public function test_the_accepting_admin_may_admit_an_administrator_request_as_a_teacher(): void
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $newcomer = User::factory()->admin()->unassigned()->create();
        $request = JoinRequest::factory()->create([
            'user_id' => $newcomer->id,
            'organization_id' => $organization->id,
            'requested_role' => 'admin',
        ]);

        $this->signIn($admin);

        $this->postJson('/api/admin/join-requests/'.$request->id.'/accept', ['role' => 'teacher'])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        $this->assertDatabaseHas('users', ['id' => $newcomer->id, 'organization_id' => $organization->id, 'role' => 'teacher']);

        $this->postJson('/api/admin/join-requests/'.$request->id.'/accept', ['role' => 'ceo'])->assertUnprocessable();
    }

    public function test_the_admin_limit_of_the_plan_is_enforced_when_admitting_an_administrator(): void
    {
        $organization = $this->organization();
        $organization->currentSubscription->plan->update(['max_admins' => 1]);
        $admin = $this->admin($organization);
        $newcomer = User::factory()->admin()->unassigned()->create();
        $request = JoinRequest::factory()->create([
            'user_id' => $newcomer->id,
            'organization_id' => $organization->id,
            'requested_role' => 'admin',
        ]);

        $this->signIn($admin);

        $this->postJson('/api/admin/join-requests/'.$request->id.'/accept')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['request']);
        $this->postJson('/api/admin/join-requests/'.$request->id.'/accept', ['role' => 'admin'])
            ->assertUnprocessable();

        $this->assertSame('pending', $request->refresh()->status->value);

        // A teacher request cannot be upgraded past the limit either; as a teacher it goes through.
        $this->postJson('/api/admin/join-requests/'.$request->id.'/accept', ['role' => 'teacher'])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $newcomer->id, 'organization_id' => $organization->id, 'role' => 'teacher']);
    }

    /* ----------------------------------------------------- role switch */

    public function test_a_school_admin_switches_members_between_teacher_and_admin_within_the_plan(): void
    {
        $organization = $this->organization();
        $organization->currentSubscription->plan->update(['max_admins' => 2]);
        $admin = $this->admin($organization);
        $teacher = $this->teacher($organization);
        $another = $this->teacher($organization);

        $this->signIn($admin);

        // Promote: now two admins, the limit.
        $this->putJson('/api/admin/users/'.$teacher->id.'/role', ['role' => 'admin'])
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');

        // A third would exceed the plan.
        $this->putJson('/api/admin/users/'.$another->id.'/role', ['role' => 'admin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);

        // Demote the promoted one back; the original admin stays.
        $this->putJson('/api/admin/users/'.$teacher->id.'/role', ['role' => 'teacher'])
            ->assertOk()
            ->assertJsonPath('data.role', 'teacher');

        // Not your own role, and never the last administrator.
        $this->putJson('/api/admin/users/'.$admin->id.'/role', ['role' => 'teacher'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'role' => 'admin']);

        // Only teacher / admin are valid targets.
        $this->putJson('/api/admin/users/'.$teacher->id.'/role', ['role' => 'ceo'])->assertUnprocessable();
    }

    public function test_the_last_administrator_cannot_be_demoted_by_the_platform_either(): void
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);

        $this->signIn($this->platformOwner());

        $this->putJson('/api/owner/users/'.$admin->id.'/role', ['role' => 'teacher'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);
    }

    public function test_the_role_switch_stays_within_the_school_and_away_from_teachers(): void
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $teacher = $this->teacher($organization);
        $elsewhere = $this->teacher($this->organization());

        $this->signIn($admin);
        $this->putJson('/api/admin/users/'.$elsewhere->id.'/role', ['role' => 'admin'])->assertNotFound();

        $this->forgetGuards();
        $this->signIn($teacher);
        $this->putJson('/api/admin/users/'.$admin->id.'/role', ['role' => 'teacher'])->assertForbidden();
    }
}
