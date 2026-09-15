<?php

namespace App\Http\Requests\App;

use App\Http\Requests\Concerns\GuardsPaymentAmount;
use App\Support\FinancialRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — StoreReceiptRequest
//  Location: app/Http/Requests/App/StoreReceiptRequest.php
//  Used by App\Http\Controllers\App\PaymentController::storeReceipt()
//
//  Two shapes, matching the prototype's "Receive Money" tab:
//    - sale_id present   → payment against that open invoice
//    - sale_id absent    → generic receipt, optionally tied to a
//                          customer for the statement, with a note
// ══════════════════════════════════════════════════════════════════
class StoreReceiptRequest extends FormRequest
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
            'sale_id'     => ['nullable', Rule::exists('sales', 'id')->where('company_id', $companyId)],
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'date'        => ['required', ...FinancialRules::date()],
            'amount'      => ['required', ...FinancialRules::amount()],
            'method'      => ['required', Rule::in(['cash', 'bank', 'visa', 'instapay', 'wallet'])],
            'payment_channel_id' => ['nullable', Rule::exists('payment_channels', 'id')->where('company_id', $companyId)],
            'note'        => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * A receipt pointed at an invoice cannot settle more than
     * that invoice still owes — see GuardsPaymentAmount for what
     * to do with a genuine overpayment instead.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->rejectOverpayment($validator, $this->resolveSale(), (float) $this->input('amount'));
        });
    }
}
