<?php

namespace App\Http\Requests\Auth;

use App\Support\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — StoreRegisterRequest
//  Location: app/Http/Requests/Auth/StoreRegisterRequest.php
//
//  Public sign-up is company sign-up: the person filling this form
//  becomes the first company_admin of a brand-new Company. Employee
//  accounts are never created here — a company_admin creates those
//  from inside the app (see App\Http\Controllers\App\UserController).
//
//  Kept from InPractice: the honeypot (_hp) and time-guard (_ft)
//  anti-bot fields — generic, still useful, no rework needed.
//  Dropped from InPractice: nickname, hubs, profession, experience
//  level — none of those apply to a bookkeeping company account.
// ══════════════════════════════════════════════════════════════════
class StoreRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // ── Security fields ────────────────────────────────
            '_hp' => ['present', 'max:0'],
            '_ft' => ['required', 'integer', 'min:1'],

            // ── Company ─────────────────────────────────────────
            'company_name' => ['required', 'string', 'min:2', 'max:150'],
            'currency'     => ['nullable', 'string', 'max:8'],

            // ── First user (becomes company_admin) ──────────────
            'name'     => ['required', 'string', 'min:2', 'max:100'],
            'email'    => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', PasswordRules::defaults()],

            // ── Preferences ──────────────────────────────────────
            'language' => ['nullable', 'string', 'in:en,ar'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $ft = $this->input('_ft');

            if ($ft && is_numeric($ft)) {
                $elapsedMs = now()->timestamp * 1000 - (int) $ft;

                if ($elapsedMs < 3000) {
                    $validator->errors()->add('email', __('auth.failed'));
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            // The anti-bot fields are hidden, so an error attached to
            // them has nowhere on the form to appear. A person who
            // trips one — a browser autofilling the honeypot, a stale
            // tab whose timestamp never got set — would press Create
            // Account and watch absolutely nothing happen, forever.
            //
            // The text is deliberately vague about WHICH guard fired:
            // telling a bot exactly which field gave it away is the
            // one thing these fields exist to avoid. Register.vue
            // surfaces it as a banner above the form — see the
            // unboundError computed there.
            '_hp.present'         => __('auth.blocked_submission'),
            '_hp.max'             => __('auth.blocked_submission'),
            '_ft.required'        => __('auth.blocked_submission'),
            '_ft.integer'         => __('auth.blocked_submission'),
            '_ft.min'             => __('auth.blocked_submission'),
            'password.confirmed'  => __('auth.password_confirmed'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'company_name' => trim($this->company_name ?? ''),
            'name'         => trim($this->name ?? ''),
            'email'        => strtolower(trim($this->email ?? '')),
            'language'     => $this->language ?? 'en',
            'currency'     => $this->currency ?: 'EGP',
        ]);
    }
}
