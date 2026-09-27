<?php

namespace App\Http\Requests\Concerns;

use App\Models\Item;
use App\Models\ProductionOrder;
use App\Services\StockTimeline;
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
     * Stop a run using more of a raw material than was on hand — on
     * the run's own date OR on any later day (see
     * App\Services\StockTimeline for why "today's stock" was not
     * enough).
     *
     * @param  ProductionOrder|null  $excluding  When editing, the
     *         order being edited — its current material lines are
     *         added back on their CURRENT date first.
     */
    protected function rejectOverusingRawMaterials(Validator $validator, ?ProductionOrder $excluding = null): void
    {
        $lines = collect($this->input('materials', []));
        $date  = StockTimeline::normalizeDate($this->input('date'));

        $wanted = $lines
            ->filter(fn ($line) => ! empty($line['item_id']))
            ->groupBy('item_id')
            ->map(fn ($rows) => (float) $rows->sum(fn ($row) => (float) ($row['qty'] ?? 0)));

        if ($wanted->isEmpty() || $date === '') {
            return;
        }

        $items = Item::query()->whereKey($wanted->keys())->get()->keyBy('id');

        $released     = $excluding
            ? $excluding->materialLines()->get()->groupBy('item_id')
                ->map(fn ($rows) => (float) $rows->sum('qty'))
            : collect();
        $releasedDate = $excluding?->date?->toDateString();

        $timeline = app(StockTimeline::class);

        foreach ($wanted as $itemId => $qty) {
            $item = $items->get($itemId);

            if (! $item) {
                continue;
            }

            $changes = [[$date, -$qty]];

            if ($releasedDate && $released->has($itemId)) {
                $changes[] = [$releasedDate, (float) $released->get($itemId)];
            }

            $shortfall = $timeline->firstShortfall((int) $itemId, $changes);

            if (! $shortfall) {
                continue;
            }

            $message = $shortfall['date'] <= $date
                ? __('errors.insufficient_stock', [
                    'item'      => $item->name,
                    'available' => StockTimeline::readableQty(max(0, $qty - $shortfall['short'])),
                    'unit'      => $item->base_unit_name ?? '',
                    'date'      => StockTimeline::readableDate($date),
                ])
                : __('errors.stock_needed_later', [
                    'item'  => $item->name,
                    'short' => StockTimeline::readableQty($shortfall['short']),
                    'unit'  => $item->base_unit_name ?? '',
                    'date'  => StockTimeline::readableDate($shortfall['date']),
                ]);

            foreach ($lines as $index => $line) {
                if ((int) ($line['item_id'] ?? 0) !== (int) $itemId) {
                    continue;
                }

                $validator->errors()->add("materials.{$index}.qty", $message);
            }
        }
    }

    /**
     * Editing a run can also take FINISHED product away — a smaller
     * qty_produced, a later date, or a different product picked. If
     * that product was already sold, those sales would be left with
     * no stock (and costed at zero). Refuse the edit instead.
     */
    protected function rejectRemovingSoldProduct(Validator $validator, ProductionOrder $existing): void
    {
        $date      = StockTimeline::normalizeDate($this->input('date'));
        $newItemId = (int) $this->input('item_id');
        $newQty    = (float) $this->input('qty_produced', 0);

        if ($date === '') {
            return;
        }

        $oldItemId = (int) $existing->item_id;
        $oldDate   = $existing->date->toDateString();
        $oldQty    = (float) $existing->qty_produced;

        $changesByItem = [$oldItemId => [[$oldDate, -$oldQty]]];
        $changesByItem[$newItemId][] = [$date, $newQty];

        $problem = app(StockTimeline::class)->removalProblem($changesByItem);

        if ($problem) {
            $validator->errors()->add('qty_produced', $problem);
        }
    }
}
