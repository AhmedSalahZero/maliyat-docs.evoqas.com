<?php

namespace App\Http\Requests\App;

use App\Http\Requests\Concerns\NormalizesName;
use App\Models\Customer;
use App\Rules\UniqueNameInCompany;
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
                // No second customer with the same name in this company
                // (spaces and capitals ignored) — see UniqueNameInCompany.
                new UniqueNameInCompany(Customer::class, $this->user()?->company_id, 'errors.duplicate_customer'),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
        ];
    }
}
