<?php

namespace App\Http\Requests\App;

use App\Support\FinancialRules;
use Illuminate\Foundation\Http\FormRequest;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — UpdateItemRequest
//
//  An item could be created and never corrected: a typo in the name,
//  or a unit-of-measure entered wrongly, was permanent.
//
//  qty_per_uom is the one field with consequences beyond the row —
//  every stock figure is expressed in BASE units, so changing it
//  re-interprets purchase lines already recorded against this item.
//  It stays editable (a wrong conversion is worse than no fix), but
//  see ItemController::update() for the warning that goes with it.
// ══════════════════════════════════════════════════════════════════
class UpdateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route-model binding applies the company scope, so reaching
        // another company's item already 404s before this runs.
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'name'           => ['required', 'string', 'max:150'],
            'uom'            => ['nullable', 'string', 'max:40'],
            'qty_per_uom'    => ['nullable', ...FinancialRules::qty()],
            'base_unit_name' => ['nullable', 'string', 'max:40'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'uom'            => $this->uom ?: 'Carton',
            'qty_per_uom'    => $this->qty_per_uom ?: 1,
            'base_unit_name' => $this->base_unit_name ?: 'Piece',
        ]);
    }
}
