<?php

namespace App\Http\Requests\App;

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
    public function authorize(): bool
    {
        return (bool) $this->user()?->company_id;
    }

    public function rules(): array
    {
        $companyId = $this->user()->company_id;

        return [
            'vendor_id' => ['required', Rule::exists('vendors', 'id')->where('company_id', $companyId)],
            'date'      => ['required', 'date'],

            'lines'                  => ['required', 'array', 'min:1'],
            'lines.*.item_id'        => ['required', Rule::exists('items', 'id')->where('company_id', $companyId)],
            'lines.*.qty'            => ['required', 'numeric', 'min:0.01'],
            'lines.*.uom'            => ['nullable', 'string', 'max:40'],
            'lines.*.qty_per_uom'    => ['nullable', 'numeric', 'min:0.01'],
            'lines.*.base_unit_name' => ['nullable', 'string', 'max:40'],
            'lines.*.unit_price'     => ['required', 'numeric', 'min:0'],

            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'mode'        => ['required', Rule::in(['now', 'later', 'partial', 'installment'])],
            'method'      => ['nullable', Rule::in(['cash', 'bank', 'visa', 'instapay', 'wallet'])],
            'payment_channel_id' => ['nullable', Rule::exists('payment_channels', 'id')->where('company_id', $companyId)],
            'amount_now'  => ['required_if:mode,partial', 'nullable', 'numeric', 'min:0.01'],
            'due_date'    => ['nullable', 'date', 'after_or_equal:date'],
            'due_in_days' => ['nullable', 'integer', 'min:1', 'max:365'],

            // Only used when mode = 'installment' — see
            // PaymentRecorderService::buildInstallmentSchedule().
            'installment_count'         => ['required_if:mode,installment', 'nullable', 'integer', 'min:2', 'max:60'],
            'installment_interval_days' => ['required_if:mode,installment', 'nullable', 'integer', 'min:1', 'max:365'],
        ];
    }
}
