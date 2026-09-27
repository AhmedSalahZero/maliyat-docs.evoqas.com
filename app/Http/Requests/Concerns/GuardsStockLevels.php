<?php

namespace App\Http\Requests\Concerns;

use App\Models\Item;
use App\Models\Sale;
use App\Services\StockTimeline;
use Illuminate\Contracts\Validation\Validator;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — GuardsStockLevels
//  Location: app/Http/Requests/Concerns/GuardsStockLevels.php
//
//  Stops a sale taking more of an item out of stock than the company
//  has.
//
//  Nothing checked this. Selling 500 of something never bought left
//  the Inventory Statement showing a stock of -500, and the report
//  even carries an `is_negative` flag to badge the row — the state
//  was expected and displayed, but never prevented at the point
//  where somebody could still fix it. It also quietly corrupts the
//  accounts: Cost of Goods Sold is priced from an item's weighted
//  average purchase cost, and an item with no purchases has no
//  average, so those sales post revenue with no cost against it and
//  overstate profit.
//
//  In practice a negative figure almost always means one of two
//  things, and both are worth catching while the form is still open:
//  the purchase was never entered, or the quantity has a typo.
//
//  Free-text lines (no item_id) are untouched — a service has no
//  stock to run out of. So is an item the company genuinely holds
//  enough of.
// ══════════════════════════════════════════════════════════════════
trait GuardsStockLevels
{
    /**
     * Add an error to any sale line that would take an item below
     * zero — on the sale's own date OR on any later day.
     *
     * Checking only "today's" stock (what this did before) let a
     * sale dated before the goods arrived straight through: the
     * stock is there today, so the check passed, and the costing
     * engine then had nothing on that day to take a cost from and
     * costed the sale at zero. See App\Services\StockTimeline.
     *
     * @param  Sale|null  $excluding  When editing, the sale being
     *         edited. Its current lines are added back on their
     *         CURRENT date first, or re-saving an existing sale
     *         unchanged would be rejected for consuming stock it
     *         already consumed — and moving a sale to another date
     *         is judged as "put back on the old date, take out on the
     *         new one".
     */
    protected function rejectOversellingStock(Validator $validator, ?Sale $excluding = null): void
    {
        $lines = collect($this->input('lines', []));
        $date  = StockTimeline::normalizeDate($this->input('date'));

        // How much of each item this submission wants, in BASE
        // units, in total — summed first, so three lines of the
        // same item are judged against the stock once rather than
        // each believing it has the whole quantity to itself. A
        // line sold in Cartons wants qty * qty_per_uom of the item's
        // actual base unit (e.g. kg), not "qty" cartons of stock.
        $wanted = $lines
            ->filter(fn ($line) => ! empty($line['item_id']))
            ->groupBy('item_id')
            ->map(fn ($rows) => (float) $rows->sum(function ($row) {
                $qtyPerUom = (float) ($row['qty_per_uom'] ?? 1) ?: 1;

                return (float) ($row['qty'] ?? 0) * $qtyPerUom;
            }));

        if ($wanted->isEmpty() || $date === '') {
            return;
        }

        $items = Item::query()->whereKey($wanted->keys())->get()->keyBy('id');

        // What the sale being edited currently holds, per item, in
        // base units, and on which date — released back before
        // asking what is available.
        $released     = $excluding
            ? $excluding->lines()->get()->groupBy('item_id')
                ->map(fn ($rows) => (float) $rows->sum(fn ($row) => $row->baseQty()))
            : collect();
        $releasedDate = $excluding?->date?->toDateString();

        $timeline = app(StockTimeline::class);

        foreach ($wanted as $itemId => $qty) {
            $item = $items->get($itemId);

            if (! $item) {
                continue; // a bad id is already the exists rule's problem
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
                // Not enough on the sale's own day.
                ? __('errors.insufficient_stock', [
                    'item'      => $item->name,
                    'available' => StockTimeline::readableQty(max(0, $qty - $shortfall['short'])),
                    'unit'      => $item->base_unit_name ?? '',
                    'date'      => StockTimeline::readableDate($date),
                ])
                // Enough on the day, but a LATER sale or production
                // run would then be left without stock.
                : __('errors.stock_needed_later', [
                    'item'  => $item->name,
                    'short' => StockTimeline::readableQty($shortfall['short']),
                    'unit'  => $item->base_unit_name ?? '',
                    'date'  => StockTimeline::readableDate($shortfall['date']),
                ]);

            // Reported against every line carrying this item, so the
            // message lands on the row the user has to change rather
            // than on the form as a whole.
            foreach ($lines as $index => $line) {
                if ((int) ($line['item_id'] ?? 0) !== (int) $itemId) {
                    continue;
                }

                $validator->errors()->add("lines.{$index}.qty", $message);
            }
        }
    }

    /**
     * A sale line with no item skips the stock check and records no
     * cost of goods sold — right for a SERVICE, wrong for goods: a
     * physical product sold as a "free text" line makes that sale look
     * 100% profit and leaves the stock count too high (audit finding
     * M8).
     *
     * So a company that sells only goods (Trading and/or Production,
     * no Service) must pick the item on every line. A company that
     * also offers services keeps its item-less lines — those ARE its
     * service sales, and the Sales screen labels them as such.
     */
    protected function rejectLinesWithoutItem(Validator $validator): void
    {
        $company = $this->user()?->company;

        if (! $company || $company->hasBusinessType('service')) {
            return;
        }

        foreach ((array) $this->input('lines', []) as $index => $line) {
            if (empty($line['item_id'])) {
                $validator->errors()->add("lines.{$index}.item_id", __('errors.sale_line_needs_item'));
            }
        }
    }

    /**
     * A line sold in a PACKAGING unit (a carton, a sack — anything worth
     * more than one base unit) takes whole or half quantities, the same
     * rule purchases already follow (HalfStepQuantity). Sold in the base
     * unit (kg, piece) any quantity to 0.01 is still fine. Before, a sale
     * could be 1.37 cartons while a purchase could only be 1 or 1.5
     * (low finding 11).
     */
    protected function rejectFractionalPackagingQty(Validator $validator): void
    {
        foreach ((array) $this->input('lines', []) as $index => $line) {
            $perUnit = (float) ($line['qty_per_uom'] ?? 1);
            $qty     = $line['qty'] ?? null;

            if ($perUnit <= 1 || ! is_numeric($qty)) {
                continue;
            }

            $doubled = (float) $qty * 2;

            if (abs($doubled - round($doubled)) > 0.000001) {
                $validator->errors()->add("lines.{$index}.qty", __('validation.half_step_qty'));
            }
        }
    }
}
