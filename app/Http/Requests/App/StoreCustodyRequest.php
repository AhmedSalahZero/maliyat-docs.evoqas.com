<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — StoreCustodyRequest
//  Location: app/Http/Requests/App/StoreCustodyRequest.php
//  Used by App\Http\Controllers\App\CustodyController::store()
//
//  "Give custody of {amount} to {holder}."
//  Unlike Sales/Expenses/Inventory/Equipment, custody has no
//  now/later/partial choice — handing out custody is always an
//  immediate cash-out; settlement happens later (see
//  SettleCustodyRequest).
// ══════════════════════════════════════════════════════════════════
class StoreCustodyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->company_id;
    }

    public function rules(): array
    {
        $companyId = $this->user()->company_id;

        return [
            'holder_id' => ['required', Rule::exists('vendors', 'id')->where('company_id', $companyId)],
            'amount'    => ['required', 'numeric', 'min:0.01'],
            'method'    => ['nullable', Rule::in(['cash', 'bank', 'visa', 'instapay', 'wallet'])],
            'payment_channel_id' => ['nullable', Rule::exists('payment_channels', 'id')->where('company_id', $companyId)],
            'given_at'  => ['required', 'date'],
        ];
    }
}
