<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\SetsLocaleFromRequest;
use App\Services\Auth\EmailVerificationService;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    use SetsLocaleFromRequest;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email'    => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'locale'   => ['nullable', 'string', 'in:en,ar'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->applyRequestLocale();
    }

    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        $user = Auth::user();

        if ($user && ! $user->hasVerifiedEmail()) {
            Auth::logout();

            $this->applyRequestLocale(
                $this->input('locale') ?: $user->language
            );

            $codeSent = app(EmailVerificationService::class)->sendForLogin($user);

            throw ValidationException::withMessages([
                'needs_verification'     => '1',
                'verification_code_sent' => $codeSent ? '1' : '0',
            ]);
        }

        // Covers both the user's own is_active flag and their
        // company's — see User::accessDenialReason(). A deactivated
        // company must not be able to sign in through any of its
        // employees, which the old user-only check allowed.
        if ($user && ($denialReason = $user->accessDenialReason())) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => __($denialReason),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
