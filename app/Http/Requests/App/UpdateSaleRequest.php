<?php

namespace App\Http\Requests\App;

use App\Http\Requests\Concerns\GuardsDocumentTotal;
use App\Http\Requests\Concerns\GuardsStockLevels;
use App\Support\FinancialRules;
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
    use GuardsDocumentTotal, GuardsStockLevels;

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
            'date' => ['required', ...FinancialRules::date()],

            'lines'              => ['required', 'array', 'min:1'],
            'lines.*.item_id'    => ['nullable', Rule::exists('items', 'id')->where('company_id', $companyId)],
            'lines.*.qty'        => ['required', ...FinancialRules::qty()],
            'lines.*.unit_price' => ['required', ...FinancialRules::amount(0)],

            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // A due date entered wrongly at creation could never be
            // corrected — update() simply didn't accept the field.
            // Nullable so a bill can also be moved back to "no due
            // date" rather than only forward.
            'due_date' => ['nullable', 'date', 'after_or_equal:date', 'before_or_equal:'.FinancialRules::latestAllowedDueDate()],
        ];
    }

    /**
     * Checks that need the lines added up first — see
     * GuardsDocumentTotal.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $subtotal = $this->documentLineTotal();
            $this->rejectTotalAboveCeiling($validator, $subtotal);

            $vatRate = (float) ($this->input('vat_rate') ?? 0);
            $total   = round($subtotal + round($subtotal * $vatRate / 100, 2), 2);
            $this->rejectAmountNowAboveTotal($validator, $total);

            // Last, so a line already rejected for a bad quantity is
            // not also told it is out of stock.
            $this->rejectOversellingStock($validator, $this->route('sale'));
        });
    }
}
