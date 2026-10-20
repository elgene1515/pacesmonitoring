<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

class PasswordResetTest extends ApiTestCase
{
    public function test_a_reset_link_is_emailed_to_a_registered_address(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'user@example.com']);

        $this->postJson('/api/forgot-password', ['email' => 'user@example.com'])
            ->assertOk()
            ->assertJsonPath('message', 'If an account exists for that email, a password reset link has been sent to it.');

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $url = $notification->toMail($user)->actionUrl;
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return str_starts_with($url, rtrim(config('app.url'), '/').'/reset-password?')
                && $query['email'] === 'user@example.com'
                && $query['token'] === $notification->token;
        });
    }

    public function test_an_unknown_address_gets_the_same_answer_and_no_email(): void
    {
        Notification::fake();

        $this->postJson('/api/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk()
            ->assertJsonPath('message', 'If an account exists for that email, a password reset link has been sent to it.');

        Notification::assertNothingSent();
    }

    public function test_forgot_password_requires_a_valid_email(): void
    {
        $this->postJson('/api/forgot-password', ['email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_a_valid_token_sets_a_new_password(): void
    {
        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => Hash::make('old-password'),
        ]);
        $token = Password::createToken($user);

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => 'user@example.com',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Your password has been reset.');

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertGuest('web');

        // The token is single-use.
        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => 'user@example.com',
            'password' => 'another-password',
            'password_confirmation' => 'another-password',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'This password reset token is invalid.');
    }

    public function test_an_invalid_token_is_rejected(): void
    {
        User::factory()->create([
            'email' => 'user@example.com',
            'password' => Hash::make('old-password'),
        ]);

        $this->postJson('/api/reset-password', [
            'token' => 'not-a-real-token',
            'email' => 'user@example.com',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'This password reset token is invalid.');

        $this->assertTrue(Hash::check('old-password', User::query()->firstOrFail()->password));
    }

    public function test_reset_password_is_validated(): void
    {
        $this->postJson('/api/reset-password', [
            'token' => '',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token', 'email', 'password']);
    }

    public function test_reset_endpoints_are_rate_limited(): void
    {
        Notification::fake();

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/forgot-password', ['email' => 'user@example.com'])->assertOk();
        }

        $this->postJson('/api/forgot-password', ['email' => 'user@example.com'])->assertTooManyRequests();
    }
}
