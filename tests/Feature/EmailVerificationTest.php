<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

class EmailVerificationTest extends ApiTestCase
{
    /**
     * The query string the SPA replays against GET /api/email/verify/{id}/{hash}.
     */
    private function signedQuery(User $user, ?string $hash = null): string
    {
        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => $hash ?? sha1($user->email)],
            absolute: false,
        );

        return (string) parse_url($url, PHP_URL_QUERY);
    }

    public function test_registering_sends_a_verification_link_to_the_spa(): void
    {
        Notification::fake();

        $this->postJson('/api/register', [
            'name' => 'Jane Teacher',
            'email' => 'jane@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'role' => 'teacher',
        ])
            ->assertCreated()
            ->assertJsonPath('data.email_verified', false);

        $user = User::query()->where('email', 'jane@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);

        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user): bool {
            $url = $notification->toMail($user)->actionUrl;
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return str_starts_with($url, rtrim(config('app.url'), '/').'/verify-email?')
                && (int) $query['id'] === $user->id
                && $query['hash'] === sha1('jane@example.com')
                && isset($query['expires'], $query['signature']);
        });
    }

    public function test_the_emailed_parameters_verify_the_address_without_a_session(): void
    {
        $user = User::factory()->unverified()->create();

        $this->getJson("/api/email/verify/{$user->id}/".sha1($user->email).'?'.$this->signedQuery($user))
            ->assertOk()
            ->assertJsonPath('message', 'Your email address has been verified.');

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertGuest('web');

        // Opening the link again is harmless.
        $this->getJson("/api/email/verify/{$user->id}/".sha1($user->email).'?'.$this->signedQuery($user))
            ->assertOk()
            ->assertJsonPath('message', 'This email address was already verified.');
    }

    public function test_a_tampered_or_expired_link_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $query = $this->signedQuery($user);

        // Signature does not match the hash in the path.
        $this->getJson("/api/email/verify/{$user->id}/".sha1('other@example.com').'?'.$query)->assertForbidden();

        // Signature matches, but the hash is not this user's email.
        $wrongHash = sha1('other@example.com');
        $this->getJson("/api/email/verify/{$user->id}/{$wrongHash}?".$this->signedQuery($user, $wrongHash))->assertForbidden();

        // Expired.
        $this->travel(61)->minutes();
        $this->getJson("/api/email/verify/{$user->id}/".sha1($user->email).'?'.$query)->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_a_signed_in_user_can_ask_for_a_new_link(): void
    {
        Notification::fake();
        $user = $this->signIn(User::factory()->unverified()->create());

        $this->postJson('/api/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('message', 'A new verification link has been sent to '.$user->email.'.');

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_resending_to_a_verified_address_sends_nothing(): void
    {
        Notification::fake();
        $this->signIn();

        $this->postJson('/api/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('message', 'This email address was already verified.');

        Notification::assertNothingSent();
    }

    public function test_resending_requires_a_session(): void
    {
        $this->postJson('/api/email/verification-notification')->assertUnauthorized();
    }

    public function test_changing_the_email_address_asks_to_verify_it_again(): void
    {
        Notification::fake();
        $user = $this->signIn();

        $this->putJson('/api/profile', ['name' => $user->name, 'email' => 'new-address@example.com', 'current_password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.email_verified', false);

        $this->assertNull($user->fresh()->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);

        // Keeping the same address changes nothing.
        $this->putJson('/api/profile', ['name' => 'Renamed', 'email' => 'new-address@example.com', 'current_password' => 'password'])->assertOk();
        Notification::assertSentToTimes($user, VerifyEmail::class, 1);
    }

    public function test_the_user_payload_reports_verification(): void
    {
        $this->signIn();

        $this->getJson('/api/user')->assertOk()->assertJsonPath('data.email_verified', true);
    }
}
