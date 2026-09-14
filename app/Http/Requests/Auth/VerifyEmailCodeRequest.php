<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\SetsLocaleFromRequest;
use App\Models\User;
use App\Services\Auth\EmailVerificationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class VerifyEmailCodeRequest extends FormRequest
{
    use SetsLocaleFromRequest;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $length = config('auth_verification.code_length');

        return [
            'email'  => ['required', 'string', 'email'],
            'code'   => ['required', 'string', "size:{$length}", 'regex:/^[0-9]+$/'],
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
