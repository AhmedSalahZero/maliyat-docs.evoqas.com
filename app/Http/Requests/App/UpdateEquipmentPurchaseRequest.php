<?php

namespace App\Http\Requests\App;

use App\Http\Requests\Concerns\GuardsDocumentTotal;
use App\Support\FinancialRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEquipmentPurchaseRequest extends FormRequest
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
            // A due date entered wrongly at creation could never be
            // corrected — update() simply didn't accept the field.
            // Nullable so a bill can also be moved back to "no due
            // date" rather than only forward.
            'due_date' => ['nullable', 'date', 'after_or_equal:date', 'before_or_equal:'.FinancialRules::latestAllowedDueDate()],
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
