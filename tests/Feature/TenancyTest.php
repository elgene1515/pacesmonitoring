<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Models\JoinRequest;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The SaaS layer: sign-up roles, organization setup, join requests,
 * subscriptions and the owner's management endpoints.
 */
class TenancyTest extends ApiTestCase
{
    /* ------------------------------------------------------------ sign-up */

    public function test_an_admin_signs_up_and_registers_an_organization_with_branches(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Ada Admin',
            'email' => 'ada@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'role' => 'admin',
        ])
            ->assertCreated()
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.is_admin', true)
            ->assertJsonPath('data.organization_id', null);

        $this->forgetGuards();

        // The admin cannot use the admin area until their organization exists.
        $this->getJson('/api/admin/dashboard/summary')
            ->assertForbidden()
            ->assertJsonPath('code', 'no_organization');

        // Branches are given by name; the admin flags the main one and the school takes its address.
        $this->postJson('/api/organizations', ['name' => 'Sunrise Christian School', 'branches' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branches']);

        $response = $this->postJson('/api/organizations', [
            'name' => 'Sunrise Christian School',
            'branches' => [
                ['name' => 'North Annex', 'address' => '5 North Rd'],
                ['name' => 'Main Campus', 'address' => '12 Hill St', 'is_main' => true],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.organization.name', 'Sunrise Christian School')
            ->assertJsonPath('data.organization.address', '12 Hill St')
            ->assertJsonPath('data.organization.branches_count', 2)
            ->assertJsonPath('data.organization.subscription.status', 'trial')
            ->assertJsonPath('data.organization.subscription.allows_writes', true)
            ->assertJsonPath('data.branch.name', 'Main Campus');

        $code = $response->json('data.organization.code');
        $this->assertMatchesRegularExpression('/^SCH-[A-Z2-9]{6}$/', $code);

        $organization = Organization::query()->where('code', $code)->firstOrFail();
        $this->assertTrue($organization->branches()->where('name', 'Main Campus')->where('is_main', true)->where('address', '12 Hill St')->exists());
        $this->assertTrue($organization->branches()->where('name', 'North Annex')->where('is_main', false)->exists());
        $this->assertSame('starter', $organization->currentSubscription->plan->slug);

        // Registering twice is refused.
        $this->postJson('/api/organizations', ['name' => 'Another'])->assertForbidden();
    }

    public function test_the_only_branch_is_the_main_one_even_when_not_flagged(): void
    {
        $this->signIn(User::factory()->admin()->unassigned()->create());

        $this->postJson('/api/organizations', ['name' => 'Tiny School', 'branches' => [['name' => 'Downtown']]])
            ->assertCreated()
            ->assertJsonPath('data.organization.branches_count', 1)
            ->assertJsonPath('data.branch.name', 'Downtown')
            ->assertJsonPath('data.branch.code', 'DOWNTOWN')
            ->assertJsonPath('data.branch.is_main', true);
    }

    public function test_sign_up_requires_a_registrable_role(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Someone',
            'email' => 'someone@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);

        $this->postJson('/api/register', [
            'name' => 'Someone',
            'email' => 'someone@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'role' => 'owner',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);
    }

    /* ------------------------------------------------------ join requests */

    public function test_a_teacher_looks_up_an_organization_and_asks_to_join_a_branch(): void
    {
        $organization = $this->organization(['name' => 'Sunrise School']);
        $main = $organization->branches()->where('is_main', true)->firstOrFail();

        $this->postJson('/api/register', [
            'name' => 'Tim Teacher',
            'email' => 'tim@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'role' => 'teacher',
        ])
            ->assertCreated()
            ->assertJsonPath('data.role', 'teacher')
            ->assertJsonPath('data.organization_id', null)
            ->assertJsonPath('data.join_request', null);

        $this->forgetGuards();

        // No organization yet: the teacher area is closed.
        $this->getJson('/api/students')->assertForbidden()->assertJsonPath('code', 'no_organization');

        $this->getJson('/api/organizations/lookup?code=nope')->assertUnprocessable();

        $this->getJson('/api/organizations/lookup?code='.strtolower($organization->code))
            ->assertOk()
            ->assertJsonPath('data.name', 'Sunrise School')
            ->assertJsonPath('data.branches.0.id', $main->id)
            ->assertJsonMissingPath('data.teachers');

        $this->postJson('/api/join-requests', [
            'organization_code' => $organization->code,
            'branch_id' => $main->id,
            'message' => 'Grade 3 teacher',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.organization.name', 'Sunrise School')
            ->assertJsonPath('data.branch.id', $main->id);

        // Only one pending request at a time.
        $this->postJson('/api/join-requests', ['organization_code' => $organization->code, 'branch_id' => $main->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['organization_code']);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.join_request.status', 'pending')
            ->assertJsonPath('data.join_request.organization.code', $organization->code);
    }

    public function test_a_branch_must_belong_to_the_organization_being_joined(): void
    {
        $organization = $this->organization();
        $other = $this->organization();
        $foreignBranch = $other->branches()->firstOrFail();

        $this->signIn(User::factory()->unassigned()->create());

        $this->postJson('/api/join-requests', ['organization_code' => $organization->code, 'branch_id' => $foreignBranch->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch_id']);
    }

    public function test_a_teacher_can_cancel_their_pending_request(): void
    {
        $teacher = User::factory()->unassigned()->create();
        $request = JoinRequest::factory()->create(['user_id' => $teacher->id]);
        $someoneElses = JoinRequest::factory()->create();

        $this->signIn($teacher);

        $this->deleteJson('/api/join-requests/'.$someoneElses->id)->assertNotFound();
        $this->deleteJson('/api/join-requests/'.$request->id)->assertNoContent();

        $this->assertDatabaseHas('join_requests', ['id' => $request->id, 'status' => 'cancelled']);
    }

    public function test_an_admin_sees_and_accepts_join_requests_for_their_organization(): void
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $teacher = User::factory()->unassigned()->create(['name' => 'Tim Teacher']);
        $request = JoinRequest::factory()->create(['user_id' => $teacher->id, 'organization_id' => $organization->id]);
        $elsewhere = JoinRequest::factory()->create();

        $this->signIn($admin);

        $this->getJson('/api/admin/join-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $request->id)
            ->assertJsonPath('data.0.user.name', 'Tim Teacher');

        $this->postJson('/api/admin/join-requests/'.$elsewhere->id.'/accept')->assertNotFound();

        $this->postJson('/api/admin/join-requests/'.$request->id.'/accept')
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.decided_by.id', $admin->id);

        $this->assertDatabaseHas('users', [
            'id' => $teacher->id,
            'organization_id' => $organization->id,
            'branch_id' => $request->branch_id,
            'role' => 'teacher',
        ]);

        // Deciding twice is refused.
        $this->postJson('/api/admin/join-requests/'.$request->id.'/accept')->assertUnprocessable();

        // The new member now has the teacher area, and the admin lists them.
        $this->forgetGuards();
        $this->signIn($teacher->refresh());
        $this->getJson('/api/students')->assertOk();
        $this->getJson('/api/user')->assertJsonPath('data.organization.id', $organization->id);

        $this->forgetGuards();
        $this->signIn($admin);
        $ids = array_column($this->getJson('/api/admin/users')->assertOk()->json('data'), 'id');
        $this->assertContains($teacher->id, $ids);
    }

    public function test_an_admin_can_decline_a_join_request_with_a_reason(): void
    {
        $organization = $this->organization();
        $teacher = User::factory()->unassigned()->create();
        $request = JoinRequest::factory()->create(['user_id' => $teacher->id, 'organization_id' => $organization->id]);

        $this->signIn($this->admin($organization));

        $this->postJson('/api/admin/join-requests/'.$request->id.'/decline', ['reason' => 'Not on staff'])
            ->assertOk()
            ->assertJsonPath('data.status', 'declined')
            ->assertJsonPath('data.decline_reason', 'Not on staff');

        $this->assertDatabaseHas('users', ['id' => $teacher->id, 'organization_id' => null]);

        // The teacher sees the decision and may ask again.
        $this->forgetGuards();
        $this->signIn($teacher);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.join_request.status', 'declined')
            ->assertJsonPath('data.join_request.decline_reason', 'Not on staff');

        $this->postJson('/api/join-requests', [
            'organization_code' => $organization->code,
            'branch_id' => $organization->branches()->first()->id,
        ])->assertCreated();
    }

    public function test_the_teacher_limit_of_the_plan_is_enforced_when_admitting(): void
    {
        $organization = $this->organization();
        $organization->currentSubscription->plan->update(['max_teachers' => 1]);
        $this->teacher($organization);
        $request = JoinRequest::factory()->create(['organization_id' => $organization->id]);

        $this->signIn($this->admin($organization));

        $this->postJson('/api/admin/join-requests/'.$request->id.'/accept')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['request']);
    }

    /* ----------------------------------------------------------- branches */

    public function test_an_admin_manages_branches_within_the_plan_limit(): void
    {
        $organization = $this->organization();
        $admin = $this->signIn($this->admin($organization));
        $plan = $organization->currentSubscription->plan;
        $plan->update(['max_branches' => 2]);

        $this->getJson('/api/admin/organization')
            ->assertOk()
            ->assertJsonPath('data.id', $organization->id)
            ->assertJsonPath('data.branches_count', 1)
            ->assertJsonPath('data.subscription.plan.max_branches', 2);

        $north = $this->postJson('/api/admin/branches', ['name' => 'North Campus'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'North Campus')
            ->assertJsonPath('data.code', 'NORTH-CAMPUS')
            ->assertJsonPath('data.is_main', false);

        $this->postJson('/api/admin/branches', ['name' => 'One too many'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $northId = $north->json('data.id');

        $this->putJson('/api/admin/branches/'.$northId, ['name' => 'North Annex', 'is_main' => true])
            ->assertOk()
            ->assertJsonPath('data.name', 'North Annex')
            ->assertJsonPath('data.is_main', true);

        $this->assertSame(1, $organization->branches()->where('is_main', true)->count());

        // The main branch cannot be removed; the old main (now secondary) still has the admin.
        $this->deleteJson('/api/admin/branches/'.$northId)->assertUnprocessable();

        $oldMain = $organization->branches()->where('id', '!=', $northId)->firstOrFail();
        $this->deleteJson('/api/admin/branches/'.$oldMain->id)->assertUnprocessable();

        $this->putJson('/api/admin/users/'.$admin->id.'/branch', ['branch_id' => $northId])
            ->assertOk()
            ->assertJsonPath('data.branch_id', $northId);

        $this->deleteJson('/api/admin/branches/'.$oldMain->id)->assertNoContent();
        $this->assertDatabaseMissing('branches', ['id' => $oldMain->id]);

        // Another organization's branch is out of reach.
        $foreign = $this->organization()->branches()->firstOrFail();
        $this->putJson('/api/admin/branches/'.$foreign->id, ['name' => 'Hijack'])->assertNotFound();
    }

    public function test_an_admin_can_update_their_organization_and_remove_a_teacher(): void
    {
        $organization = $this->organization();
        $admin = $this->signIn($this->admin($organization));
        $teacher = $this->teacher($organization);
        $stranger = User::factory()->create();

        // Changing the school's details is confirmed with the admin's own password.
        $this->putJson('/api/admin/organization', ['name' => 'Renamed School'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['admin_password']);

        $this->putJson('/api/admin/organization', ['name' => 'Renamed School', 'contact_email' => 'office@example.com', 'admin_password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed School')
            ->assertJsonPath('data.contact_email', 'office@example.com');

        $this->getJson('/api/admin/users/'.$stranger->id)->assertNotFound();
        $this->deleteJson('/api/admin/users/'.$admin->id.'/membership')->assertUnprocessable();

        $this->deleteJson('/api/admin/users/'.$teacher->id.'/membership')->assertNoContent();
        $this->assertDatabaseHas('users', ['id' => $teacher->id, 'organization_id' => null, 'branch_id' => null]);
    }

    /* -------------------------------------------------- data isolation */

    public function test_an_admin_only_sees_their_own_organizations_data(): void
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);
        $mine = $this->teacher($organization);
        $stranger = User::factory()->create();

        $myStudent = $this->student([], $mine);
        $theirStudent = $this->student([], $stranger);
        $this->pace([], $mine);
        $theirPace = $this->pace([], $stranger);

        $this->signIn($admin);

        $this->getJson('/api/admin/students')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $myStudent->id);

        $this->getJson('/api/admin/students/'.$theirStudent->id)->assertNotFound();
        $this->getJson('/api/admin/paces/'.$theirPace->id)->assertNotFound();
        $this->getJson('/api/admin/paces')->assertOk()->assertJsonCount(1, 'data');

        $users = $this->getJson('/api/admin/users')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$admin->id, $mine->id], array_column($users, 'id'));

        $this->getJson('/api/admin/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.organization_id', $organization->id)
            ->assertJsonPath('data.students', 1)
            ->assertJsonPath('data.totals.teachers', 1)
            ->assertJsonPath('data.totals.admins', 1)
            ->assertJsonPath('data.totals.students', 1);

        $this->getJson('/api/admin/dashboard/summary?teacher_id='.$stranger->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['teacher_id']);

        $this->getJson('/api/admin/grade-levels?teacher_id='.$stranger->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['teacher_id']);
    }

    public function test_the_admin_dashboard_can_be_narrowed_to_a_branch(): void
    {
        $organization = $this->organization();
        $main = $organization->branches()->firstOrFail();
        $north = \App\Models\Branch::factory()->create(['organization_id' => $organization->id, 'name' => 'North']);
        $admin = $this->admin($organization);
        $teacherA = User::factory()->inOrganization($organization, $main)->create();
        $teacherB = User::factory()->inOrganization($organization, $north)->create();

        $this->student([], $teacherA);
        $this->student([], $teacherB);
        $this->student([], $teacherB);

        $this->signIn($admin);

        $this->getJson('/api/admin/dashboard/summary?branch_id='.$north->id)
            ->assertOk()
            ->assertJsonPath('data.branch_id', $north->id)
            ->assertJsonPath('data.students', 2)
            ->assertJsonPath('data.totals.students', 3);

        $this->getJson('/api/admin/users?branch_id='.$north->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $teacherB->id);

        $this->getJson('/api/admin/branches')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_the_teacher_area_is_closed_to_admins_and_owners(): void
    {
        $this->signIn($this->admin());

        $this->getJson('/api/students')->assertForbidden();
        $this->getJson('/api/dashboard/summary')->assertForbidden();

        $this->forgetGuards();
        $this->signIn($this->platformOwner());

        $this->getJson('/api/students')->assertForbidden();
    }

    /* ------------------------------------------------------ subscriptions */

    public function test_a_lapsed_subscription_makes_the_organization_read_only(): void
    {
        $organization = $this->organization();
        $teacher = $this->teacher($organization);
        $admin = $this->admin($organization);
        $pace = $this->pace([], $teacher);

        $organization->currentSubscription->update(['ends_at' => Carbon::yesterday()->toDateString()]);

        $this->signIn($teacher);

        $this->getJson('/api/paces')->assertOk()->assertJsonCount(1, 'data');

        $this->postJson('/api/paces', ['name' => 'Math', 'number' => '1001'])
            ->assertForbidden()
            ->assertJsonPath('code', 'subscription_inactive');

        $this->putJson('/api/paces/'.$pace->id, ['name' => 'Renamed', 'number' => $pace->number])->assertForbidden();
        $this->deleteJson('/api/paces/'.$pace->id)->assertForbidden();
        $this->assertDatabaseHas('paces', ['id' => $pace->id, 'name' => $pace->name]);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.organization.subscription.effective_status', 'expired')
            ->assertJsonPath('data.organization.subscription.is_read_only', true);

        // The admin is read-only too, but can still look.
        $this->forgetGuards();
        $this->signIn($admin);

        $this->getJson('/api/admin/dashboard/summary')->assertOk();
        $this->postJson('/api/admin/branches', ['name' => 'New'])->assertForbidden();
    }

    public function test_a_past_due_subscription_still_allows_changes(): void
    {
        $organization = $this->organization();
        $organization->currentSubscription->update(['status' => SubscriptionStatus::PastDue]);
        $this->signIn($this->teacher($organization));

        $this->postJson('/api/paces', ['name' => 'Math', 'number' => '1001'])->assertCreated();
    }

    /* -------------------------------------------------------------- owner */

    public function test_non_owners_cannot_use_the_owner_area(): void
    {
        $this->signIn($this->admin());

        $this->getJson('/api/owner/dashboard/summary')->assertForbidden();
        $this->getJson('/api/owner/organizations')->assertForbidden();
        $this->getJson('/api/owner/plans')->assertForbidden();

        $this->forgetGuards();
        $this->signIn();

        $this->getJson('/api/owner/organizations')->assertForbidden();
    }

    public function test_the_owner_sees_every_organization_and_the_platform_summary(): void
    {
        $sunrise = $this->organization(['name' => 'Sunrise School']);
        $hilltop = $this->organization(['name' => 'Hilltop Academy']);
        $hilltop->currentSubscription->update(['ends_at' => Carbon::yesterday()->toDateString()]);
        $this->admin($sunrise);
        $this->teacher($sunrise);
        $this->teacher($hilltop);
        JoinRequest::factory()->create(['organization_id' => $sunrise->id]);

        $this->signIn($this->platformOwner());

        $this->getJson('/api/owner/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.totals.organizations', 2)
            ->assertJsonPath('data.totals.admins', 1)
            // Two members plus the teacher account still waiting on its join request.
            ->assertJsonPath('data.totals.teachers', 3)
            ->assertJsonPath('data.totals.unassigned_users', 1)
            ->assertJsonPath('data.totals.pending_join_requests', 1)
            ->assertJsonPath('data.subscriptions_by_status.active', 1)
            ->assertJsonPath('data.subscriptions_by_status.expired', 1)
            ->assertJsonCount(2, 'data.recent_organizations')
            ->assertJsonCount(1, 'data.pending_join_requests');

        $this->getJson('/api/owner/organizations')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Hilltop Academy')
            ->assertJsonPath('data.0.subscription.effective_status', 'expired')
            ->assertJsonPath('data.1.name', 'Sunrise School')
            ->assertJsonPath('data.1.teachers_count', 1)
            ->assertJsonPath('data.1.admins_count', 1)
            ->assertJsonPath('data.1.pending_join_requests_count', 1);

        $this->getJson('/api/owner/organizations?status=expired')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $hilltop->id);

        $this->getJson('/api/owner/organizations?search=sunrise')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/owner/organizations/'.$sunrise->id)
            ->assertOk()
            ->assertJsonPath('data.id', $sunrise->id)
            ->assertJsonCount(1, 'data.branches')
            ->assertJsonCount(1, 'data.subscriptions')
            ->assertJsonCount(2, 'members')
            ->assertJsonCount(1, 'join_requests');

        // Editing a school's details is confirmed with the owner's own password.
        $this->putJson('/api/owner/organizations/'.$sunrise->id, ['name' => 'Sunrise Christian School'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['admin_password']);

        $this->putJson('/api/owner/organizations/'.$sunrise->id, ['name' => 'Sunrise Christian School', 'admin_password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Sunrise Christian School');
    }

    public function test_the_owner_assigns_and_renews_subscriptions(): void
    {
        $organization = $this->organization();
        $organization->currentSubscription->update(['ends_at' => Carbon::yesterday()->toDateString()]);
        $growth = Plan::query()->where('slug', 'growth')->firstOrFail();
        $teacher = $this->teacher($organization);

        $this->signIn($this->platformOwner());

        $this->postJson('/api/owner/organizations/'.$organization->id.'/subscriptions', [
            'plan_id' => $growth->id,
            'status' => 'active',
            'starts_at' => Carbon::today()->toDateString(),
            'ends_at' => Carbon::today()->subDay()->toDateString(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ends_at']);

        $created = $this->postJson('/api/owner/organizations/'.$organization->id.'/subscriptions', [
            'plan_id' => $growth->id,
            'status' => 'active',
            'starts_at' => Carbon::today()->toDateString(),
            'ends_at' => Carbon::today()->addYear()->toDateString(),
            'notes' => 'Founding-client rate',
        ])
            ->assertCreated()
            ->assertJsonPath('data.plan.slug', 'growth')
            ->assertJsonPath('data.effective_status', 'active')
            ->assertJsonPath('data.allows_writes', true);

        $this->getJson('/api/owner/organizations/'.$organization->id.'/subscriptions')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $created->json('data.id'));

        $this->putJson('/api/owner/subscriptions/'.$created->json('data.id'), ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.effective_status', 'cancelled')
            ->assertJsonPath('data.is_read_only', true);

        $this->putJson('/api/owner/subscriptions/'.$created->json('data.id'), ['status' => 'active'])->assertOk();

        // The renewed organization can write again.
        $this->forgetGuards();
        $this->signIn($teacher);
        $this->postJson('/api/paces', ['name' => 'Math', 'number' => '1001'])->assertCreated();
    }

    public function test_the_owner_manages_plans(): void
    {
        $this->signIn($this->platformOwner());

        $this->getJson('/api/owner/plans')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.slug', 'starter')
            ->assertJsonPath('data.0.max_branches', 1)
            ->assertJsonPath('data.2.slug', 'multi-campus');

        $created = $this->postJson('/api/owner/plans', [
            'name' => 'Enterprise',
            'max_branches' => 10,
            'max_teachers' => 100,
            'price_per_year' => 90000,
        ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'enterprise')
            ->assertJsonPath('data.max_teachers', 100)
            ->assertJsonPath('data.is_active', true);

        $this->putJson('/api/owner/plans/'.$created->json('data.id'), ['is_active' => false, 'price_per_year' => 95000])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.price_per_year', 95000);

        $this->postJson('/api/owner/plans', ['name' => '', 'max_branches' => 0, 'price_per_year' => -1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'max_branches', 'price_per_year']);
    }

    public function test_the_owner_can_promote_a_teacher_to_admin_and_scope_the_admin_area(): void
    {
        $organization = $this->organization();
        $teacher = $this->teacher($organization);
        $other = $this->organization();
        $this->teacher($other);
        $this->student([], $teacher);

        $this->signIn($this->platformOwner());

        $this->putJson('/api/owner/users/'.$teacher->id.'/role', ['role' => 'admin'])
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');

        $this->putJson('/api/owner/users/'.$teacher->id.'/role', ['role' => 'owner'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);

        // Unscoped, the owner sees every organization; scoped, one of them.
        $this->getJson('/api/admin/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.organization_id', null)
            ->assertJsonPath('data.totals.organizations', 2)
            ->assertJsonPath('data.totals.students', 1);

        $this->getJson('/api/admin/dashboard/summary?organization_id='.$other->id)
            ->assertOk()
            ->assertJsonPath('data.organization_id', $other->id)
            ->assertJsonPath('data.totals.organizations', 1)
            ->assertJsonPath('data.totals.students', 0);

        $this->getJson('/api/admin/users?organization_id='.$organization->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $teacher->id);

        $this->getJson('/api/admin/organization?organization_id='.$organization->id)
            ->assertOk()
            ->assertJsonPath('data.id', $organization->id);

        $this->getJson('/api/admin/organization')->assertNotFound();
    }

    public function test_the_make_owner_command_promotes_a_user(): void
    {
        $user = User::factory()->create(['email' => 'boss@example.com']);

        $this->artisan('user:make-owner', ['email' => 'boss@example.com'])->assertSuccessful();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'role' => 'owner', 'organization_id' => null]);

        $this->artisan('user:make-owner', ['email' => 'missing@example.com'])->assertFailed();
    }
}
