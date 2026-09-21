<?php

namespace App\Services;

use App\Models\Account;
use App\Models\InventoryPurchaseLine;
use App\Models\InventoryStockLedger;
use App\Models\Item;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderMaterialLine;
use App\Models\Sale;
use App\Models\SaleLine;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — MovingAverageCostingService
//
//  The true moving-average ("Option A") costing engine — see the
//  plan doc for the full reasoning this implements:
//
//    - One pool of quantity + value per item, recalculated one
//      calendar day at a time: that day's inbound stock (purchases,
//      production output) is added and the average recalculated
//      BEFORE that day's outbound stock (sales, production
//      material consumption) is priced at it.
//    - Sold/consumed stock actually leaves the pool — unlike
//      Item::averagePurchaseCost() — REMOVED (Sep 2026), this
//      service is now the only source of an item's cost — this
//      recalculates the item's actual day-by-day stock pool instead.
//    - No closed periods. Editing or deleting a past sale, purchase,
//      or production order re-runs the affected item's ledger
//      forward from that date to today, and cascades into anything
//      downstream (production consuming that item, and the
//      finished product that production feeds) automatically.
//
//  Entry point for every caller: onItemMovementChanged(). Everything
//  else here is a private step of that one operation.
// ══════════════════════════════════════════════════════════════════
class MovingAverageCostingService
{
    /** Recursion guard for the raw-material → product cascade. */
    private const MAX_CASCADE_DEPTH = 5;

    public function __construct(
        private readonly JournalService $journal,
    ) {}

    /**
     * The one method every caller (Sale/InventoryPurchase/
     * ProductionOrder controllers) needs to know about: "this item
     * had a transaction change on or after this date — make
     * everything downstream of that consistent again."
     *
     * Recalculates the item's own ledger, corrects the stored cost
     * on every affected sale line and production material line,
     * reverses and reposts the accounting ledger entries for any
     * sale or production order whose cost actually changed, and —
     * when this item feeds a production run — cascades into the
     * product that run makes, so the correction propagates all the
     * way to the finished goods it eventually becomes.
     */
    public function onItemMovementChanged(int $companyId, int $itemId, string $fromDate, int $depth = 0): void
    {
        if ($depth > self::MAX_CASCADE_DEPTH) {
            // Should never happen (materials feed products, products
            // don't ordinarily feed themselves) — a hard stop rather
            // than a silent infinite recursion if the data ever does
            // something unexpected.
            return;
        }

        DB::transaction(function () use ($companyId, $itemId, $fromDate, $depth) {
            $result = $this->recalculateItem($companyId, $itemId, $fromDate);

            foreach ($result['affected_sale_ids'] as $saleId) {
                $this->repriceSaleCogs($companyId, $saleId);
            }

            foreach ($result['affected_production_order_ids'] as $orderId) {
                $this->repriceProductionOrder($companyId, $orderId, $depth);
            }
        });
    }

    /**
     * Rebuilds ONE item's stock ledger, day by day, from $fromDate
     * to its latest movement date. Every sale line and production
     * material line for this item dated on/after $fromDate gets its
     * stored cost rewritten to match.
     *
     * @return array{affected_sale_ids: int[], affected_production_order_ids: int[]}
     */
    private function recalculateItem(int $companyId, int $itemId, string $fromDate): array
    {
        [$beginningQty, $beginningValue] = $this->startingBalance($companyId, $itemId, $fromDate);

        // Rebuilt wholesale from here forward — never patched in
        // place, so there is no way for a stale row to survive a
        // recalculation that should have replaced it. Filtered by
        // company_id explicitly (not just the item's own global
        // scope) — same defensive habit as DashboardController's
        // raw queries, cheap insurance against ever touching another
        // company's ledger.
        InventoryStockLedger::query()
            ->where('company_id', $companyId)
            ->where('item_id', $itemId)
            ->where('date', '>=', $fromDate)
            ->delete();

        $inboundPurchases = InventoryPurchaseLine::query()
            ->where('item_id', $itemId)
            ->whereHas('inventoryPurchase', fn ($q) => $q->where('company_id', $companyId)->where('date', '>=', $fromDate))
            ->with('inventoryPurchase:id,date')
            ->get()
            ->map(fn (InventoryPurchaseLine $line) => [
                'date'  => $line->inventoryPurchase->date->toDateString(),
                'qty'   => (float) $line->qty * (float) $line->qty_per_uom,
                'value' => (float) $line->line_total,
            ]);

        $inboundProduction = ProductionOrder::query()
            ->where('company_id', $companyId)
            ->where('item_id', $itemId)
            ->where('date', '>=', $fromDate)
            ->get(['id', 'date', 'qty_produced', 'total_cost'])
            ->map(fn (ProductionOrder $order) => [
                'date'  => $order->date->toDateString(),
                'qty'   => (float) $order->qty_produced,
                'value' => (float) $order->total_cost,
            ]);

        $inboundByDate = $inboundPurchases->concat($inboundProduction)
            ->groupBy('date')
            ->map(fn ($rows) => [
                'qty'   => (float) $rows->sum('qty'),
                'value' => (float) $rows->sum('value'),
            ]);

        /** @var \Illuminate\Support\Collection<int, SaleLine> $outboundSaleLines */
        $outboundSaleLines = SaleLine::query()
            ->where('item_id', $itemId)
            ->whereHas('sale', fn ($q) => $q->where('company_id', $companyId)->where('date', '>=', $fromDate)->where('is_opening_balance', false))
            ->with('sale:id,date')
            ->get();

        /** @var \Illuminate\Support\Collection<int, ProductionOrderMaterialLine> $outboundMaterialLines */
        $outboundMaterialLines = ProductionOrderMaterialLine::query()
            ->where('item_id', $itemId)
            ->whereHas('productionOrder', fn ($q) => $q->where('company_id', $companyId)->where('date', '>=', $fromDate))
            ->with('productionOrder:id,date')
            ->get();

        $outboundDates = $outboundSaleLines->map(fn (SaleLine $l) => $l->sale->date->toDateString())
            ->concat($outboundMaterialLines->map(fn (ProductionOrderMaterialLine $l) => $l->productionOrder->date->toDateString()));

        $dates = $inboundByDate->keys()
            ->concat($outboundDates)
            ->unique()
            ->sort()
            ->values();

        $affectedSaleIds = collect();
        $affectedProductionOrderIds = collect();

        $runningQty   = $beginningQty;
        $runningValue = $beginningValue;

        foreach ($dates as $date) {
            $dayBeginningQty   = $runningQty;
            $dayBeginningValue = $runningValue;

            $qtyIn   = (float) ($inboundByDate[$date]['qty'] ?? 0);
            $valueIn = (float) ($inboundByDate[$date]['value'] ?? 0);

            $availableQty   = round($dayBeginningQty + $qtyIn, 2);
            $availableValue = round($dayBeginningValue + $valueIn, 2);

            // No stock available to average — an item never bought/
            // produced yet still shouldn't block a sale (same
            // "treated as free" fallback the app already used
            // before this engine existed).
            $averageCost = $availableQty > 0 ? round($availableValue / $availableQty, 4) : 0.0;

            $daySaleLines = $outboundSaleLines->filter(fn (SaleLine $l) => $l->sale->date->toDateString() === $date);
            $dayMaterialLines = $outboundMaterialLines->filter(fn (ProductionOrderMaterialLine $l) => $l->productionOrder->date->toDateString() === $date);

            // A sale line's own qty is in whatever unit it was
            // invoiced in (e.g. Carton) — baseQty() converts it to
            // base units before it can be weighed against the pool,
            // same as an inbound purchase line already does.
            $qtyOut = round((float) $daySaleLines->sum(fn (SaleLine $l) => $l->baseQty()) + (float) $dayMaterialLines->sum('qty'), 2);

            foreach ($daySaleLines as $line) {
                // unit_cost is always cost PER BASE UNIT (per kg,
                // say) regardless of which unit the line was sold
                // in — repriceSaleCogs() multiplies it back out by
                // this line's base quantity, not its raw qty.
                if ((float) ($line->unit_cost ?? -1) !== $averageCost) {
                    $affectedSaleIds->push($line->sale_id);
                }
                $line->forceFill(['unit_cost' => $averageCost])->save();
            }

            foreach ($dayMaterialLines as $line) {
                $lineTotal = round((float) $line->qty * $averageCost, 2);
                if ((float) ($line->unit_cost_snapshot ?? -1) !== $averageCost) {
                    $affectedProductionOrderIds->push($line->production_order_id);
                }
                $line->forceFill(['unit_cost_snapshot' => $averageCost, 'line_total' => $lineTotal])->save();
            }

            $valueOut = round($qtyOut * $averageCost, 2);

            $endingQty   = round($availableQty - $qtyOut, 2);
            $endingValue = round($availableValue - $valueOut, 2);

            InventoryStockLedger::create([
                'company_id'      => $companyId,
                'item_id'         => $itemId,
                'date'            => $date,
                'beginning_qty'   => $dayBeginningQty,
                'beginning_value' => $dayBeginningValue,
                'qty_in'          => $qtyIn,
                'value_in'        => $valueIn,
                'average_cost'    => $averageCost,
                'qty_out'         => $qtyOut,
                'value_out'       => $valueOut,
                'ending_qty'      => $endingQty,
                'ending_value'    => $endingValue,
            ]);

            $runningQty   = $endingQty;
            $runningValue = $endingValue;
        }

        return [
            'affected_sale_ids' => $affectedSaleIds->unique()->values()->all(),
            'affected_production_order_ids' => $affectedProductionOrderIds->unique()->values()->all(),
        ];
    }

    /**
     * The balance to carry in as of the start of $fromDate — the
     * previous ledger row's ending balance, or zero if this item has
     * no movement before $fromDate at all.
     *
     * @return array{0: float, 1: float} [qty, value]
     */
    private function startingBalance(int $companyId, int $itemId, string $fromDate): array
    {
        $previous = InventoryStockLedger::query()
            ->where('company_id', $companyId)
            ->where('item_id', $itemId)
            ->where('date', '<', $fromDate)
            ->orderByDesc('date')
            ->first();

        return $previous
            ? [(float) $previous->ending_qty, (float) $previous->ending_value]
            : [0.0, 0.0];
    }

    /**
     * A sale's total Cost of Goods Sold = the sum of every one of
     * its lines' (qty × stored unit_cost) — across every item the
     * sale contains, not just the one item that triggered this
     * recalculation, since a single sale can mix several products.
     * Reposts only if that total actually changed from what's
     * currently on the books.
     */
    private function repriceSaleCogs(int $companyId, int $saleId): void
    {
        $sale = Sale::query()->find($saleId);

        if (! $sale) {
            return;
        }

        // qty * qty_per_uom converts each line back to the base
        // units unit_cost is actually priced per — a line sold in
        // Cartons must be costed on the kg it actually took out of
        // stock, not on the number of cartons on the invoice.
        $newTotal = round((float) SaleLine::query()
            ->where('sale_id', $saleId)
            ->whereNotNull('unit_cost')
            ->selectRaw('SUM(qty * qty_per_uom * unit_cost) as total')
            ->value('total'), 2);

        $currentlyPosted = $this->currentCogsPostedFor($sale);

        if (abs($newTotal - $currentlyPosted) < 0.01) {
            return;
        }

        $this->journal->reverseCostOfGoodsSoldFor($sale);

        if ($newTotal > 0) {
            $this->journal->postCostOfGoodsSold($sale, $newTotal);
        }
    }

    /**
     * A production order's own cost is recomputed from its (now
     * freshly repriced) material lines plus its unchanged labor
     * estimate, exactly the same formula ProductionOrderService::cost()
     * uses when the order is first created — kept here rather than
     * shared, since that method reads from request input and this
     * one reads from what's already on the row.
     */
    private function repriceProductionOrder(int $companyId, int $orderId, int $depth): void
    {
        $order = ProductionOrder::query()->with('materialLines')->find($orderId);

        if (! $order) {
            return;
        }

        $newMaterialCost = round((float) $order->materialLines->sum(
            fn (ProductionOrderMaterialLine $line) => (float) $line->qty * (float) $line->unit_cost_snapshot
        ), 2);

        $laborCost = (float) $order->labor_cost;
        $newTotalCost = round($newMaterialCost + $laborCost + (float) $order->other_cost_total, 2);
        $newUnitCost = (float) $order->qty_produced > 0
            ? round($newTotalCost / (float) $order->qty_produced, 4)
            : 0.0;

        $changed = abs($newTotalCost - (float) $order->total_cost) >= 0.01;

        if ($changed) {
            $this->journal->reverseEntriesFor($order);
        }

        $order->forceFill([
            'material_cost' => $newMaterialCost,
            'total_cost'    => $newTotalCost,
            'unit_cost'     => $newUnitCost,
        ])->save();

        if ($changed) {
            $this->journal->postProductionOrder($order->fresh(['materialLines']));

            // The batch this order produced is now worth a different
            // amount going into the FINISHED item's own pool — that
            // item's ledger needs recalculating forward from this
            // order's own date, exactly like any other inbound
            // movement changing. This is the raw-material → product
            // hop in the two-stage chain (see the plan doc).
            $this->onItemMovementChanged($companyId, (int) $order->item_id, $order->date->toDateString(), $depth + 1);
        }
    }

    /**
     * What's currently posted to Cost of Goods Sold against this
     * sale, net of any prior reversal — so repriceSaleCogs() can
     * tell whether a repost is actually needed.
     */
    private function currentCogsPostedFor(Sale $sale): float
    {
        return (float) DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.source_type', Sale::class)
            ->where('journal_entries.source_id', $sale->id)
            ->where('accounts.code', Account::COST_OF_GOODS_SOLD)
            ->selectRaw('COALESCE(SUM(journal_lines.debit) - SUM(journal_lines.credit), 0) as net')
            ->value('net');
    }
}
