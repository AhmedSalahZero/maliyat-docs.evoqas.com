<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — StoreCustomerRequest
//  Location: app/Http/Requests/App/StoreCustomerRequest.php
//  Used by App\Http\Controllers\App\CustomerController::store()
//  Company scoping happens automatically (BelongsToCompany trait
//  fills company_id from the logged-in user on create).
// ══════════════════════════════════════════════════════════════════
class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->company_id;
    }

    public function rules(): array
    {
        return [
            'name'  => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
        ];
    }
}
