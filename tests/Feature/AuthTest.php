<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthTest extends ApiTestCase
{
    public function test_a_visitor_can_register_and_is_logged_in(): void
    {
        $response = $this->postJson('/api/register', [
            'first_name' => 'Jane',
            'last_name' => 'Teacher',
            'phone' => '0917 123 4567',
            'terms' => true,
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
            'first_name' => '',
            'last_name' => '',
            'phone' => '0917 123 4567',
            'terms' => true,
            'email' => 'taken@example.com',
            'password' => 'short',
            'password_confirmation' => 'different',
            'role' => 'teacher',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name', 'last_name', 'email', 'password']);

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

    public function test_repeated_failed_logins_are_rate_limited(): void
    {
        User::factory()->create([
            'email' => 'user@example.com',
            'password' => Hash::make('password123'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', [
                'email' => 'user@example.com',
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $response = $this->postJson('/api/login', [
            'email' => 'user@example.com',
            'password' => 'wrong-password',
        ])->assertTooManyRequests();

        $this->assertStringContainsString('Too many login attempts', $response->json('message'));
        $this->assertGreaterThan(0, $response->json('retry_after'));
        $this->assertSame((string) $response->json('retry_after'), $response->headers->get('Retry-After'));

        // The correct password is also blocked while locked out.
        $this->postJson('/api/login', [
            'email' => 'user@example.com',
            'password' => 'password123',
        ])->assertTooManyRequests();

        $this->assertGuest('web');
    }

    public function test_a_successful_login_clears_prior_failed_attempts(): void
    {
        User::factory()->create([
            'email' => 'user@example.com',
            'password' => Hash::make('password123'),
        ]);

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/login', [
                'email' => 'user@example.com',
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/login', [
            'email' => 'user@example.com',
            'password' => 'password123',
        ])->assertOk();

        $this->forgetGuards();
        $this->postJson('/api/logout');

        // One more wrong attempt right after shouldn't be locked out, since
        // the successful login reset the counter.
        $this->postJson('/api/login', [
            'email' => 'user@example.com',
            'password' => 'wrong-password',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
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

        $this->putJson('/api/profile', ['first_name' => 'New', 'last_name' => 'Name', 'phone' => '0917 123 4567', 'email' => 'new-email@example.com', 'current_password' => 'password'])
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

        $this->putJson('/api/profile', ['first_name' => '', 'last_name' => '', 'phone' => '0917 123 4567', 'email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name', 'last_name', 'email', 'current_password']);

        $this->putJson('/api/profile', ['first_name' => 'Someone', 'last_name' => 'Tester', 'phone' => '0917 123 4567', 'email' => $other->email, 'current_password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_updating_the_profile_requires_the_correct_current_password(): void
    {
        $user = $this->signIn(User::factory()->create(['name' => 'Original', 'email' => 'me@example.com']));

        $this->putJson('/api/profile', ['first_name' => 'Hijacked', 'last_name' => 'Tester', 'phone' => '0917 123 4567', 'email' => 'attacker@example.com', 'current_password' => 'wrong-password'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.current_password.0', 'The current password is incorrect.');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Original', 'email' => 'me@example.com']);
    }

    public function test_a_user_can_keep_their_own_email_when_updating_their_profile(): void
    {
        $user = $this->signIn(User::factory()->create(['email' => 'me@example.com']));

        $this->putJson('/api/profile', ['first_name' => 'Updated', 'last_name' => 'Name', 'phone' => '0917 123 4567', 'email' => 'me@example.com', 'current_password' => 'password'])
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
        $this->putJson('/api/profile', ['first_name' => 'X', 'last_name' => 'Tester', 'phone' => '0917 123 4567', 'email' => 'x@example.com'])->assertUnauthorized();
        $this->putJson('/api/password', ['current_password' => 'x', 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertUnauthorized();
    }

    public function test_unknown_api_routes_return_json_404(): void
    {
        $this->get('/api/does-not-exist', ['Accept' => 'text/html'])
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }
}
