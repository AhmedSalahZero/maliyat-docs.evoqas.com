<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — StoreRecurringExpenseRequest
//  Location: app/Http/Requests/App/StoreRecurringExpenseRequest.php
//  Used by App\Http\Controllers\App\ExpenseController::storeRecurring()
// ══════════════════════════════════════════════════════════════════
class StoreRecurringExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->company_id;
    }

    public function rules(): array
    {
        $companyId = $this->user()->company_id;

        return [
            'vendor_id'   => ['required', Rule::exists('vendors', 'id')->where('company_id', $companyId)],
            'category_id' => ['required', Rule::exists('categories', 'id')->where('company_id', $companyId)->where('kind', 'expense')],
            'date'        => ['required', 'date'],
            'amount'      => ['required', 'numeric', 'min:0.01'],
            'frequency'   => ['required', Rule::in(['weekly', 'monthly', 'q3', 'h6'])],
            'count'       => ['required', 'integer', 'min:2', 'max:60'],

            'mode'        => ['required', Rule::in(['now', 'later', 'partial'])],
            'method'      => ['nullable', Rule::in(['cash', 'bank', 'visa', 'instapay', 'wallet'])],
            'payment_channel_id' => ['nullable', Rule::exists('payment_channels', 'id')->where('company_id', $companyId)],
            'amount_now'  => ['required_if:mode,partial', 'nullable', 'numeric', 'min:0.01'],
            'due_date'    => ['nullable', 'date', 'after_or_equal:date'],
        ];
    }
}
