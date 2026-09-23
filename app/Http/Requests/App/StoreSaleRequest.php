<?php

namespace App\Http\Requests\App;

use App\Http\Requests\Concerns\GuardsDocumentTotal;
use App\Http\Requests\Concerns\GuardsStockLevels;
use App\Support\FinancialRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — StoreSaleRequest
//  Location: app/Http/Requests/App/StoreSaleRequest.php
//  Used by App\Http\Controllers\App\SaleController::store()
//
//  "Sell to {customer}: {lines...} for {total}, paid {mode}."
// ══════════════════════════════════════════════════════════════════
class StoreSaleRequest extends FormRequest
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
                // Nullable so a Cash Sale can be submitted with no
                // customer chosen — SaleController::store() fills in
                // Customer::cashCustomer() when this is left blank.
                'nullable',
                Rule::exists('customers', 'id')->where('company_id', $companyId),
            ],
            'sales_channel_id' => [
                // Nullable the same way — the form always sends the
                // default "Direct Sales" channel, but the controller
                // has its own fallback if it's ever missing.
                'nullable',
                Rule::exists('sales_channels', 'id')->where('company_id', $companyId),
            ],
            'date' => ['required', ...FinancialRules::date()],

            // Set when this sale is being recorded from a saved draft;
            // the draft is removed once the sale is saved. See
            // SaleDraftController.
            'draft_id' => ['nullable', 'integer'],

            'lines'                  => ['required', 'array', 'min:1'],
            'lines.*.item_id'        => ['nullable', Rule::exists('items', 'id')->where('company_id', $companyId)],
            'lines.*.qty'            => ['required', ...FinancialRules::qty()],
            // The unit this line was actually sold in — e.g. "Carton"
            // — and how many base units (e.g. "kg") one of it equals.
            // Optional: a free-text line, or one submitted with no
            // unit info, is simply read as already being in base
            // units (qty_per_uom defaults to 1 — see
            // SaleController::lineAttributes()), same as before this
            // feature existed.
            'lines.*.uom'            => ['nullable', 'string', 'max:40'],
            'lines.*.qty_per_uom'    => ['nullable', ...FinancialRules::qty()],
            'lines.*.base_unit_name' => ['nullable', 'string', 'max:40'],
            'lines.*.unit_price'     => ['required', ...FinancialRules::amount(0)],

            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'mode'       => ['required', Rule::in(['now', 'later', 'partial', 'installment'])],
            'method'     => ['nullable', Rule::in(['cash', 'bank', 'visa', 'instapay', 'wallet'])],
            'payment_channel_id' => ['nullable', Rule::exists('payment_channels', 'id')->where('company_id', $companyId)],
            'amount_now' => ['required_if:mode,partial', 'nullable', ...FinancialRules::amount()],
            'due_date'   => ['nullable', 'date', 'after_or_equal:date', 'before_or_equal:'.FinancialRules::latestAllowedDueDate()],
            'due_in_days'=> ['nullable', 'integer', 'min:1', 'max:365'],

            // Only used when mode = 'installment' — see
            // PaymentRecorderService::buildInstallmentSchedule().
            'installment_count'         => ['required_if:mode,installment', 'nullable', 'integer', 'min:2', 'max:60'],
            'installment_interval_days' => ['required_if:mode,installment', 'nullable', 'integer', 'min:1', 'max:365'],
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
            $this->rejectOversellingStock($validator, null);
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
