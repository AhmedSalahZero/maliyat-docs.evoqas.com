<?php

namespace App\Http\Requests\App;

use App\Http\Requests\Concerns\GuardsRawMaterialStock;
use App\Support\FinancialRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — UpdateProductionOrderRequest
//  Used by App\Http\Controllers\App\ProductionOrderController::update()
//
//  Correcting a production run that was entered wrong.
//
//  There was no way to do this: the service had create() and
//  delete() and nothing between them, so fixing a mistyped quantity
//  meant deleting the run and entering it again. That is not an
//  equivalent action — deleting is restricted to a company admin
//  precisely because it destroys the record and its ledger trail,
//  and it left an employee who had made a typo unable to fix their
//  own work.
//
//  Same rules as creating one, with a single difference: the stock
//  check releases THIS order's current material lines back before
//  asking what is available, or re-saving a run unchanged would be
//  refused for consuming material it had already consumed. That is
//  what GuardsRawMaterialStock's $excluding argument was always for
//  — it has been sitting unused since it was written.
// ══════════════════════════════════════════════════════════════════
class UpdateProductionOrderRequest extends FormRequest
{
    use GuardsRawMaterialStock;

    public function authorize(): bool
    {
        // The company scope on route-model binding has already 404'd
        // another company's order before we get here.
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
            'date'         => ['required', ...FinancialRules::date()],
            'qty_produced' => ['required', ...FinancialRules::qty()],

            'materials'           => ['required', 'array', 'min:1'],
            'materials.*.item_id' => [
                'required',
                Rule::exists('items', 'id')->where('company_id', $companyId)->where('type', 'raw_material'),
            ],
            'materials.*.qty'     => ['required', ...FinancialRules::qty()],

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

            // The order being edited releases its own materials first.
            $this->rejectOverusingRawMaterials($validator, $this->route('productionOrder'));
        });
    }
}
