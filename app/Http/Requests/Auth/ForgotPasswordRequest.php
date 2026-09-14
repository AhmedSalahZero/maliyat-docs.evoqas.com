<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\SetsLocaleFromRequest;
use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
{
    use SetsLocaleFromRequest;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email'  => ['required', 'string', 'email', 'exists:users,email'],
            'locale' => ['nullable', 'string', 'in:en,ar'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->applyRequestLocale();
    }

    public function messages(): array
    {
        return [
            'email.exists' => __('auth.reset_email_not_found'),
        ];
    }
}
