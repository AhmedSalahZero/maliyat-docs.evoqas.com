<?php

namespace App\Http\Requests\App;

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

            'mode'       => ['required', Rule::in(['now', 'later', 'partial', 'installment'])],
            'method'     => ['nullable', Rule::in(['cash', 'bank', 'visa', 'instapay', 'wallet'])],
            'payment_channel_id' => ['nullable', Rule::exists('payment_channels', 'id')->where('company_id', $companyId)],
            'amount_now' => ['required_if:mode,partial', 'nullable', 'numeric', 'min:0.01'],
            'due_date'   => ['nullable', 'date', 'after_or_equal:date'],
            'due_in_days'=> ['nullable', 'integer', 'min:1', 'max:365'],

            // Only used when mode = 'installment' — see
            // PaymentRecorderService::buildInstallmentSchedule().
            'installment_count'         => ['required_if:mode,installment', 'nullable', 'integer', 'min:2', 'max:60'],
            'installment_interval_days' => ['required_if:mode,installment', 'nullable', 'integer', 'min:1', 'max:365'],
        ];
    }
}
