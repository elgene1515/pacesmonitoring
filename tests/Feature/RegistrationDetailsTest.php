<?php

namespace Tests\Feature;

use App\Models\User;

/**
 * Sign-up asks for the name in parts, a contact number, an optional address
 * and the acceptance of the Terms of Service and Privacy Policy; My account
 * edits the same details.
 */
class RegistrationDetailsTest extends ApiTestCase
{
    private function signUp(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/register', $overrides + [
            'first_name' => 'Juan',
            'middle_name' => 'Santos',
            'last_name' => 'Dela Cruz',
            'suffix' => 'Jr.',
            'phone' => '+63 917 123 4567',
            'address' => '12 Hill St, Quezon City',
            'email' => 'juan@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'role' => 'teacher',
            'terms' => true,
        ]);
    }

    public function test_sign_up_stores_the_name_parts_contact_details_and_the_acceptance(): void
    {
        $this->signUp()
            ->assertCreated()
            // The full name is composed from the parts, so every list and greeting keeps working.
            ->assertJsonPath('data.name', 'Juan Santos Dela Cruz Jr.')
            ->assertJsonPath('data.first_name', 'Juan')
            ->assertJsonPath('data.middle_name', 'Santos')
            ->assertJsonPath('data.last_name', 'Dela Cruz')
            ->assertJsonPath('data.suffix', 'Jr.')
            ->assertJsonPath('data.phone', '+63 917 123 4567')
            ->assertJsonPath('data.address', '12 Hill St, Quezon City')
            ->assertJsonPath('data.terms_version', config('saas.terms_version'));

        $user = User::query()->where('email', 'juan@example.com')->firstOrFail();

        $this->assertSame('Juan Santos Dela Cruz Jr.', $user->name);
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertSame(config('saas.terms_version'), $user->terms_version);
    }

    public function test_only_the_first_name_surname_and_contact_number_are_required(): void
    {
        $this->signUp(['middle_name' => '', 'suffix' => null, 'address' => '   '])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Juan Dela Cruz')
            ->assertJsonPath('data.middle_name', null)
            ->assertJsonPath('data.suffix', null)
            ->assertJsonPath('data.address', null);
    }

    public function test_the_required_parts_are_validated(): void
    {
        $this->signUp(['first_name' => '', 'last_name' => '', 'phone' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name', 'last_name', 'phone']);

        $this->signUp(['phone' => 'call me'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);

        $this->signUp(['suffix' => str_repeat('x', 21)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['suffix']);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_the_terms_and_privacy_policy_must_be_accepted(): void
    {
        $this->postJson('/api/register', [
            'first_name' => 'Juan',
            'last_name' => 'Cruz',
            'phone' => '0917 123 4567',
            'email' => 'juan@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'role' => 'teacher',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['terms']);

        $this->signUp(['terms' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['terms'])
            ->assertJsonPath('errors.terms.0', 'Please accept the Terms of Service and Privacy Policy to create an account.');

        $this->assertDatabaseCount('users', 0);
        $this->assertGuest('web');
    }

    public function test_my_account_edits_the_name_parts_and_contact_details(): void
    {
        $this->signUp()->assertCreated();
        $this->forgetGuards();

        $this->putJson('/api/profile', [
            'first_name' => 'Juanito',
            'middle_name' => null,
            'last_name' => 'Cruz',
            'suffix' => null,
            'phone' => '0918 765 4321',
            'address' => null,
            'email' => 'juan@example.com',
            'current_password' => 'secret-password',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Juanito Cruz')
            ->assertJsonPath('data.middle_name', null)
            ->assertJsonPath('data.phone', '0918 765 4321')
            ->assertJsonPath('data.address', null);

        // The acceptance is a record of sign-up and is not rewritten by editing.
        $user = User::query()->where('email', 'juan@example.com')->firstOrFail();
        $this->assertSame(config('saas.terms_version'), $user->terms_version);

        $this->putJson('/api/profile', ['first_name' => '', 'last_name' => '', 'phone' => '', 'email' => 'juan@example.com', 'current_password' => 'secret-password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name', 'last_name', 'phone']);
    }

    public function test_accounts_made_before_the_parts_existed_still_work(): void
    {
        // An older account has only a full name: the resource shows null parts and the name as it was.
        $user = User::factory()->create(['name' => 'Ada Lovelace']);

        $this->signIn($user);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.name', 'Ada Lovelace')
            ->assertJsonPath('data.first_name', null)
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.terms_accepted_at', null);
    }
}
