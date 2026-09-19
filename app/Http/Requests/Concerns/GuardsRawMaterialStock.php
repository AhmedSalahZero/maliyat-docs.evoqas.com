<?php

namespace App\Http\Requests\Concerns;

use App\Models\Item;
use App\Models\ProductionOrder;
use App\Support\FinancialRules;
use Illuminate\Contracts\Validation\Validator;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — GuardsRawMaterialStock
//  Location: app/Http/Requests/Concerns/GuardsRawMaterialStock.php
//
//  A Production Order's sibling to GuardsStockLevels — stops a
//  production run consuming more of a raw material than the
//  company actually has in stock, for exactly the reason that
//  trait documents: it either means a purchase was never entered,
//  or a quantity has a typo, and both are worth catching while the
//  form is still open rather than after the negative stock has
//  already corrupted the Inventory Statement and cost figures.
// ══════════════════════════════════════════════════════════════════
trait GuardsRawMaterialStock
{
    /**
     * @param  ProductionOrder|null  $excluding  When editing, the
     *         order being edited — its current material lines are
     *         added back to stock first.
     */
    protected function rejectOverusingRawMaterials(Validator $validator, ?ProductionOrder $excluding = null): void
    {
        $lines = collect($this->input('materials', []));

        $wanted = $lines
            ->filter(fn ($line) => ! empty($line['item_id']))
            ->groupBy('item_id')
            ->map(fn ($rows) => (float) $rows->sum(fn ($row) => (float) ($row['qty'] ?? 0)));

        if ($wanted->isEmpty()) {
            return;
        }

        $items = Item::query()->whereKey($wanted->keys())->get()->keyBy('id');

        $released = $excluding
            ? $excluding->materialLines()->get()->groupBy('item_id')
                ->map(fn ($rows) => (float) $rows->sum('qty'))
            : collect();

        foreach ($wanted as $itemId => $qty) {
            $item = $items->get($itemId);

            if (! $item) {
                continue;
            }

            $available = round($item->currentStock() + (float) $released->get($itemId, 0), 2);

            if ($qty <= $available + FinancialRules::AMOUNT_TOLERANCE) {
                continue;
            }

            foreach ($lines as $index => $line) {
                if ((int) ($line['item_id'] ?? 0) !== (int) $itemId) {
                    continue;
                }

                $validator->errors()->add("materials.{$index}.qty", __('errors.insufficient_stock', [
                    'item'      => $item->name,
                    'available' => rtrim(rtrim(number_format($available, 2), '0'), '.'),
                    'unit'      => $item->base_unit_name ?? '',
                ]));
            }
        }
    }
}
