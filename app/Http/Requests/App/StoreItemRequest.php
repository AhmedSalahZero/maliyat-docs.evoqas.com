<?php

namespace App\Http\Requests\App;

use App\Support\FinancialRules;
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
            // 'trading' unless the company has Production turned on —
            // the picker for the other two only appears on the
            // frontend then, so most companies never send this field.
            'type'           => ['nullable', 'in:trading,raw_material,product'],
            'uom'            => ['nullable', 'string', 'max:40'],
            'qty_per_uom'    => ['nullable', ...FinancialRules::qty()],
            'base_unit_name' => ['nullable', 'string', 'max:40'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'type'           => $this->type ?: 'trading',
            'uom'            => $this->uom ?: 'Carton',
            'qty_per_uom'    => $this->qty_per_uom ?: 1,
            'base_unit_name' => $this->base_unit_name ?: 'unit',
        ]);
    }
}
