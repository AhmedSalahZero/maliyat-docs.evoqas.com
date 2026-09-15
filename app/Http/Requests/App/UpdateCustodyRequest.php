<?php

namespace App\Http\Requests\App;

use App\Support\FinancialRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustodyRequest extends FormRequest
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
            'amount'    => ['required', ...FinancialRules::amount()],
            'method'    => ['nullable', Rule::in(['cash', 'bank', 'visa', 'instapay', 'wallet'])],
            'payment_channel_id' => ['nullable', Rule::exists('payment_channels', 'id')->where('company_id', $companyId)],
            'given_at'  => ['required', ...FinancialRules::date()],
        ];
    }
}
