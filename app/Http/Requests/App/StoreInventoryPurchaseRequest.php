<?php

namespace App\Http\Requests\App;

use App\Rules\HalfStepQuantity;
use App\Http\Requests\Concerns\GuardsDocumentTotal;
use App\Support\FinancialRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — StoreInventoryPurchaseRequest
//  Location: app/Http/Requests/App/StoreInventoryPurchaseRequest.php
//  Used by App\Http\Controllers\App\InventoryPurchaseController::store()
//
//  "Buy from {vendor}: {lines...} for {total}, paid {mode}."
// ══════════════════════════════════════════════════════════════════
class StoreInventoryPurchaseRequest extends FormRequest
{
    use GuardsDocumentTotal;

    public function authorize(): bool
    {
        return (bool) $this->user()?->company_id;
    }

    public function rules(): array
    {
        $companyId = $this->user()->company_id;

        return [
            'vendor_id' => ['required', Rule::exists('vendors', 'id')->where('company_id', $companyId)],
            'date'      => ['required', ...FinancialRules::date()],

            'lines'                  => ['required', 'array', 'min:1'],
            'lines.*.item_id'        => ['required', Rule::exists('items', 'id')->where('company_id', $companyId)],
            // Whole or half only (1, 1.5, 2, 2.5 …) — see HalfStepQuantity.
            'lines.*.qty'            => ['required', ...FinancialRules::qty(0.5), new HalfStepQuantity],
            'lines.*.uom'            => ['nullable', 'string', 'max:40'],
            'lines.*.qty_per_uom'    => ['nullable', ...FinancialRules::qty()],
            'lines.*.base_unit_name' => ['nullable', 'string', 'max:40'],
            'lines.*.unit_price'     => ['required', ...FinancialRules::amount(0)],

            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'mode'        => ['required', Rule::in(['now', 'later', 'partial', 'installment'])],
            'method'      => ['nullable', Rule::in(['cash', 'bank', 'visa', 'instapay', 'wallet'])],
            'payment_channel_id' => ['nullable', Rule::exists('payment_channels', 'id')->where('company_id', $companyId)],
            'amount_now'  => ['required_if:mode,partial', 'nullable', ...FinancialRules::amount()],
            'due_date'    => ['nullable', 'date', 'after_or_equal:date', 'before_or_equal:'.FinancialRules::latestAllowedDueDate()],
            'due_in_days' => ['nullable', 'integer', 'min:1', 'max:365'],

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
        });
    }
}
