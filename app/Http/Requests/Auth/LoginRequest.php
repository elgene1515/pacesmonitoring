<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * How many failed attempts (per email + IP) before locking out.
     */
    protected const MAX_ATTEMPTS = 5;

    /**
     * How many seconds a lockout lasts once triggered.
     */
    protected const DECAY_SECONDS = 60;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials against the web guard.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $credentials = $this->only('email', 'password');

        if (! Auth::guard('web')->attempt($credentials, $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey(), static::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'email' => [trans('auth.failed')],
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        $this->session()->regenerate();
    }

    /**
     * Abort with a 429 if this email + IP has failed too many times recently.
     * The body carries `retry_after` (seconds) so the SPA can count down and
     * keep the form locked until the lockout ends.
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), static::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw new HttpResponseException(response()->json([
            'message' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
            'retry_after' => $seconds,
        ], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => $seconds]));
    }

    /**
     * The rate limiter key: email + IP, so one attacker can't lock out a
     * victim's account by spamming failed attempts, and one IP trying many
     * emails isn't throttled off a single key either.
     */
    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
