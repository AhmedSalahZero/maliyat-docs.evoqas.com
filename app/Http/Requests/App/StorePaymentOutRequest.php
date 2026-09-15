<?php

namespace App\Http\Requests\App;

use App\Http\Requests\Concerns\GuardsPaymentAmount;
use App\Support\FinancialRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — StorePaymentOutRequest
//  Location: app/Http/Requests/App/StorePaymentOutRequest.php
//  Used by App\Http\Controllers\App\PaymentController::storePayment()
//
//  Matches the prototype's "Pay Money" tab. `payable_type` names
//  which open-bill table `payable_id` belongs to; both blank means
//  a standalone payment with no bill attached.
// ══════════════════════════════════════════════════════════════════
class StorePaymentOutRequest extends FormRequest
{
    use GuardsPaymentAmount;

    public function authorize(): bool
    {
        return (bool) $this->user()?->company_id;
    }

    public function rules(): array
    {
        $companyId = $this->user()->company_id;

        return [
            'payable_type' => ['nullable', Rule::in(['expense', 'inventory_purchase', 'equipment_purchase'])],
            'payable_id'   => ['required_with:payable_type', 'nullable', 'integer'],
            'vendor_id'    => ['nullable', Rule::exists('vendors', 'id')->where('company_id', $companyId)],
            'category_id'  => ['nullable', Rule::exists('categories', 'id')->where('company_id', $companyId)->where('kind', 'expense')],
            'date'         => ['required', ...FinancialRules::date()],
            'amount'       => ['required', ...FinancialRules::amount()],
            'method'       => ['required', Rule::in(['cash', 'bank', 'visa', 'instapay', 'wallet'])],
            'payment_channel_id' => ['nullable', Rule::exists('payment_channels', 'id')->where('company_id', $companyId)],
            'note'         => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Same rule as the receipt side: a payment pointed at a bill
     * cannot settle more than that bill still owes.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->rejectOverpayment($validator, $this->resolveBill(), (float) $this->input('amount'));
        });
    }
}
