<?php

namespace App\Http\Requests\App;

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
            'date'        => ['required', 'date'],
            'amount'      => ['required', 'numeric', 'min:0.01'],
        ];
    }
}
