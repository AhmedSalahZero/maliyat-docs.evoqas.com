<?php

namespace App\Http\Requests\App;

use App\Http\Requests\Concerns\GuardsDocumentTotal;
use App\Support\FinancialRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — StoreEquipmentPurchaseRequest
//  Location: app/Http/Requests/App/StoreEquipmentPurchaseRequest.php
//  Used by App\Http\Controllers\App\EquipmentPurchaseController::store()
// ══════════════════════════════════════════════════════════════════
class StoreEquipmentPurchaseRequest extends FormRequest
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
            'category_id' => ['required', Rule::exists('categories', 'id')->where('company_id', $companyId)->where('kind', 'equipment')],
            'name'        => ['required', 'string', 'max:150'],
            'qty'         => ['nullable', ...FinancialRules::qty()],
            'unit_price'  => ['required', ...FinancialRules::amount(0)],
            'date'        => ['required', ...FinancialRules::date()],

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

    protected function prepareForValidation(): void
    {
        $this->merge(['qty' => $this->qty ?: 1]);
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

            $total = round((float) ($this->input('qty') ?: 1) * (float) $this->input('unit_price', 0), 2);

            $this->rejectTotalAboveCeiling($validator, $total, 'unit_price');
            $this->rejectAmountNowAboveTotal($validator, $total);
        });
    }
}
