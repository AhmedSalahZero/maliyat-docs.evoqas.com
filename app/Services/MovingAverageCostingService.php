<?php

namespace App\Services;

use App\Models\Account;
use App\Models\InventoryPurchaseLine;
use App\Models\InventoryStockLedger;
use App\Models\Item;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderMaterialLine;
use App\Models\PurchaseReturnLine;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\SaleReturn;
use App\Models\SaleReturnLine;
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
//  RETURNS (added Oct 2026):
//    - A SALES RETURN is inbound stock on the RETURN date, valued at
//      the cost the original sale took those goods out at (the sale
//      line's unit_cost) — never at the day's average. That cancels
//      exactly the profit the sale showed, leaves earlier months
//      untouched, and keeps stock value = quantity x cost. If the
//      sale line is later repriced, the return follows it (its
//      stored unit_cost and its Cost-of-Goods-Sold reversal are
//      corrected the same way a sale's COGS is).
//      A return on the SAME day as its sale is valued at the day's
//      average cost without the return — adding stock at exactly the
//      average cannot change the average, which is also the cost the
//      sale line gets that day.
//    - A PURCHASE RETURN is outbound stock on the return date,
//      valued at the original purchase price (fixed, stored on the
//      return line, as its line total — the same figure the ledger
//      credits to Inventory). The pool's average is then recomputed from what
//      is left.
//
//  Entry point for every caller: onItemMovementChanged(). Everything
//  else here is a private step of that one operation.
// ══════════════════════════════════════════════════════════════════
class MovingAverageCostingService
{
    /** Recursion guard for the raw-material → product cascade. */
    private const MAX_CASCADE_DEPTH = 5;

    /**
     * Quantities in the stock ledger are kept to 4 decimals (audit
     * M9). With 2, a purchase of 3 cartons × 0.333 kg, or a sale of
     * 1.5 × 0.75, lost part of a unit every time, so over months the
     * quantity on hand drifted away from what was really bought and
     * sold. Money stays at 2 decimals (cents); the average cost at 4.
     */
    public const QTY_DECIMALS = 4;

    /** One outbound line's cost, rounded to the cent as posted. */
    public static function lineCost(float $baseQty, float $unitCost): float
    {
        return round($baseQty * $unitCost, 2);
    }

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

            foreach ($result['affected_sale_return_ids'] as $returnId) {
                $this->repriceSaleReturnCogs($companyId, $returnId);
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
     * @return array{affected_sale_ids: int[], affected_sale_return_ids: int[], affected_production_order_ids: int[]}
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
                'qty'   => round((float) $line->qty * (float) $line->qty_per_uom, self::QTY_DECIMALS),
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

        // Sales returns put goods back (inbound, on the return date);
        // purchase returns send goods back to the supplier (outbound,
        // on the return date). Both are dated by the RETURN, never by
        // the original document.
        /** @var \Illuminate\Support\Collection<int, SaleReturnLine> $saleReturnLines */
        $saleReturnLines = SaleReturnLine::query()
            ->where('item_id', $itemId)
            ->whereHas('saleReturn', fn ($q) => $q->where('company_id', $companyId)->where('date', '>=', $fromDate))
            ->with(['saleReturn:id,date,sale_id', 'saleReturn.sale:id,date', 'saleLine:id,sale_id,unit_cost'])
            ->get();

        /** @var \Illuminate\Support\Collection<int, PurchaseReturnLine> $purchaseReturnLines */
        $purchaseReturnLines = PurchaseReturnLine::query()
            ->where('item_id', $itemId)
            ->whereHas('purchaseReturn', fn ($q) => $q->where('company_id', $companyId)->where('date', '>=', $fromDate))
            ->with('purchaseReturn:id,date')
            ->get();

        $outboundDates = $outboundSaleLines->map(fn (SaleLine $l) => $l->sale->date->toDateString())
            ->concat($outboundMaterialLines->map(fn (ProductionOrderMaterialLine $l) => $l->productionOrder->date->toDateString()))
            ->concat($purchaseReturnLines->map(fn (PurchaseReturnLine $l) => $l->purchaseReturn->date->toDateString()));

        $dates = $inboundByDate->keys()
            ->concat($saleReturnLines->map(fn (SaleReturnLine $l) => $l->saleReturn->date->toDateString()))
            ->concat($outboundDates)
            ->unique()
            ->sort()
            ->values();

        $affectedSaleIds = collect();
        $affectedSaleReturnIds = collect();
        $affectedProductionOrderIds = collect();

        $runningQty   = $beginningQty;
        $runningValue = $beginningValue;

        foreach ($dates as $date) {
            $dayBeginningQty   = $runningQty;
            $dayBeginningValue = $runningValue;

            $qtyIn   = (float) ($inboundByDate[$date]['qty'] ?? 0);
            $valueIn = (float) ($inboundByDate[$date]['value'] ?? 0);

            // ── Sales returns coming back today ──────────────────
            // Returns of a sale made on an EARLIER day go back at the
            // cost that sale took them out at (read live from the sale
            // line, which this same pass may just have repriced).
            $dayReturnLines = $saleReturnLines->filter(fn (SaleReturnLine $l) => $l->saleReturn->date->toDateString() === $date);
            $sameDayReturnLines = $dayReturnLines->filter(fn (SaleReturnLine $l) => $l->saleReturn->sale?->date?->toDateString() === $date);
            $earlierSaleReturnLines = $dayReturnLines->diff($sameDayReturnLines);

            foreach ($earlierSaleReturnLines as $line) {
                $cost = $this->originalSaleCost($line, $outboundSaleLines);
                $this->stampReturnCost($line, $cost, $affectedSaleReturnIds);
                $qtyIn   += $line->baseQty();
                $valueIn += self::lineCost($line->baseQty(), $cost);
            }

            $availableQty   = round($dayBeginningQty + $qtyIn, self::QTY_DECIMALS);
            $availableValue = round($dayBeginningValue + $valueIn, 2);

            // Returns of a sale made TODAY: valued at today's average
            // as it stands without them. Putting stock back at exactly
            // the average leaves the average unchanged — and that is
            // the cost today's sale line gets, so the two cancel.
            if ($sameDayReturnLines->isNotEmpty()) {
                $baseAverage = $availableQty > 0 ? max(0.0, round($availableValue / $availableQty, 4)) : null;

                foreach ($sameDayReturnLines as $line) {
                    $cost = $baseAverage ?? $this->originalSaleCost($line, $outboundSaleLines);
                    $this->stampReturnCost($line, $cost, $affectedSaleReturnIds);
                    $qtyIn   += $line->baseQty();
                    $valueIn += self::lineCost($line->baseQty(), $cost);
                    $availableQty   = round($availableQty + $line->baseQty(), self::QTY_DECIMALS);
                    $availableValue = round($availableValue + self::lineCost($line->baseQty(), $cost), 2);
                }
            }

            // No stock available to average — an item never bought/
            // produced yet still shouldn't block a sale (same
            // "treated as free" fallback the app already used
            // before this engine existed). Never negative: a pool
            // left with stock but no value (a purchase return taken
            // out at a price above the remaining average) costs
            // nothing rather than a negative amount.
            $averageCost = $availableQty > 0 ? max(0.0, round($availableValue / $availableQty, 4)) : 0.0;

            $daySaleLines = $outboundSaleLines->filter(fn (SaleLine $l) => $l->sale->date->toDateString() === $date);
            $dayMaterialLines = $outboundMaterialLines->filter(fn (ProductionOrderMaterialLine $l) => $l->productionOrder->date->toDateString() === $date);

            // A sale line's own qty is in whatever unit it was
            // invoiced in (e.g. Carton) — baseQty() converts it to
            // base units before it can be weighed against the pool,
            // same as an inbound purchase line already does.
            $dayPurchaseReturnLines = $purchaseReturnLines->filter(fn (PurchaseReturnLine $l) => $l->purchaseReturn->date->toDateString() === $date);

            $qtyOut = round(
                (float) $daySaleLines->sum(fn (SaleLine $l) => $l->baseQty())
                + (float) $dayMaterialLines->sum('qty')
                + (float) $dayPurchaseReturnLines->sum(fn (PurchaseReturnLine $l) => $l->baseQty()),
                self::QTY_DECIMALS
            );

            // What leaves stock today is valued LINE BY LINE, each line
            // rounded to the cent exactly as it is posted to Cost of
            // Goods Sold (repriceSaleCogs() / material line_total). It
            // used to be one rounded figure for the whole day, so the
            // stock value here and the Inventory account in the ledger
            // could drift apart by a cent or two a day (audit M9).
            $valueOut = 0.0;

            foreach ($daySaleLines as $line) {
                // unit_cost is always cost PER BASE UNIT (per kg,
                // say) regardless of which unit the line was sold
                // in — repriceSaleCogs() multiplies it back out by
                // this line's base quantity, not its raw qty.
                if ((float) ($line->unit_cost ?? -1) !== $averageCost) {
                    $affectedSaleIds->push($line->sale_id);
                }
                $line->forceFill(['unit_cost' => $averageCost])->save();
                $valueOut += self::lineCost($line->baseQty(), $averageCost);
            }

            foreach ($dayMaterialLines as $line) {
                $lineTotal = round((float) $line->qty * $averageCost, 2);
                if ((float) ($line->unit_cost_snapshot ?? -1) !== $averageCost) {
                    $affectedProductionOrderIds->push($line->production_order_id);
                }
                $line->forceFill(['unit_cost_snapshot' => $averageCost, 'line_total' => $lineTotal])->save();
                $valueOut += $lineTotal;
            }

            // Goods sent back to a supplier leave at the price they were
            // bought at (stored on the return line), not at the average.
            foreach ($dayPurchaseReturnLines as $line) {
                // The line's own value (the same figure credited to
                // Inventory in the ledger), so stock value and ledger agree
                // to the cent whatever the quantity.
                $valueOut += (float) $line->line_total;
            }

            $valueOut = round($valueOut, 2);

            $endingQty   = round($availableQty - $qtyOut, self::QTY_DECIMALS);
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
            'affected_sale_return_ids' => $affectedSaleReturnIds->unique()->values()->all(),
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
        // Each line rounded to the cent on its own, then added — the
        // same figure recalculateItem() takes out of the stock value,
        // so the ledger's Inventory account and the stock ledger stay
        // equal to the cent (audit M9).
        $newTotal = round((float) SaleLine::query()
            ->where('sale_id', $saleId)
            ->whereNotNull('unit_cost')
            ->get()
            ->sum(fn (SaleLine $line) => self::lineCost($line->baseQty(), (float) $line->unit_cost)), 2);

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
     * The cost per base unit the ORIGINAL SALE took this returned
     * line's goods out of stock at. A sale line dated on/after the
     * recalculation start was (or is about to be) repriced in this
     * very pass, so its in-memory copy is read; an older one still
     * holds its stored cost. Falls back to the cost already stamped
     * on the return line, then to zero.
     *
     * @param  \Illuminate\Support\Collection<int, SaleLine>  $outboundSaleLines
     */
    private function originalSaleCost(SaleReturnLine $line, \Illuminate\Support\Collection $outboundSaleLines): float
    {
        $live = $outboundSaleLines->firstWhere('id', $line->sale_line_id);
        $cost = $live?->unit_cost ?? $line->saleLine?->unit_cost ?? $line->unit_cost;

        return (float) ($cost ?? 0);
    }

    /**
     * Store the cost a returned line went back into stock at, and
     * remember the return if that changed (so its Cost of Goods Sold
     * reversal is reposted).
     */
    private function stampReturnCost(SaleReturnLine $line, float $cost, \Illuminate\Support\Collection $affectedReturnIds): void
    {
        $cost = round($cost, 4);

        if ($line->unit_cost === null || abs((float) $line->unit_cost - $cost) >= 0.00005) {
            $affectedReturnIds->push($line->sale_return_id);
        }

        $line->forceFill(['unit_cost' => $cost])->save();
    }

    /**
     * A sales return's Cost of Goods Sold reversal = the sum of its
     * lines' (base qty x the cost they went back into stock at). The
     * mirror image of repriceSaleCogs(): reposts only if the total
     * actually differs from what is on the books.
     */
    private function repriceSaleReturnCogs(int $companyId, int $returnId): void
    {
        $return = SaleReturn::query()->with('lines')->find($returnId);

        if (! $return) {
            return;
        }

        $newTotal = round((float) $return->lines
            ->filter(fn (SaleReturnLine $line) => $line->unit_cost !== null && $line->item_id)
            ->sum(fn (SaleReturnLine $line) => self::lineCost($line->baseQty(), (float) $line->unit_cost)), 2);

        $currentlyPosted = (float) DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.source_type', SaleReturn::class)
            ->where('journal_entries.source_id', $return->id)
            ->where('accounts.code', Account::COST_OF_GOODS_SOLD)
            ->selectRaw('COALESCE(SUM(journal_lines.credit) - SUM(journal_lines.debit), 0) as net')
            ->value('net');

        if (abs($newTotal - $currentlyPosted) < 0.01) {
            return;
        }

        $this->journal->reverseCostOfGoodsSoldFor($return);

        if ($newTotal > 0) {
            $this->journal->postSaleReturnCostReversal($return, $newTotal);
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
