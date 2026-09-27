<?php

namespace App\Services;

use App\Models\InventoryPurchaseLine;
use App\Models\Item;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderMaterialLine;
use App\Models\SaleLine;
use App\Support\FinancialRules;
use Illuminate\Support\Carbon;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — StockTimeline
//  Location: app/Services/StockTimeline.php
//
//  Answers ONE question before anything that moves stock is saved:
//  "after this change, does this item ever go below zero on ANY day
//  from the change onward?"
//
//  Why it exists. The stock guards used to ask Item::currentStock()
//  with no date — i.e. "how much do we hold TODAY?". That let a sale
//  dated 5 Sep through when the only purchase was dated 10 Sep (the
//  stock is there today, so the check passed). MovingAverageCostingService
//  then rebuilt the item from 5 Sep forward — correctly, as designed
//  — but on 5 Sep there was nothing on hand to take a cost from, so
//  the sale was costed at ZERO and every later unit carried an
//  inflated average. Editing a purchase to a later date, deleting a
//  purchase or production run whose stock was already sold, or
//  resetting opening stock that was already sold, all produced the
//  same hole — and none of them were checked at all.
//
//  The rule this class enforces is the rule the costing engine
//  needs: stock may never be negative at the END of any day. Days are
//  netted exactly the way the costing engine nets them (everything
//  in on a day, then everything out), and the same sources are read
//  (purchases + production in; sales, excluding opening-balance
//  sales, + production materials out).
//
//  Existing bad history is not held against unrelated edits: a day
//  is only reported if the change makes it negative, or MORE
//  negative than it already was.
// ══════════════════════════════════════════════════════════════════
class StockTimeline
{
    /**
     * Net base-unit movement per day for one item (in − out).
     *
     * @return array<string, float>  'Y-m-d' => net qty
     */
    public function dailyMovements(int $itemId): array
    {
        $net = [];
        $add = function (string $date, float $qty) use (&$net): void {
            $net[$date] = ($net[$date] ?? 0.0) + $qty;
        };

        InventoryPurchaseLine::query()
            ->where('item_id', $itemId)
            ->whereHas('inventoryPurchase')
            ->with('inventoryPurchase:id,date')
            ->get()
            ->each(fn (InventoryPurchaseLine $line) => $add(
                $line->inventoryPurchase->date->toDateString(),
                (float) $line->qty * ((float) $line->qty_per_uom ?: 1)
            ));

        ProductionOrder::query()
            ->where('item_id', $itemId)
            ->get(['id', 'date', 'qty_produced'])
            ->each(fn (ProductionOrder $order) => $add(
                $order->date->toDateString(),
                (float) $order->qty_produced
            ));

        SaleLine::query()
            ->where('item_id', $itemId)
            ->whereHas('sale', fn ($q) => $q->where('is_opening_balance', false))
            ->with('sale:id,date')
            ->get()
            ->each(fn (SaleLine $line) => $add(
                $line->sale->date->toDateString(),
                -$line->baseQty()
            ));

        ProductionOrderMaterialLine::query()
            ->where('item_id', $itemId)
            ->whereHas('productionOrder')
            ->with('productionOrder:id,date')
            ->get()
            ->each(fn (ProductionOrderMaterialLine $line) => $add(
                $line->productionOrder->date->toDateString(),
                -(float) $line->qty
            ));

        return $net;
    }

    /**
     * Apply hypothetical changes to an item's history and return the
     * first day that would end below zero because of them, or null
     * if every day is fine.
     *
     * @param  array<int, array{0:string, 1:float}>  $changes
     *         Each is [date 'Y-m-d', signed base qty]. Positive puts
     *         stock in on that day, negative takes it out. To "move"
     *         or "remove" an existing record, pass its current effect
     *         reversed (e.g. deleting a purchase of 100 on 10 Sep is
     *         ['2026-09-10', -100]).
     * @return array{date:string, balance:float, short:float}|null
     */
    public function firstShortfall(int $itemId, array $changes): ?array
    {
        $changes = array_values(array_filter(
            $changes,
            fn (array $change) => abs((float) $change[1]) > FinancialRules::AMOUNT_TOLERANCE
        ));

        if ($changes === []) {
            return null;
        }

        $before = $this->dailyMovements($itemId);
        $after  = $before;

        foreach ($changes as [$date, $qty]) {
            $after[$date] = ($after[$date] ?? 0.0) + (float) $qty;
        }

        $firstChange = min(array_map(fn (array $change) => $change[0], $changes));

        $dates = array_unique(array_merge(array_keys($before), array_keys($after)));
        sort($dates);

        $runningBefore = 0.0;
        $runningAfter  = 0.0;
        $tolerance     = FinancialRules::AMOUNT_TOLERANCE;

        foreach ($dates as $date) {
            $runningBefore += $before[$date] ?? 0.0;
            $runningAfter  += $after[$date] ?? 0.0;

            if ($date < $firstChange) {
                continue;
            }

            $balanceBefore = round($runningBefore, 2);
            $balanceAfter  = round($runningAfter, 2);

            if ($balanceAfter < -$tolerance && $balanceAfter < $balanceBefore - $tolerance) {
                return [
                    'date'    => $date,
                    'balance' => $balanceAfter,
                    'short'   => round(-$balanceAfter, 2),
                ];
            }
        }

        return null;
    }

    /**
     * For changes that take stock AWAY from history (editing or
     * deleting a purchase or production run, resetting opening
     * stock): the plain-language reason the change must be refused,
     * or null when it is safe.
     *
     * @param  array<int, array<int, array{0:string, 1:float}>>  $changesByItem
     *         item id => list of [date, signed base qty] changes.
     */
    public function removalProblem(array $changesByItem): ?string
    {
        foreach ($changesByItem as $itemId => $changes) {
            $shortfall = $this->firstShortfall((int) $itemId, $changes);

            if (! $shortfall) {
                continue;
            }

            $item = Item::query()->find($itemId);

            return __('errors.stock_already_used', [
                'item'  => $item?->name ?? '',
                'short' => self::readableQty($shortfall['short']),
                'unit'  => $item?->base_unit_name ?? '',
                'date'  => self::readableDate($shortfall['date']),
            ]);
        }

        return null;
    }

    /**
     * A submitted date as 'Y-m-d' (the form may send any format the
     * `date` rule accepts), or '' when it can't be read — the date
     * rule has already reported that case.
     */
    public static function normalizeDate(mixed $date): string
    {
        if (! is_string($date) || trim($date) === '') {
            return '';
        }

        try {
            return Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * A date the way people read it in messages ("5 Sep 2026", or
     * the Arabic month name when the app is in Arabic).
     */
    public static function readableDate(string $date): string
    {
        // Laravel keeps Carbon's locale in step with the app's own
        // (English or Arabic), so the month name follows the user.
        return Carbon::parse($date)->translatedFormat('j M Y');
    }

    /**
     * A quantity without trailing zeros ("7", "2.5").
     */
    public static function readableQty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 2), '0'), '.');
    }
}
