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
            'sales_channel_id' => [
                'nullable',
                Rule::exists('sales_channels', 'id')->where('company_id', $companyId),
            ],
            'date' => ['required', ...FinancialRules::date()],

            'lines'                  => ['required', 'array', 'min:1'],
            'lines.*.item_id'        => ['nullable', Rule::exists('items', 'id')->where('company_id', $companyId)],
            'lines.*.qty'            => ['required', ...FinancialRules::qty()],
            'lines.*.uom'            => ['nullable', 'string', 'max:40'],
            'lines.*.qty_per_uom'    => ['nullable', ...FinancialRules::qty()],
            'lines.*.base_unit_name' => ['nullable', 'string', 'max:40'],
            'lines.*.unit_price'     => ['required', ...FinancialRules::amount(0)],
            // VAT % and Withholding Tax % of this line. Withholding is
            // calculated on the line amount BEFORE VAT.
            'lines.*.vat_rate'         => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.withholding_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],

            // Invoice-level VAT % — only the fallback for a line that
            // carries no VAT % of its own (an old draft). The screen
            // now sends VAT % and Withholding % on every line.
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

            // VAT and Withholding Tax per product line — see LineTax.
            // "Paid now" is compared with what is actually owed, i.e.
            // AFTER the withholding is taken off.
            $tax = $this->documentTaxTotals();
            $this->rejectGrossAboveCeiling($validator, $tax);
            $this->rejectAmountNowAboveTotal($validator, $tax['total']);

            // Last, so a line already rejected for a bad quantity is
            // not also told it is out of stock.
            $this->rejectLinesWithoutItem($validator);
            $this->rejectFractionalPackagingQty($validator);

            $this->rejectOversellingStock($validator, $this->route('sale'));
        });
    }

    /**
     * A future date is refused (see FinancialRules::date()); say so
     * in plain words instead of "must be a date before or equal to
     * 2026-09-23".
     */
    public function messages(): array
    {
        return [
            'date.before_or_equal' => __('validation.sale_date_not_future'),
        ];
    }
}
