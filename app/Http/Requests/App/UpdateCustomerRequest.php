<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — UpdateCustomerRequest
//  Location: app/Http/Requests/App/UpdateCustomerRequest.php
//  Used by App\Http\Controllers\App\CustomerController::update()
// ══════════════════════════════════════════════════════════════════
class UpdateCustomerRequest extends FormRequest
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
