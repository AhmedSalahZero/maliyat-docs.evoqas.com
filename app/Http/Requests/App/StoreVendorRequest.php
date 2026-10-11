<?php

namespace App\Http\Requests\App;

use App\Http\Requests\Concerns\NormalizesName;
use App\Models\Vendor;
use App\Rules\UniqueNameInCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — StoreVendorRequest
//  Location: app/Http/Requests/App/StoreVendorRequest.php
//  Used by App\Http\Controllers\App\VendorController::store()
// ══════════════════════════════════════════════════════════════════
class StoreVendorRequest extends FormRequest
{
    use NormalizesName;

    public function authorize(): bool
    {
        return (bool) $this->user()?->company_id;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:150',
                // Suppliers and employees share one list, so the name
                // must be new across both (same company only).
                new UniqueNameInCompany(Vendor::class, $this->user()?->company_id, 'errors.duplicate_vendor'),
            ],
            'type' => ['required', Rule::in(['vendor', 'employee'])],
        ];
    }
}
