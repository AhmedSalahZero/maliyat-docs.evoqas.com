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
        return (bool) $this->user()?->company_id;
    }

    public function rules(): array
    {
        return [
            'name'           => ['required', 'string', 'max:150'],
            'type'           => ['nullable', 'in:trading,raw_material,product'],
            'uom'            => ['nullable', 'string', 'max:40'],
            'qty_per_uom'    => ['nullable', ...FinancialRules::qty()],
            'base_unit_name' => ['nullable', 'string', 'max:40'],
            'confirm_unit_change' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Changing "units per carton" on an item that has already been
     * bought or sold must be confirmed (low finding 11). Lines already
     * recorded keep the conversion they were entered with, and only
     * new purchases and sales use the new one — easy to miss, and a
     * wrong figure here changes every future stock count. The screen
     * asks first and then sends confirm_unit_change; this refuses a
     * change that was never confirmed.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty() || $this->boolean('confirm_unit_change')) {
                return;
            }

            $item = $this->route('item');

            if (! $item) {
                return;
            }

            $changed = abs((float) $this->input('qty_per_uom') - (float) ($item->qty_per_uom ?: 1)) > 0.000001;
            $inUse   = $item->purchaseLines()->exists() || $item->saleLines()->exists();

            if ($changed && $inUse) {
                $validator->errors()->add('qty_per_uom', __('errors.item_unit_change_needs_confirm'));
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'type'           => $this->type ?: 'trading',
            'uom'            => $this->uom ?: 'Carton',
            'qty_per_uom'    => $this->qty_per_uom ?: 1,
            'base_unit_name' => $this->base_unit_name ?: 'Piece',
        ]);
    }
}
