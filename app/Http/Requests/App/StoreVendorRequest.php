<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — StoreVendorRequest
//  Location: app/Http/Requests/App/StoreVendorRequest.php
//  Used by App\Http\Controllers\App\VendorController::store()
// ══════════════════════════════════════════════════════════════════
class StoreVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->company_id;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(['vendor', 'employee'])],
        ];
    }
}
