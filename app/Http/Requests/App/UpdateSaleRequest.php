<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — UpdateSaleRequest
//  Location: app/Http/Requests/App/UpdateSaleRequest.php
//  Used by App\Http\Controllers\App\SaleController::update()
//
//  Only the invoice itself is editable here (customer, lines, VAT)
//  — not payment mode/method, which only make sense at the moment
//  of creation. Editing after payments exist is allowed (per the
//  user's explicit choice), but the frontend must warn about the
//  resulting deficit/surplus before submitting; see Sales/Index.vue.
// ══════════════════════════════════════════════════════════════════
class UpdateSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->company_id;
    }

    public function rules(): array
    {
        $companyId = $this->user()->company_id;

        return [
            'customer_id' => [
                'required',
                Rule::exists('customers', 'id')->where('company_id', $companyId),
            ],
            'date' => ['required', 'date'],

            'lines'              => ['required', 'array', 'min:1'],
            'lines.*.item_id'    => ['nullable', Rule::exists('items', 'id')->where('company_id', $companyId)],
            'lines.*.qty'        => ['required', 'numeric', 'min:0.01'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],

            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
