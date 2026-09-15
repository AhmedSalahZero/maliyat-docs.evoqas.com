<?php

namespace App\Http\Requests\App;

use App\Support\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — UpdateEmployeeRequest
//
//  A company admin could create an employee and then only switch them
//  on and off — a misspelt name or a wrong email address was
//  permanent. This makes both correctable.
//
//  The password is optional here on purpose: leaving it blank edits
//  the other fields and leaves the existing password alone, which is
//  what an admin fixing a typo expects. Filling it in resets it.
// ══════════════════════════════════════════════════════════════════
class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        // Only a company admin, and only over someone in their own
        // company. UserController re-checks this too — this is the
        // first gate, not the only one.
        return (bool) $this->user()?->isCompanyAdmin()
            && $target?->company_id === $this->user()->company_id;
    }

    public function rules(): array
    {
        $target = $this->route('user');

        return [
            'name'  => ['required', 'string', 'min:2', 'max:100'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($target?->id),
            ],
            // Blank means "don't touch it".
            'password' => ['nullable', 'confirmed', PasswordRules::defaults()],
        ];
    }
}
