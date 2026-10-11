<?php

namespace App\Http\Requests\App;

use App\Http\Requests\Concerns\NormalizesName;
use App\Models\Customer;
use App\Rules\UniqueNameInCompany;
use Illuminate\Foundation\Http\FormRequest;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — UpdateCustomerRequest
//  Location: app/Http/Requests/App/UpdateCustomerRequest.php
//  Used by App\Http\Controllers\App\CustomerController::update()
// ══════════════════════════════════════════════════════════════════
class UpdateCustomerRequest extends FormRequest
{
    use NormalizesName;

    public function authorize(): bool
    {
        return (bool) $this->user()?->company_id;
    }

    public function rules(): array
    {
        return [
            'name'  => [
                'required', 'string', 'max:150',
                new UniqueNameInCompany(Customer::class, $this->user()?->company_id, 'errors.duplicate_customer', $this->route('customer')?->id),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
        ];
    }
}
