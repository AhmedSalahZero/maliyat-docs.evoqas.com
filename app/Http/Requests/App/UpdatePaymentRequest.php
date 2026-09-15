<?php

namespace App\Http\Requests\App;

use App\Http\Requests\Concerns\GuardsPaymentAmount;
use App\Support\FinancialRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — UpdatePaymentRequest
//
//  Correcting a payment in place. Before this, a payment entered for
//  the wrong amount or on the wrong date could only be deleted and
//  re-entered — which works, but leaves two reversal entries in the
//  ledger for what was really one typo.
//
//  What is NOT editable here: which record the payment belongs to.
//  Moving money from one invoice to another is not a correction, it
//  is two different facts — delete it and record it against the
//  right one, so both ledgers tell the truth.
// ══════════════════════════════════════════════════════════════════
class UpdatePaymentRequest extends FormRequest
{
    use GuardsPaymentAmount;

    public function authorize(): bool
    {
        // The company scope on route-model binding has already 404'd
        // anything belonging to someone else.
        return (bool) $this->user();
    }

    public function rules(): array
    {
        $companyId = $this->user()->company_id;

        return [
            'date'   => ['required', ...FinancialRules::date()],
            'amount' => ['required', ...FinancialRules::amount()],
            'method' => ['required', Rule::in(['cash', 'bank', 'visa', 'instapay', 'wallet'])],
            'payment_channel_id' => [
                'nullable',
                Rule::exists('payment_channels', 'id')->where('company_id', $companyId),
            ],
        ];
    }

    /**
     * A correction cannot push the record it settles past what it
     * owes. This payment's own current amount is credited back
     * first, so re-saving it unchanged — or reducing it — is
     * always allowed.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $payment = $this->route('payment');

            $this->rejectOverpayment(
                $validator,
                $payment?->payable,
                (float) $this->input('amount'),
                (float) ($payment?->amount ?? 0),
            );
        });
    }
}
