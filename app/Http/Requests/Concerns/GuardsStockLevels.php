<?php

namespace App\Http\Requests\Concerns;

use App\Models\Item;
use App\Models\Sale;
use App\Support\FinancialRules;
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
     * zero.
     *
     * @param  Sale|null  $excluding  When editing, the sale being
     *         edited. Its current lines are added back to stock
     *         first, or re-saving an existing sale unchanged would
     *         be rejected for consuming stock it already consumed.
     */
    protected function rejectOversellingStock(Validator $validator, ?Sale $excluding = null): void
    {
        $lines = collect($this->input('lines', []));

        // How much of each item this submission wants, in total —
        // summed first, so three lines of the same item are judged
        // against the stock once rather than each believing it has
        // the whole quantity to itself.
        $wanted = $lines
            ->filter(fn ($line) => ! empty($line['item_id']))
            ->groupBy('item_id')
            ->map(fn ($rows) => (float) $rows->sum(fn ($row) => (float) ($row['qty'] ?? 0)));

        if ($wanted->isEmpty()) {
            return;
        }

        $items = Item::query()->whereKey($wanted->keys())->get()->keyBy('id');

        // What the sale being edited currently holds, per item —
        // released back before asking what is available.
        $released = $excluding
            ? $excluding->lines()->get()->groupBy('item_id')
                ->map(fn ($rows) => (float) $rows->sum('qty'))
            : collect();

        foreach ($wanted as $itemId => $qty) {
            $item = $items->get($itemId);

            if (! $item) {
                continue; // a bad id is already the exists rule's problem
            }

            $available = round($item->currentStock() + (float) $released->get($itemId, 0), 2);

            if ($qty <= $available + FinancialRules::AMOUNT_TOLERANCE) {
                continue;
            }

            // Reported against every line carrying this item, so the
            // message lands on the row the user has to change rather
            // than on the form as a whole.
            foreach ($lines as $index => $line) {
                if ((int) ($line['item_id'] ?? 0) !== (int) $itemId) {
                    continue;
                }

                $validator->errors()->add("lines.{$index}.qty", __('errors.insufficient_stock', [
                    'item'      => $item->name,
                    'available' => rtrim(rtrim(number_format($available, 2), '0'), '.'),
                    'unit'      => $item->base_unit_name ?? '',
                ]));
            }
        }
    }
}
