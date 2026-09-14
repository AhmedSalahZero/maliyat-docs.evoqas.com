<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — StoreItemRequest
//  Location: app/Http/Requests/App/StoreItemRequest.php
//  Used by App\Http\Controllers\App\ItemController::store()
// ══════════════════════════════════════════════════════════════════
class StoreItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->company_id;
    }

    public function rules(): array
    {
        return [
            'name'           => ['required', 'string', 'max:150'],
            'uom'            => ['nullable', 'string', 'max:40'],
            'qty_per_uom'    => ['nullable', 'numeric', 'min:0.01'],
            'base_unit_name' => ['nullable', 'string', 'max:40'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'uom'            => $this->uom ?: 'Carton',
            'qty_per_uom'    => $this->qty_per_uom ?: 1,
            'base_unit_name' => $this->base_unit_name ?: 'unit',
        ]);
    }
}
