<?php

namespace App\Http\Requests\App;

use App\Http\Requests\Concerns\GuardsPaymentAmount;
use App\Services\CustomerCreditService;
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
//
//  Customer credit (audit finding 4.6):
//    - use_credit            pay the invoice from credit the customer
//                            already has, instead of new money;
//    - keep_extra_as_credit  the customer paid MORE than the invoice:
//                            the invoice is settled and the extra is
//                            kept as their credit (a liability — never
//                            revenue, which is what the old advice to
//                            "record the extra as a cash sale" did).
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
            // Not needed when paying from credit — no money moves.
            'method'      => ['required_unless:use_credit,1,true', 'nullable', Rule::in(['cash', 'bank', 'visa', 'instapay', 'wallet'])],
            'use_credit'           => ['nullable', 'boolean'],
            'keep_extra_as_credit' => ['nullable', 'boolean'],
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

            $sale   = $this->resolveSale();
            $amount = (float) $this->input('amount');

            if ($this->boolean('use_credit')) {
                $this->rejectCreditUse($validator, $sale, $amount);

                return;
            }

            // Paying more than the invoice is allowed only when the
            // user said to keep the extra as the customer's credit.
            if ($sale && $this->boolean('keep_extra_as_credit')) {
                return;
            }

            $this->rejectOverpayment($validator, $sale, $amount);
        });
    }

    /**
     * Paying from credit needs an invoice, enough credit, and no more
     * than the invoice still owes.
     */
    private function rejectCreditUse($validator, $sale, float $amount): void
    {
        if (! $sale) {
            $validator->errors()->add('sale_id', __('errors.credit_needs_invoice'));

            return;
        }

        $available = app(CustomerCreditService::class)->available((int) $sale->customer_id);

        if ($amount > $available + \App\Support\FinancialRules::AMOUNT_TOLERANCE) {
            $validator->errors()->add('amount', __('errors.credit_not_enough', [
                'available' => number_format($available, 2),
            ]));

            return;
        }

        $this->rejectOverpayment($validator, $sale, $amount);
    }
}
