<?php

namespace App\Http\Requests\Admin;

use App\Support\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — StoreCompanyRequest
//  Location: app/Http/Requests/Admin/StoreCompanyRequest.php
//  Used by App\Http\Controllers\Admin\CompanyController::store()
// ══════════════════════════════════════════════════════════════════
class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperAdmin();
    }

    public function rules(): array
    {
        return [
            'name'          => ['required', 'string', 'max:150'],
            'name_ar'       => ['nullable', 'string', 'max:150'],
            'currency'      => ['nullable', 'string', 'max:8'],

            'admin_name'     => ['required', 'string', 'min:2', 'max:100'],
            'admin_email'    => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'admin_password' => ['required', 'confirmed', PasswordRules::defaults()],
        ];
    }
}
