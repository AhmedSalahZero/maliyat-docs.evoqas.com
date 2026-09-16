<?php

namespace App\Http\Requests\App;

use App\Http\Requests\Concerns\GuardsRawMaterialStock;
use App\Support\FinancialRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — StoreProductionOrderRequest
//  Used by App\Http\Controllers\App\ProductionOrderController::store()
//
//  "Made {qty} {product} today: used {materials...}, labor {X}."
//  The "other costs" repeater is parked — see
//  ProductionOrderService's class doc comment.
// ══════════════════════════════════════════════════════════════════
class StoreProductionOrderRequest extends FormRequest
{
    use GuardsRawMaterialStock;

    public function authorize(): bool
    {
        return (bool) $this->user()?->company_id;
    }

    public function rules(): array
    {
        $companyId = $this->user()->company_id;

        return [
            'item_id' => [
                'required',
                Rule::exists('items', 'id')->where('company_id', $companyId)->where('type', 'product'),
            ],
            'date' => ['required', ...FinancialRules::date()],
            'qty_produced' => ['required', ...FinancialRules::qty()],

            'materials'             => ['required', 'array', 'min:1'],
            'materials.*.item_id'   => [
                'required',
                Rule::exists('items', 'id')->where('company_id', $companyId)->where('type', 'raw_material'),
            ],
            'materials.*.qty'       => ['required', ...FinancialRules::qty()],

            'labor_cost' => ['nullable', ...FinancialRules::amount(0)],

            // ── PARKED: the "other costs" repeater ────────────────
            // The form no longer sends these and
            // ProductionOrderService::cost() zeroes them regardless,
            // so leaving the rules live would only advertise a field
            // that does nothing. See that service's class doc comment
            // for why a workshop's run is materials + labor only.
            //
            // 'other_costs'               => ['nullable', 'array'],
            // 'other_costs.*.category_id' => [
            //     'required_with:other_costs.*.amount',
            //     'nullable',
            //     Rule::exists('categories', 'id')->where('company_id', $companyId)->where('kind', 'expense'),
            // ],
            // 'other_costs.*.amount'      => ['nullable', ...FinancialRules::amount(0)],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->rejectOverusingRawMaterials($validator, null);
        });
    }
}
