<?php

namespace App\Http\Requests\App;

use App\Support\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — StoreEmployeeRequest
//  Location: app/Http/Requests/App/StoreEmployeeRequest.php
//  Used by App\Http\Controllers\App\UserController::store()
//  Only a company_admin (see UserController's own authorization
//  check) can reach this — the new user is always created as
//  'employee' in the admin's own company.
// ══════════════════════════════════════════════════════════════════
class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isCompanyAdmin();
    }

    public function rules(): array
    {
        return [
            'name'     => ['required', 'string', 'min:2', 'max:100'],
            'email'    => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', PasswordRules::defaults()],
        ];
    }
}
