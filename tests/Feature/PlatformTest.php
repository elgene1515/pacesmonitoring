<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanRequest;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Platform management on top of the tenancy layer: deactivating schools,
 * plan requests, plan limits and the owner's staff.
 */
class PlatformTest extends ApiTestCase
{
    /* ------------------------------------------------ deactivation */

    public function test_the_owner_deactivates_and_restores_an_organization(): void
    {
        $organization = $this->organization(['name' => 'Closing School']);
        $teacher = $this->teacher($organization);
        $this->student([], $teacher);

        $this->signIn($this->platformOwner());

        // The password is required, and a vice admin may not do this at all.
        $this->deleteJson('/api/owner/organizations/'.$organization->id, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['admin_password']);

        $this->deleteJson('/api/owner/organizations/'.$organization->id, ['admin_password' => 'password'])->assertNoContent();

        $this->assertSoftDeleted('organizations', ['id' => $organization->id]);
        $this->assertDatabaseHas('students', ['created_by' => $teacher->id]);

        // Gone from the default list, still reachable with ?trashed=1 and on its own page.
        $this->getJson('/api/owner/organizations')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/owner/organizations?trashed=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_deleted', true);
        $this->getJson('/api/owner/organizations/'.$organization->id)
            ->assertOk()
            ->assertJsonPath('data.is_deleted', true);

        // Teachers cannot join it any more, and its members are locked out.
        $this->getJson('/api/organizations/lookup?code='.$organization->code)->assertUnprocessable();

        $this->forgetGuards();
        $this->signIn($teacher);

        $this->getJson('/api/students')
            ->assertForbidden()
            ->assertJsonPath('code', 'organization_inactive');

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.organization_suspended', true)
            ->assertJsonPath('data.organization', null);

        // Restoring brings everything back.
        $this->forgetGuards();
        $this->signIn($this->platformOwner());

        $this->postJson('/api/owner/organizations/'.$organization->id.'/restore')
            ->assertOk()
            ->assertJsonPath('data.is_deleted', false);

        $this->forgetGuards();
        $this->signIn($teacher->fresh());
        $this->getJson('/api/students')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_vice_admin_cannot_deactivate_organizations_manage_staff_or_delete_plans(): void
    {
        $organization = $this->organization();
        $plan = Plan::factory()->create();

        $this->signIn(User::factory()->owner()->create(['role' => 'vice_admin']));

        // The platform view is open to a vice admin...
        $this->getJson('/api/owner/dashboard/summary')->assertOk();
        $this->getJson('/api/owner/organizations/'.$organization->id)->assertOk();
        $this->getJson('/api/admin/dashboard/summary')->assertOk();
        $this->getJson('/api/owner/plans')->assertOk();

        // ...but the owner-only actions are not.
        $this->deleteJson('/api/owner/organizations/'.$organization->id, ['admin_password' => 'password'])->assertForbidden();
        $this->postJson('/api/owner/organizations/'.$organization->id.'/restore')->assertForbidden();
        $this->deleteJson('/api/owner/plans/'.$plan->id, ['admin_password' => 'password'])->assertForbidden();
        $this->getJson('/api/owner/staff')->assertForbidden();
        $this->postJson('/api/owner/staff', [])->assertForbidden();
    }

    /* ------------------------------------------------------ staff */

    public function test_the_owner_manages_platform_staff(): void
    {
        $owner = $this->signIn($this->platformOwner());

        $this->getJson('/api/owner/staff')->assertOk()->assertJsonCount(1, 'data');

        $created = $this->postJson('/api/owner/staff', [
            'name' => 'Vera Vice',
            'email' => 'vera@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'role' => 'vice_admin',
        ])
            ->assertCreated()
            ->assertJsonPath('data.role', 'vice_admin')
            ->assertJsonPath('data.is_platform', true)
            ->assertJsonPath('data.is_owner', false)
            ->assertJsonPath('data.organization_id', null);

        $viceId = $created->json('data.id');

        $this->postJson('/api/owner/staff', ['name' => 'X', 'email' => 'x@example.com', 'password' => 'secret-password', 'password_confirmation' => 'secret-password', 'role' => 'admin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);

        $this->getJson('/api/owner/staff')->assertOk()->assertJsonCount(2, 'data');

        // Promote to owner, demote back; the last owner can never be demoted or removed.
        $this->putJson('/api/owner/staff/'.$viceId.'/role', ['role' => 'owner'])->assertOk()->assertJsonPath('data.role', 'owner');
        $this->putJson('/api/owner/staff/'.$owner->id.'/role', ['role' => 'vice_admin'])->assertOk();
        $this->putJson('/api/owner/staff/'.$viceId.'/role', ['role' => 'vice_admin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);

        $this->putJson('/api/owner/staff/'.$owner->id.'/role', ['role' => 'owner'])->assertOk();
        $this->deleteJson('/api/owner/staff/'.$owner->id)->assertUnprocessable();
        $this->deleteJson('/api/owner/staff/'.$viceId)->assertNoContent();
        $this->assertDatabaseMissing('users', ['id' => $viceId]);

        // A school member is not staff.
        $teacher = User::factory()->create();
        $this->putJson('/api/owner/staff/'.$teacher->id.'/role', ['role' => 'vice_admin'])->assertNotFound();

        // Platform roles are never handed out through the member role switch.
        $this->putJson('/api/owner/users/'.$teacher->id.'/role', ['role' => 'vice_admin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);
    }

    /* ------------------------------------------------------ plans */

    public function test_plans_can_cap_students_and_be_deleted_when_unused(): void
    {
        $organization = $this->organization();
        $organization->currentSubscription->plan->update(['max_students' => 2]);
        $teacherA = $this->teacher($organization);
        $teacherB = $this->teacher($organization);
        $this->student([], $teacherA);
        $this->student([], $teacherB);

        $this->signIn($teacherA);
        $grade = $this->gradeLevel('Grade 1', $teacherA);

        $this->postJson('/api/students', [
            'first_name' => 'One',
            'last_name' => 'Too Many',
            'birth_date' => '2015-01-01',
            'age' => 11,
            'sex' => 'female',
            'grade_level_id' => $grade->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['students']);

        $this->forgetGuards();
        $owner = $this->signIn($this->platformOwner());
        $this->getJson('/api/user')->assertJsonPath('data.id', $owner->id)->assertJsonPath('data.role', 'owner');
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('password', $owner->password));

        $this->getJson('/api/plans')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.max_students', 2);

        $unused = $this->postJson('/api/owner/plans', ['name' => 'Temporary', 'max_branches' => 1, 'max_students' => 50, 'price_per_year' => 1])
            ->assertCreated()
            ->assertJsonPath('data.max_students', 50);

        $this->deleteJson('/api/owner/plans/'.$unused->json('data.id'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['admin_password']);
        $this->deleteJson('/api/owner/plans/'.$unused->json('data.id'), ['admin_password' => 'password'])->assertNoContent();
        $this->assertDatabaseMissing('plans', ['id' => $unused->json('data.id')]);

        // A plan with subscriptions on record (or the trial default) can only be retired.
        $starter = Plan::query()->where('slug', 'starter')->firstOrFail();
        $this->deleteJson('/api/owner/plans/'.$starter->id, ['admin_password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['plan']);
    }

    /* ---------------------------------------------- plan requests */

    public function test_a_school_can_pick_a_paid_plan_at_sign_up_which_becomes_a_request(): void
    {
        $growth = Plan::query()->where('slug', 'growth')->firstOrFail();

        $this->signIn(User::factory()->admin()->unassigned()->create());

        $this->getJson('/api/plans')->assertOk()->assertJsonCount(3, 'data');

        $this->postJson('/api/organizations', ['name' => 'Growing School', 'branches' => [['name' => 'Main']], 'plan_id' => $growth->id])
            ->assertCreated()
            ->assertJsonPath('data.organization.subscription.status', 'trial')
            ->assertJsonPath('data.organization.pending_plan_requests_count', 1);

        $this->assertDatabaseHas('plan_requests', ['plan_id' => $growth->id, 'action' => 'change', 'status' => 'pending']);

        $this->getJson('/api/admin/plan-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.plan.slug', 'growth')
            ->assertJsonPath('data.0.message', 'Chosen at sign-up.');
    }

    public function test_a_school_admin_requests_a_plan_change_and_the_owner_approves_it(): void
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $multi = Plan::query()->where('slug', 'multi-campus')->firstOrFail();

        $this->signIn($admin);

        $this->postJson('/api/admin/plan-requests', ['action' => 'change'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['plan_id']);

        $request = $this->postJson('/api/admin/plan-requests', ['action' => 'change', 'plan_id' => $multi->id, 'message' => 'Opening two more campuses'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.plan.slug', 'multi-campus')
            ->assertJsonPath('data.requested_by.id', $admin->id);

        // One pending request at a time.
        $this->postJson('/api/admin/plan-requests', ['action' => 'cancel'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['action']);

        $this->getJson('/api/user')->assertJsonPath('data.organization.pending_plan_requests_count', 1);

        $this->forgetGuards();
        $this->signIn($this->platformOwner());

        $this->getJson('/api/owner/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.totals.pending_plan_requests', 1)
            ->assertJsonCount(1, 'data.pending_plan_requests');

        $this->getJson('/api/owner/plan-requests?status=pending')->assertOk()->assertJsonCount(1, 'data');

        $this->postJson('/api/owner/plan-requests/'.$request->json('data.id').'/approve', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at']);

        $this->postJson('/api/owner/plan-requests/'.$request->json('data.id').'/approve', [
            'starts_at' => Carbon::today()->toDateString(),
            'ends_at' => Carbon::today()->addYear()->toDateString(),
            'notes' => 'Invoice 2026-014',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.subscription.plan.slug', 'multi-campus')
            ->assertJsonPath('data.subscription.effective_status', 'active');

        $this->assertSame('multi-campus', $organization->fresh()->currentSubscription->plan->slug);

        // Deciding twice is refused; the school sees the outcome.
        $this->postJson('/api/owner/plan-requests/'.$request->json('data.id').'/decline')->assertUnprocessable();

        $this->forgetGuards();
        $this->signIn($admin);
        $this->getJson('/api/admin/plan-requests')->assertOk()->assertJsonPath('data.0.status', 'approved');
        $this->getJson('/api/admin/organization')->assertJsonPath('data.subscription.plan.slug', 'multi-campus');
    }

    public function test_the_owner_declines_a_request_and_a_school_can_withdraw_or_request_cancellation(): void
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $other = $this->organization();
        $foreign = PlanRequest::factory()->create(['organization_id' => $other->id]);

        $this->signIn($admin);

        $mine = $this->postJson('/api/admin/plan-requests', ['action' => 'cancel', 'message' => 'Closing for the summer'])
            ->assertCreated()
            ->assertJsonPath('data.action', 'cancel')
            ->assertJsonPath('data.plan_id', null);

        // Another school's request is out of reach; withdrawing one's own works.
        $this->deleteJson('/api/admin/plan-requests/'.$foreign->id)->assertNotFound();
        $this->deleteJson('/api/admin/plan-requests/'.$mine->json('data.id'))->assertNoContent();
        $this->assertDatabaseHas('plan_requests', ['id' => $mine->json('data.id'), 'status' => 'cancelled']);

        $again = $this->postJson('/api/admin/plan-requests', ['action' => 'cancel'])->assertCreated();

        $this->forgetGuards();
        $this->signIn($this->platformOwner());

        $this->postJson('/api/owner/plan-requests/'.$again->json('data.id').'/decline', ['decision_note' => 'Call us first'])
            ->assertOk()
            ->assertJsonPath('data.status', 'declined')
            ->assertJsonPath('data.decision_note', 'Call us first');

        // Approving a cancellation marks the current subscription cancelled (read-only).
        $this->postJson('/api/owner/plan-requests/'.$foreign->id.'/approve', [
            'starts_at' => Carbon::today()->toDateString(),
            'ends_at' => Carbon::today()->addYear()->toDateString(),
        ])->assertOk()->assertJsonPath('data.status', 'approved');

        $cancellation = PlanRequest::factory()->cancellation()->create(['organization_id' => $organization->id]);

        $this->postJson('/api/owner/plan-requests/'.$cancellation->id.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.subscription.effective_status', 'cancelled');

        $this->assertFalse($organization->fresh()->allowsWrites());
    }
}
