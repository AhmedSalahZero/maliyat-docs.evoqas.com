<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\SetsLocaleFromRequest;
use App\Models\User;
use App\Services\Auth\EmailVerificationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class ResendVerificationCodeRequest extends FormRequest
{
    use SetsLocaleFromRequest;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email'  => ['required', 'string', 'email'],
            'locale' => ['nullable', 'string', 'in:en,ar'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->applyRequestLocale();
    }

    public function resolveUser(): User
    {
        if ($this->user() && $this->user()->email === $this->input('email')) {
            if ($this->user()->hasVerifiedEmail()) {
                throw ValidationException::withMessages([
                    'email' => [__('auth.verification_already_verified')],
                ]);
            }

            return $this->user();
        }

        $user = app(EmailVerificationService::class)
            ->findUnverifiedUserByEmail($this->input('email'));

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => [__('auth.verification_email_not_found')],
            ]);
        }

        return $user;
    }
}
