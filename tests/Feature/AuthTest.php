<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthTest extends ApiTestCase
{
    public function test_a_visitor_can_register_and_is_logged_in(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Jane Teacher',
            'email' => 'jane@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'role' => 'teacher',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'name', 'email', 'created_at']])
            ->assertJsonPath('data.name', 'Jane Teacher')
            ->assertJsonPath('data.email', 'jane@example.com')
            ->assertJsonMissingPath('data.password');

        $this->assertDatabaseHas('users', ['email' => 'jane@example.com']);
        $this->assertTrue(Hash::check('secret-password', User::query()->firstOrFail()->password));
        $this->assertAuthenticated('web');

        $this->forgetGuards();

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.email', 'jane@example.com');
    }

    public function test_registration_validates_input(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/register', [
            'name' => '',
            'email' => 'taken@example.com',
            'password' => 'short',
            'password_confirmation' => 'different',
            'role' => 'teacher',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password']);

        $this->assertGuest('web');
    }

    public function test_a_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/login', [
            'email' => 'user@example.com',
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', 'user@example.com');

        $this->assertAuthenticatedAs($user, 'web');

        $this->forgetGuards();

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_login_with_wrong_password_returns_validation_error(): void
    {
        User::factory()->create([
            'email' => 'user@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/login', [
            'email' => 'user@example.com',
            'password' => 'wrong-password',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'These credentials do not match our records.');

        $this->assertGuest('web');
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->postJson('/api/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_an_authenticated_user_can_fetch_their_profile(): void
    {
        $user = $this->signIn();

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJson([
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => 'teacher',
                    'is_owner' => false,
                    'is_admin' => false,
                    'is_teacher' => true,
                    'organization_id' => $user->organization_id,
                    'branch_id' => $user->branch_id,
                    'created_at' => $user->created_at->toISOString(),
                ],
            ])
            ->assertJsonPath('data.organization.id', $user->organization_id)
            ->assertJsonPath('data.organization.subscription.allows_writes', true)
            ->assertJsonPath('data.branch.id', $user->branch_id)
            ->assertJsonMissingPath('data.password');
    }

    public function test_a_user_can_logout(): void
    {
        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/login', [
            'email' => 'user@example.com',
            'password' => 'password123',
        ])->assertOk();

        $this->assertAuthenticatedAs($user, 'web');

        $this->postJson('/api/logout')->assertNoContent();

        $this->assertGuest('web');

        $this->forgetGuards();

        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_a_user_can_update_their_name_and_email(): void
    {
        $user = $this->signIn();

        $this->putJson('/api/profile', ['name' => 'New Name', 'email' => 'new-email@example.com'])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.email', 'new-email@example.com');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'New Name',
            'email' => 'new-email@example.com',
        ]);
    }

    public function test_profile_update_is_validated(): void
    {
        $this->signIn();
        $other = User::factory()->create(['email' => 'taken@example.com']);

        $this->putJson('/api/profile', ['name' => '', 'email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email']);

        $this->putJson('/api/profile', ['name' => 'Someone', 'email' => $other->email])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_a_user_can_keep_their_own_email_when_updating_their_profile(): void
    {
        $user = $this->signIn(User::factory()->create(['email' => 'me@example.com']));

        $this->putJson('/api/profile', ['name' => 'Updated Name', 'email' => 'me@example.com'])
            ->assertOk()
            ->assertJsonPath('data.email', 'me@example.com');
    }

    public function test_a_user_can_change_their_password(): void
    {
        $user = $this->signIn(User::factory()->create(['password' => Hash::make('old-password')]));

        $this->putJson('/api/password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertNoContent();

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    public function test_changing_password_requires_the_correct_current_password(): void
    {
        $this->signIn(User::factory()->create(['password' => Hash::make('old-password')]));

        $this->putJson('/api/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);
    }

    public function test_changing_password_is_validated(): void
    {
        $this->signIn(User::factory()->create(['password' => Hash::make('old-password')]));

        $this->putJson('/api/password', [
            'current_password' => 'old-password',
            'password' => 'short',
            'password_confirmation' => 'different',
            'role' => 'teacher',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_guests_cannot_access_protected_endpoints(): void
    {
        $this->getJson('/api/user')
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.']);

        $this->getJson('/api/students')->assertUnauthorized();
        $this->getJson('/api/paces')->assertUnauthorized();
        $this->getJson('/api/grade-levels')->assertUnauthorized();
        $this->getJson('/api/subjects')->assertUnauthorized();
        $this->getJson('/api/dashboard/summary')->assertUnauthorized();
        $this->postJson('/api/logout')->assertUnauthorized();
        $this->putJson('/api/profile', ['name' => 'X', 'email' => 'x@example.com'])->assertUnauthorized();
        $this->putJson('/api/password', ['current_password' => 'x', 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertUnauthorized();
    }

    public function test_unknown_api_routes_return_json_404(): void
    {
        $this->get('/api/does-not-exist', ['Accept' => 'text/html'])
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }
}
