<?php

namespace App\Http\Requests\App;

use App\Http\Requests\Concerns\GuardsDocumentTotal;
use App\Support\FinancialRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — StoreRecurringExpenseRequest
//  Location: app/Http/Requests/App/StoreRecurringExpenseRequest.php
//  Used by App\Http\Controllers\App\ExpenseController::storeRecurring()
// ══════════════════════════════════════════════════════════════════
class StoreRecurringExpenseRequest extends FormRequest
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
            'vendor_id'   => ['required', Rule::exists('vendors', 'id')->where('company_id', $companyId)],
            'category_id' => ['required', Rule::exists('categories', 'id')->where('company_id', $companyId)->where('kind', 'expense')],
            'date'        => ['required', ...FinancialRules::date()],
            'amount'      => ['required', ...FinancialRules::amount()],
            'frequency'   => ['required', Rule::in(['weekly', 'monthly', 'q3', 'h6'])],
            'count'       => ['required', 'integer', 'min:2', 'max:60'],

            'mode'        => ['required', Rule::in(['now', 'later', 'partial'])],
            'method'      => ['nullable', Rule::in(['cash', 'bank', 'visa', 'instapay', 'wallet'])],
            'payment_channel_id' => ['nullable', Rule::exists('payment_channels', 'id')->where('company_id', $companyId)],
            'amount_now'  => ['required_if:mode,partial', 'nullable', ...FinancialRules::amount()],
            'due_date'    => ['nullable', 'date', 'after_or_equal:date', 'before_or_equal:'.FinancialRules::latestAllowedDueDate()],
        ];
    }

    /**
     * Checks that need the document's own total — see
     * GuardsDocumentTotal.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $total = (float) $this->input('amount', 0);

            $this->rejectTotalAboveCeiling($validator, $total, 'amount');
            $this->rejectAmountNowAboveTotal($validator, $total);
        });
    }
}
