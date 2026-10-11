<?php

namespace App\Http\Requests\App;

use App\Http\Requests\Concerns\NormalizesName;
use App\Models\User;
use App\Rules\UniqueNameInCompany;
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
    use NormalizesName;

    public function authorize(): bool
    {
        return (bool) $this->user()?->isCompanyAdmin();
    }

    public function rules(): array
    {
        return [
            'name'     => [
                'required', 'string', 'min:2', 'max:100',
                new UniqueNameInCompany(User::class, $this->user()?->company_id, 'errors.duplicate_employee'),
            ],
            'email'    => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', PasswordRules::defaults()],
        ];
    }
}
