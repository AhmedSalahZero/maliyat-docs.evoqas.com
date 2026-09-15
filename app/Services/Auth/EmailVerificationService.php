<?php

namespace App\Services\Auth;

use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Notifications\VerifyEmailCodeNotification;
use App\Support\AuthVerification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class EmailVerificationService
{
    public function issueAndSend(User $user): EmailVerificationCode
    {
        if (! AuthVerification::enabled()) {
            throw new \RuntimeException('Email verification is disabled in configuration.');
        }

        $plainCode = $this->generatePlainCode();

        $record = DB::transaction(function () use ($user, $plainCode) {
            EmailVerificationCode::query()
                ->where('user_id', $user->id)
                ->whereNull('verified_at')
                ->delete();

            return EmailVerificationCode::create([
                'user_id'    => $user->id,
                'code_hash'  => Hash::make($plainCode),
                'expires_at' => now()->addMinutes(config('auth_verification.expires_minutes')),
            ]);
        });

        $this->dispatchVerificationMail($user, $plainCode);

        return $record;
    }

    public function sendForLogin(User $user): bool
    {
        if (! AuthVerification::enabled()) {
            return true;
        }

        $key = 'verify-login-mail:'.$user->id;
        $decaySeconds = config('auth_verification.resend_throttle_seconds');

        if (RateLimiter::tooManyAttempts($key, 1)) {
            return false;
        }

        try {
            $this->issueAndSend($user);
            RateLimiter::hit($key, $decaySeconds);

            return true;
        } catch (\Throwable $e) {
            Log::error('Verification email failed on login', [
                'user_id' => $user->id,
                'email'   => $user->email,
                'error'   => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function verify(User $user, string $code): void
    {
        $record = EmailVerificationCode::query()
            ->where('user_id', $user->id)
            ->whereNull('verified_at')
            ->latest('id')
            ->first();

        if (! $record) {
            throw ValidationException::withMessages([
                'code' => [__('auth.verification_code_invalid')],
            ]);
        }

        if ($record->isExpired()) {
            throw ValidationException::withMessages([
                'code' => [__('auth.verification_code_expired')],
            ]);
        }

        $maxAttempts = config('auth_verification.max_attempts');

        if ($record->attempts >= $maxAttempts) {
            throw ValidationException::withMessages([
                'code' => [__('auth.verification_code_locked')],
            ]);
        }

        if (! Hash::check($code, $record->code_hash)) {
            $record->increment('attempts');

            throw ValidationException::withMessages([
                'code' => [__('auth.verification_code_invalid')],
            ]);
        }

        DB::transaction(function () use ($user, $record) {
            $record->update(['verified_at' => now()]);

            EmailVerificationCode::query()
                ->where('user_id', $user->id)
                ->whereNull('verified_at')
                ->delete();

            if (! $user->hasVerifiedEmail()) {
                $user->forceFill(['email_verified_at' => now()])->save();
                event(new Verified($user));
            }
        });
    }

    public function findUnverifiedUserByEmail(string $email): ?User
    {
        return User::query()
            ->where('email', $email)
            ->whereNull('email_verified_at')
            ->first();
    }

    public function hasActiveCode(User $user): bool
    {
        $record = EmailVerificationCode::query()
            ->where('user_id', $user->id)
            ->whereNull('verified_at')
            ->latest('id')
            ->first();

        if (! $record || $record->isExpired()) {
            return false;
        }

        return $record->attempts < config('auth_verification.max_attempts');
    }

    /**
     * Issue a new code when none is active. Returns true if a new code was sent.
     */
    public function ensureActiveCode(User $user): bool
    {
        if ($this->hasActiveCode($user)) {
            return false;
        }

        $this->issueAndSend($user);

        return true;
    }

    /**
     * Send the code immediately, not through the queue.
     *
     * notifyNow() is deliberate and it is the right call here: the
     * person is sitting on the verification screen waiting for a
     * six-digit number. A queued notification would sit in the jobs
     * table until a worker picked it up, so on any install without a
     * running worker the code would simply never arrive — and the
     * screen would still say it had been sent.
     *
     * The consequence to know: NOTHING about this flow ever touches
     * the queue. An empty jobs table, an empty failed_jobs table and
     * silent worker logs are all correct here, not evidence that
     * sending failed. Where the mail actually went is decided by
     * MAIL_MAILER alone — with the `log` driver it is written to
     * storage/logs/laravel.log rather than delivered anywhere.
     *
     * A failure is rethrown rather than swallowed: the caller has
     * just written a code to the database, and telling somebody a
     * code is on its way when the send threw is worse than showing
     * them an error.
     */
    private function dispatchVerificationMail(User $user, string $plainCode): void
    {
        try {
            $user->notifyNow(new VerifyEmailCodeNotification($plainCode));
        } catch (\Throwable $e) {
            Log::error('Verification email dispatch failed', [
                'user_id' => $user->id,
                'email'   => $user->email,
                'error'   => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function generatePlainCode(): string
    {
        $length = config('auth_verification.code_length');

        $max = (10 ** $length) - 1;
        $number = random_int(0, $max);

        return str_pad((string) $number, $length, '0', STR_PAD_LEFT);
    }
}
