<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // The reset link must open the SPA's reset page, not a Laravel route.
        // APP_URL carries the SPA's base path (e.g. /pacesmonitoring).
        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            return rtrim(config('app.url'), '/').'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ]);
        });

        // Same idea for the verification link: the SPA page receives the signed
        // parameters and replays them against the (relative-signed) API route.
        VerifyEmail::createUrlUsing(function (User $user): string {
            $id = $user->getKey();
            $hash = sha1($user->getEmailForVerification());

            $signedApiUrl = URL::temporarySignedRoute(
                'verification.verify',
                now()->addMinutes(config('auth.verification.expire', 60)),
                ['id' => $id, 'hash' => $hash],
                absolute: false,
            );

            return rtrim(config('app.url'), '/').'/verify-email?'
                .http_build_query(['id' => $id, 'hash' => $hash])
                .'&'.parse_url($signedApiUrl, PHP_URL_QUERY);
        });
    }
}
