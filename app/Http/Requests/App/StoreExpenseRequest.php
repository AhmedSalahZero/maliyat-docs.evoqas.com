<?php

namespace App\Http\Requests\App;

use App\Http\Requests\Concerns\GuardsDocumentTotal;
use App\Support\FinancialRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — StoreExpenseRequest
//  Location: app/Http/Requests/App/StoreExpenseRequest.php
//  Used by App\Http\Controllers\App\ExpenseController::store()
//
//  "Pay {vendor/employee} {amount} for {category}, paid {mode}."
// ══════════════════════════════════════════════════════════════════
class StoreExpenseRequest extends FormRequest
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
            // Nullable so a Cash Expense can be submitted with no
            // vendor/employee chosen — ExpenseController::store()
            // fills in Vendor::cashVendor() when this is left blank.
            'vendor_id'   => ['nullable', Rule::exists('vendors', 'id')->where('company_id', $companyId)],
            'category_id' => ['required', Rule::exists('categories', 'id')->where('company_id', $companyId)->where('kind', 'expense')],
            'date'        => ['required', ...FinancialRules::date()],
            'amount'      => ['required', ...FinancialRules::amount()],
            // "This is Production Labor" checkbox — see
            // ExpenseController::postExpenseJournal().
            'is_production_labor' => ['nullable', 'boolean'],

            'mode'        => ['required', Rule::in(['now', 'later', 'partial', 'installment'])],
            'method'      => ['nullable', Rule::in(['cash', 'bank', 'visa', 'instapay', 'wallet'])],
            'payment_channel_id' => ['nullable', Rule::exists('payment_channels', 'id')->where('company_id', $companyId)],
            'amount_now'  => ['required_if:mode,partial', 'nullable', ...FinancialRules::amount()],
            'due_date'    => ['nullable', 'date', 'after_or_equal:date', 'before_or_equal:'.FinancialRules::latestAllowedDueDate()],
            'due_in_days' => ['nullable', 'integer', 'min:1', 'max:365'],

            // Only used when mode = 'installment' — see
            // PaymentRecorderService::buildInstallmentSchedule().
            'installment_count'         => ['required_if:mode,installment', 'nullable', 'integer', 'min:2', 'max:60'],
            'installment_interval_days' => ['required_if:mode,installment', 'nullable', 'integer', 'min:1', 'max:365'],
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