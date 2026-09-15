<?php

namespace App\Http\Requests\App;

use App\Http\Requests\Concerns\GuardsDocumentTotal;
use App\Support\FinancialRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInventoryPurchaseRequest extends FormRequest
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
            'vendor_id' => ['required', Rule::exists('vendors', 'id')->where('company_id', $companyId)],
            'date'      => ['required', ...FinancialRules::date()],

            'lines'                  => ['required', 'array', 'min:1'],
            'lines.*.item_id'        => ['required', Rule::exists('items', 'id')->where('company_id', $companyId)],
            'lines.*.qty'            => ['required', ...FinancialRules::qty()],
            'lines.*.uom'            => ['nullable', 'string', 'max:40'],
            'lines.*.qty_per_uom'    => ['nullable', ...FinancialRules::qty()],
            'lines.*.base_unit_name' => ['nullable', 'string', 'max:40'],
            'lines.*.unit_price'     => ['required', ...FinancialRules::amount(0)],

            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // A due date entered wrongly at creation could never be
            // corrected — update() simply didn't accept the field.
            // Nullable so a bill can also be moved back to "no due
            // date" rather than only forward.
            'due_date' => ['nullable', 'date', 'after_or_equal:date', 'before_or_equal:'.FinancialRules::latestAllowedDueDate()],
        ];
    }

    /**
     * Checks that need the lines added up first — see
     * GuardsDocumentTotal.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $subtotal = $this->documentLineTotal();
            $this->rejectTotalAboveCeiling($validator, $subtotal);

            $vatRate = (float) ($this->input('vat_rate') ?? 0);
            $total   = round($subtotal + round($subtotal * $vatRate / 100, 2), 2);
            $this->rejectAmountNowAboveTotal($validator, $total);
        });
    }
}
