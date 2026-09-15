<?php

namespace App\Http\Requests\App;

use App\Support\FinancialRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — UpdateExpenseRequest
//  Location: app/Http/Requests/App/UpdateExpenseRequest.php
//  Used by App\Http\Controllers\App\ExpenseController::update()
//  See UpdateSaleRequest's doc comment — same reasoning here.
// ══════════════════════════════════════════════════════════════════
class UpdateExpenseRequest extends FormRequest
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
            'date'        => ['required', ...FinancialRules::date()],
            'amount'      => ['required', ...FinancialRules::amount()],
            // A due date entered wrongly at creation could never be
            // corrected — update() simply didn't accept the field.
            // Nullable so a bill can also be moved back to "no due
            // date" rather than only forward.
            'due_date' => ['nullable', 'date', 'after_or_equal:date', 'before_or_equal:'.FinancialRules::latestAllowedDueDate()],
        ];
    }
}
