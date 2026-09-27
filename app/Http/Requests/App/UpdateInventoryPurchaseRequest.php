<?php

namespace App\Http\Requests\App;

use App\Rules\HalfStepQuantity;
use App\Http\Requests\Concerns\GuardsDocumentTotal;
use App\Models\InventoryPurchase;
use App\Services\StockTimeline;
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
            // Whole or half only (1, 1.5, 2, 2.5 …) — see HalfStepQuantity.
            'lines.*.qty'            => ['required', ...FinancialRules::qty(0.5), new HalfStepQuantity],
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

            $this->rejectRemovingStockAlreadySold($validator);
        });
    }

    /**
     * Editing a purchase can take stock away from the past — a
     * smaller quantity, a later date, or an item swapped for
     * another. If that stock was already sold or used in production,
     * those sales would be left with nothing to take a cost from and
     * be costed at zero (see App\Services\StockTimeline). Refuse the
     * edit and say which item and day would be short.
     */
    private function rejectRemovingStockAlreadySold($validator): void
    {
        $purchase = $this->route('inventoryPurchase');
        $newDate  = StockTimeline::normalizeDate($this->input('date'));

        if (! $purchase instanceof InventoryPurchase || $newDate === '') {
            return;
        }

        $oldDate = $purchase->date->toDateString();

        // [item id => list of [date, signed base qty]]: the old lines
        // come OUT on the old date, the new lines go IN on the new one.
        $changesByItem = [];

        foreach ($purchase->lines()->get() as $line) {
            $changesByItem[(int) $line->item_id][] = [$oldDate, -((float) $line->qty * ((float) $line->qty_per_uom ?: 1))];
        }

        foreach ((array) $this->input('lines', []) as $line) {
            if (empty($line['item_id'])) {
                continue;
            }

            $changesByItem[(int) $line['item_id']][] = [$newDate, (float) ($line['qty'] ?? 0) * ((float) ($line['qty_per_uom'] ?? 1) ?: 1)];
        }

        $problem = app(StockTimeline::class)->removalProblem($changesByItem);

        if ($problem) {
            $validator->errors()->add('lines', $problem);
        }
    }
}
