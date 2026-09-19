<?php

namespace App\Services\Reports;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\EquipmentPurchase;
use App\Models\InventoryPurchase;
use App\Models\InventoryPurchaseLine;
use App\Models\InventoryStockLedger;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderMaterialLine;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\Vendor;
use App\Support\FinancialRules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — ReportDataService
//  Location: app/Services/Reports/ReportDataService.php
//
//  Every number shown in a report — on screen (ReportController),
//  in a downloaded Excel file, or in a downloaded PDF (both via
//  ReportExportController) — is computed by ONE of the methods
//  below. Nothing queries Sale/Expense/Payment/etc. a second way
//  anywhere else. That's deliberate: the moment the on-screen table
//  and the exported file each ran their own query, they were free
//  to quietly drift apart (a filter added to one place and not the
//  other, a rounding difference, a forgotten where-clause) — this
//  service exists so that can't happen. Screen and file are always
//  reading the same numbers.
//
//  Profit & Loss, the Trial Balance, and every statement/ledger
//  report are ACCRUAL — read from the general ledger (JournalLine),
//  which is itself posted at invoice/bill/production date throughout
//  the app (see JournalService). Cash movement — what actually came
//  into or left the till/bank — is a separate, genuinely different
//  question, answered by cashFlow() alone.
// ══════════════════════════════════════════════════════════════════
class ReportDataService
{
    /**
     * @return array{0:string,1:string} [from, to] as Y-m-d, defaulting to the current month.
     */
    public function monthRange(?string $from, ?string $to): array
    {
        $fromDate = $from ? Carbon::parse($from) : now()->startOfMonth();
        $toDate   = $to ? Carbon::parse($to) : now()->endOfMonth();

        return [$fromDate->toDateString(), $toDate->toDateString()];
    }

    /**
     * Same shape as monthRange(), but defaulting to the current
     * calendar YEAR-to-date instead of the current month. Used by
     * the Trial Balance: an external auditor almost always wants
     * "this year so far", not "everything since the company's very
     * first transaction" nor "just this month" — see trialBalance()
     * for why Opening Balance still carries the full pre-$from
     * history forward regardless of this default.
     *
     * @return array{0:string,1:string} [from, to] as Y-m-d
     */
    public function yearRange(?string $from, ?string $to): array
    {
        $fromDate = $from ? Carbon::parse($from) : now()->startOfYear();
        $toDate   = $to ? Carbon::parse($to) : now();

        return [$fromDate->toDateString(), $toDate->toDateString()];
    }

    /**
     * "All Entries" — every sale, expense, inventory/equipment
     * purchase in one chronological list.
     */
    public function ledger(string $from, string $to): Collection
    {
        $rows = collect();

        Sale::query()->whereBetween('date', [$from, $to])->with(['customer:id,name', 'payments'])->get()
            ->each(fn (Sale $s) => $rows->push([
                'type' => $s->is_opening_balance ? 'opening_balance_receivable' : 'sale',
                'id' => $s->id, 'date' => $s->date->toDateString(),
                'party' => $s->customer?->name, 'amount' => (float) $s->amount,
                'balance' => $s->balance(), 'status' => $this->status($s),
            ]));

        Expense::query()->whereBetween('date', [$from, $to])->with(['vendor:id,name', 'payments'])->get()
            ->each(fn (Expense $e) => $rows->push([
                'type' => $e->is_opening_balance ? 'opening_balance_payable' : 'expense',
                'id' => $e->id, 'date' => $e->date->toDateString(),
                'party' => $e->vendor?->name, 'amount' => (float) $e->amount,
                'balance' => $e->balance(), 'status' => $this->status($e),
            ]));

        InventoryPurchase::query()->whereBetween('date', [$from, $to])->with(['vendor:id,name', 'payments'])->get()
            ->each(fn (InventoryPurchase $p) => $rows->push([
                'type' => $p->is_opening_balance ? 'opening_balance_inventory' : 'inventory_purchase',
                'id' => $p->id, 'date' => $p->date->toDateString(),
                'party' => $p->vendor?->name, 'amount' => (float) $p->amount,
                'balance' => $p->balance(), 'status' => $this->status($p),
            ]));

        EquipmentPurchase::query()->whereBetween('date', [$from, $to])->with(['vendor:id,name', 'payments'])->get()
            ->each(fn (EquipmentPurchase $p) => $rows->push([
                'type' => $p->is_opening_balance ? 'opening_balance_equipment' : 'equipment_purchase',
                'id' => $p->id, 'date' => $p->date->toDateString(),
                'party' => $p->vendor?->name, 'amount' => (float) $p->amount,
                'balance' => $p->balance(), 'status' => $this->status($p),
            ]));

        return $rows->sortByDesc('date')->values();
    }

    /**
     * Revenue earned vs. expenses incurred, ACCRUAL basis — this is
     * the company's real Income Statement, and it is read straight
     * off the general ledger (JournalLine/JournalEntry/Account), the
     * exact same rows the Trial Balance is built from. That is a
     * deliberate design choice, not just a convenient shortcut:
     *
     *   - Every entry in this system is already posted at the
     *     invoice/bill/production date, never the payment date (see
     *     JournalService — postSaleInvoice(), postExpenseInvoice(),
     *     postCustodySettlement(), postProductionLaborExpense() all
     *     date their entry from the underlying document, not from
     *     any later payment). So reading the ledger for a date range
     *     IS reading accrual activity for that range.
     *   - Opening balances never touch an income or expense account
     *     — the "other side" of every opening-balance entry is
     *     Owner's Equity (see the opening-balance block in
     *     JournalService). Filtering to account type income/expense
     *     therefore excludes them automatically, with no separate
     *     is_opening_balance flag to remember to check.
     *   - Every expense category already has its own ledger account
     *     (JournalService::expenseAccountFor() creates one per
     *     Category, code "EXP-{id}"), and a settled custody debits
     *     that same account on its settlement date. So the category
     *     breakdown below falls out of the same query as the
     *     headline — one source, not two that can quietly drift
     *     apart (which is exactly what the cash-basis version of
     *     this report used to risk).
     *
     * This report used to be cash basis ("income received" / "expenses
     * paid", built from Payment rows) while the rest of the system —
     * revenue recognised at invoice date, expenses recognised at bill
     * date, COGS at sale date — was accrual throughout. That made this
     * one report the odd one out, and its numbers could not be
     * reconciled against the Trial Balance. Cash movement in/out is a
     * genuinely different, still-useful question — it has its own
     * report, see cashFlow() below — but it does not belong here
     * twice.
     */
    public function profitAndLoss(string $from, string $to): array
    {
        $accountTotals = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->whereBetween('journal_entries.date', [$from, $to])
            ->whereIn('accounts.type', ['income', 'expense'])
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.type')
            ->selectRaw('accounts.id, accounts.code, accounts.name, accounts.type,
                SUM(journal_lines.debit) as total_debit, SUM(journal_lines.credit) as total_credit')
            ->get();

        $revenue = 0.0;
        $cogs    = 0.0;
        $expensesByCategory = collect();

        foreach ($accountTotals as $row) {
            $debit  = (float) $row->total_debit;
            $credit = (float) $row->total_credit;

            if ($row->type === 'income') {
                // Income accounts are credit-normal.
                $revenue += $credit - $debit;

                continue;
            }

            // Expense accounts (including Cost of Goods Sold) are
            // debit-normal.
            $net = $debit - $credit;

            if ($row->code === Account::COST_OF_GOODS_SOLD) {
                // Kept apart from the category breakdown so Gross
                // Profit can be shown on its own line — COGS already
                // carries both the per-sale cost (postCostOfGoodsSold())
                // and any production-labor variance
                // (postProductionLaborExpense()), exactly as in the
                // worked example in production-cycle_EN.md §10.
                $cogs += $net;

                continue;
            }

            $expensesByCategory->push((object) [
                'category' => $row->name,
                'total'    => round($net, 2),
            ]);
        }

        $expensesByCategory = $expensesByCategory->sortByDesc('total')->values();

        // Summed from the rounded category totals rather than the raw
        // ledger sums, so the headline is exactly what the bars
        // underneath it add up to — not a cent adrift of them.
        $operatingExpenses = round((float) $expensesByCategory->sum('total'), 2);

        $revenue     = round($revenue, 2);
        $cogs        = round($cogs, 2);
        $grossProfit = round($revenue - $cogs, 2);
        $netProfit   = round($grossProfit - $operatingExpenses, 2);

        // Per-item revenue breakdown — built the same way it always
        // was, straight from the sales invoices themselves. This was
        // already accrual before today's change (it never looked at
        // Payment rows), and sale_lines.line_total sums to exactly
        // the same figure the ledger's Sales Revenue account shows,
        // so it ties to $revenue above.
        $incomeByItem = Sale::query()
            ->whereBetween('sales.date', [$from, $to])
            ->where('sales.is_opening_balance', false)
            ->join('sale_lines', 'sale_lines.sale_id', '=', 'sales.id')
            ->leftJoin('items', 'items.id', '=', 'sale_lines.item_id')
            ->selectRaw("COALESCE(items.name, 'Other') as item, SUM(sale_lines.line_total) as total")
            ->groupBy('item')
            ->orderByDesc('total')
            ->get();

        // % of total revenue on both breakdowns — read as a standard
        // common-size income statement, revenue = 100%. $revenue == 0
        // (no sales in range at all) would divide by zero, so every
        // row just reads 0% instead — there's nothing to be a
        // percentage OF.
        $percentOf = fn (float $amount) => $revenue > 0 ? round(($amount / $revenue) * 100, 1) : 0.0;

        $incomeByItem = $incomeByItem->map(fn ($row) => (object) [
            'item'  => $row->item,
            'total' => round((float) $row->total, 2),
            'percent_of_revenue' => $percentOf((float) $row->total),
        ]);

        $expensesByCategory = $expensesByCategory->map(fn ($row) => (object) [
            'category' => $row->category,
            'total'    => $row->total,
            'percent_of_revenue' => $percentOf((float) $row->total),
        ]);

        // Cost of Goods Sold, broken down by product — Trading and
        // Production companies only (a Service company carries no
        // inventory, so this is always empty for them). Built from
        // the per-line unit_cost MovingAverageCostingService stamps
        // onto each sale line at pricing time, which is the exact
        // number already summed into $cogs above via the ledger —
        // so this table always ties out to the COGS headline, never
        // a separately-reconstructed estimate. A line with no
        // unit_cost yet (sold before this engine existed, or a
        // free-text/service line) contributes nothing here, same as
        // it always contributed nothing to COGS.
        $businessTypes = auth()->user()?->company?->businessTypes() ?? [];
        $showCogsByItem = in_array('trading', $businessTypes, true) || in_array('production', $businessTypes, true);

        $costOfGoodsSoldByItem = collect();

        if ($showCogsByItem) {
            $costOfGoodsSoldByItem = Sale::query()
                ->whereBetween('sales.date', [$from, $to])
                ->where('sales.is_opening_balance', false)
                ->join('sale_lines', 'sale_lines.sale_id', '=', 'sales.id')
                ->leftJoin('items', 'items.id', '=', 'sale_lines.item_id')
                ->whereNotNull('sale_lines.unit_cost')
                ->selectRaw("COALESCE(items.name, 'Other') as item, SUM(sale_lines.qty * sale_lines.unit_cost) as total")
                ->groupBy('item')
                ->orderByDesc('total')
                ->get()
                ->map(fn ($row) => (object) [
                    'item'  => $row->item,
                    'total' => round((float) $row->total, 2),
                    'percent_of_revenue' => $percentOf((float) $row->total),
                ]);
        }

        return [
            'from' => $from, 'to' => $to,
            'revenue'              => $revenue,
            'cost_of_goods_sold'   => $cogs,
            'gross_profit'         => $grossProfit,
            'operating_expenses'   => $operatingExpenses,
            'net_profit'           => $netProfit,
            // % of revenue for the summary/total rows themselves —
            // the table's Vue page and its export both need these to
            // render the Total Revenue / Total COGS / Gross Profit /
            // Total Operating Expenses / Net Profit rows inline with
            // everything else, all in the one table (see
            // ProfitAndLoss.vue — this report is a single nested
            // table now, not separate summary cards).
            'revenue_percent'            => $revenue > 0 ? 100.0 : 0.0,
            'cost_of_goods_sold_percent' => $percentOf($cogs),
            'gross_profit_percent'       => $percentOf($grossProfit),
            'operating_expenses_percent' => $percentOf($operatingExpenses),
            'net_profit_percent'         => $percentOf($netProfit),
            'expenses_by_category' => $expensesByCategory,
            'income_by_item'       => $incomeByItem,
            'cost_of_goods_sold_by_item' => $costOfGoodsSoldByItem,
            'show_cost_of_goods_sold_by_item' => $showCogsByItem,
        ];
    }

    /**
     * @return array{entries: Collection, balance: float}
     */
    public function customerStatement(?Customer $customer, ?string $from = null, ?string $to = null): array
    {
        if (! $customer) {
            return ['entries' => collect(), 'balance' => 0.0, 'opening_balance' => 0.0];
        }

        $entries = collect();

        $customer->sales()->with('payments')->orderBy('date')->get()->each(function (Sale $sale) use (&$entries) {
            $entries->push([
                'date' => $sale->date->toDateString(),
                'type' => $sale->is_opening_balance ? 'opening_balance' : 'sale',
                'ref' => $sale->is_opening_balance ? 'Opening Balance' : "Sale #{$sale->id}",
                'debit' => (float) $sale->amount, 'credit' => 0,
            ]);

            foreach ($sale->payments as $payment) {
                $entries->push([
                    'date' => $payment->date->toDateString(), 'type' => 'payment',
                    'ref' => "Payment #{$payment->id}", 'debit' => 0, 'credit' => (float) $payment->amount,
                ]);
            }
        });

        // Receipts tagged to this customer that settle no invoice —
        // a cash sale with no paperwork, a refund going the other
        // way. They never appear above, because nothing links them
        // to a Sale; reaching them needs the customer's own relation.
        // Direction decides the column: money in reduces what they
        // owe, money out (a refund) increases it.
        $customer->standalonePayments()->orderBy('date')->get()
            ->each(fn (Payment $payment) => $entries->push([
                'date'  => $payment->date->toDateString(),
                'type'  => $payment->direction === 'in' ? 'receipt' : 'refund',
                'ref'   => $payment->note ?: ($payment->direction === 'in' ? "Receipt #{$payment->id}" : "Refund #{$payment->id}"),
                'debit' => $payment->direction === 'in' ? 0 : (float) $payment->amount,
                'credit' => $payment->direction === 'in' ? (float) $payment->amount : 0,
            ]));

        return $this->windowStatement($entries, $from, $to, 'debit', 'credit');
    }

    /**
     * Cut a statement down to a date window without losing the story.
     *
     * A statement filtered by simply dropping everything outside the
     * range would be wrong, not just shorter: a customer who owed
     * 5,000 coming into March and paid 2,000 during March would show
     * a closing balance of -2,000. So everything BEFORE the window is
     * collapsed into a single brought-forward figure, the window's
     * own movements run on top of it, and the closing balance is what
     * they actually owe on the last day of the range. That is how a
     * statement of account is supposed to read, and it is what makes
     * the range usable for "what happened last quarter" without
     * misrepresenting the position.
     *
     * With no range given, the behaviour is exactly what it was
     * before: the whole history, opening at zero.
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return array{entries: Collection, balance: float, opening_balance: float}
     */
    private function windowStatement(Collection $entries, ?string $from, ?string $to, string $plus, string $minus): array
    {
        $entries = $entries->sortBy('date')->values();

        $opening = 0.0;

        if ($from) {
            $before  = $entries->filter(fn (array $e) => $e['date'] < $from);
            $opening = round($before->sum($plus) - $before->sum($minus), 2);
            $entries = $entries->reject(fn (array $e) => $e['date'] < $from)->values();
        }

        if ($to) {
            $entries = $entries->reject(fn (array $e) => $e['date'] > $to)->values();
        }

        $running = $opening;
        $entries = $entries->map(function (array $entry) use (&$running, $plus, $minus) {
            $running += $entry[$plus] - $entry[$minus];
            $entry['running_balance'] = round($running, 2);

            return $entry;
        });

        return [
            'entries'         => $entries,
            'balance'         => round($running, 2),
            'opening_balance' => $opening,
        ];
    }

    /**
     * @return array{entries: Collection, balance: float, opening_balance: float}
     */
    public function supplierStatement(?Vendor $vendor, ?string $from = null, ?string $to = null): array
    {
        if (! $vendor) {
            return ['entries' => collect(), 'balance' => 0.0, 'opening_balance' => 0.0];
        }

        $entries = collect();

        $addBills = function ($bills, string $label) use (&$entries) {
            foreach ($bills as $bill) {
                $isOpening = (bool) ($bill->is_opening_balance ?? false);
                $entries->push([
                    'date' => $bill->date->toDateString(),
                    'type' => $isOpening ? 'opening_balance' : $label,
                    'ref' => $isOpening ? 'Opening Balance' : ucfirst($label)." #{$bill->id}",
                    'debit' => 0, 'credit_owed' => (float) $bill->amount,
                ]);

                foreach ($bill->payments as $payment) {
                    $entries->push([
                        'date' => $payment->date->toDateString(), 'type' => 'payment',
                        'ref' => "Payment #{$payment->id}", 'debit' => (float) $payment->amount, 'credit_owed' => 0,
                    ]);
                }
            }
        };

        $addBills($vendor->expenses()->with('payments')->orderBy('date')->get(), 'expense');
        $addBills($vendor->inventoryPurchases()->with('payments')->orderBy('date')->get(), 'inventory_purchase');
        $addBills($vendor->equipmentPurchases()->with('payments')->orderBy('date')->get(), 'equipment_purchase');

        // Payments tagged to this supplier that settle no bill — see
        // the same block in customerStatement() for why they cannot
        // be reached through the bills above.
        $vendor->standalonePayments()->orderBy('date')->get()
            ->each(fn (Payment $payment) => $entries->push([
                'date'  => $payment->date->toDateString(),
                'type'  => $payment->direction === 'out' ? 'payment' : 'refund',
                'ref'   => $payment->note ?: ($payment->direction === 'out' ? "Payment #{$payment->id}" : "Refund #{$payment->id}"),
                'debit' => $payment->direction === 'out' ? (float) $payment->amount : 0,
                'credit_owed' => $payment->direction === 'out' ? 0 : (float) $payment->amount,
            ]));

        return $this->windowStatement($entries, $from, $to, 'credit_owed', 'debit');
    }

    /**
     * Per-item stock levels (quantity + weighted-average value).
     * When $itemId is given, also returns that one item's full
     * activity history — purchases, sales, and Production Order
     * output/consumption — with a running stock balance; this is the
     * drill-down the prototype had (renderInvItemDetail) that the
     * summary-only table dropped, now extended to cover Production.
     *
     * @return array{items: Collection, total_stock_value: float, selected: ?array, history: Collection}
     */
    public function inventoryStatement(?int $itemId = null, ?string $from = null, ?string $to = null): array
    {
        // Quantities (purchased/produced/sold/consumed base units) are
        // plain counts — correct no matter which costing method is
        // used, so they're still read straight from the source
        // tables exactly as before.
        //
        // The COST figures (avg_purchase_cost, stock_value) are NOT
        // computed here anymore. They used to be calculated the same
        // way the legacy Item::averagePurchaseCost() worked — total
        // money ever spent ÷ total units ever bought, never
        // subtracting what had already been sold. That's now removed
        // (see Item.php). This report reads those two figures
        // straight from InventoryStockLedger instead — the same
        // day-by-day moving-average ledger MovingAverageCostingService
        // maintains and actually prices every real sale's Cost of
        // Goods Sold against (see that service's class doc comment).
        // So the number shown here is now, by construction, the same
        // number driving the accounting — not a second, separately
        // -computed guess at it.
        $itemIds = Item::query()->pluck('id');

        $beforeFrom = $from ? $this->dayBefore($from) : null;

        $asOf   = $this->itemStockTotals($itemIds, $to);
        $before = $from ? $this->itemStockTotals($itemIds, $beforeFrom) : null;
        $ledger = $this->latestLedgerRows($itemIds, $to);

        $items = Item::query()
            ->orderBy('name')
            ->get()
            ->map(function (Item $item) use ($asOf, $before, $from, $ledger) {
                $t = $asOf->get($item->id);

                $stockIn  = round($t->purchased + $t->produced, 2);
                $stockOut = round($t->sold + $t->consumed, 2);
                $stock    = round($stockIn - $stockOut, 2);

                // Null when the item has no ledger row yet (never
                // bought, made, or sold as of this date), so the
                // report shows a blank rather than a fabricated zero
                // cost — same rule the old calculation followed.
                $ledgerRow  = $ledger->get($item->id);
                $avgCost    = $ledgerRow ? (float) $ledgerRow->average_cost : null;
                $stockValue = $ledgerRow ? (float) $ledgerRow->ending_value : 0.0;

                $openingStock = 0.0;
                $periodIn     = $stockIn;
                $periodOut    = $stockOut;

                if ($from) {
                    $b = $before->get($item->id);
                    $openingStock = round(($b->purchased + $b->produced) - ($b->sold + $b->consumed), 2);
                    $periodIn     = round($stockIn - round($b->purchased + $b->produced, 2), 2);
                    $periodOut    = round($stockOut - round($b->sold + $b->consumed, 2), 2);
                }

                return [
                    'id'                => $item->id,
                    'name'              => $item->name,
                    'total_in_base'     => $stockIn,
                    'total_out_base'    => $stockOut,
                    'current_stock'     => $stock,
                    'opening_stock'     => $openingStock,
                    'period_in_base'    => $periodIn,
                    'period_out_base'   => $periodOut,
                    'avg_purchase_cost' => $avgCost,
                    'stock_value'       => $stockValue,
                    'base_unit_name'    => $item->base_unit_name,
                    // Flags the summary table can badge without the
                    // Vue layer re-deriving thresholds of its own.
                    'is_negative'       => $stock < 0,
                    'is_out_of_stock'   => $stock <= 0 && $stockIn > 0,
                ];
            });

        $selected = null;
        $history  = collect();

        if ($itemId) {
            $item = $items->firstWhere('id', $itemId);

            if ($item) {
                $selected = $item;
                $history  = $this->inventoryItemHistory($itemId, $from, $to);
            }
        }

        return [
            'items'              => $items,
            'total_stock_value'  => round($items->sum('stock_value'), 2),
            'selected'           => $selected,
            'history'            => $history,
        ];
    }

    /**
     * Purchased/produced/sold/consumed base-unit quantities AND cost,
     * for every item id given, optionally only activity on or before
     * $asOf — four grouped queries regardless of how many items are
     * in $itemIds. This is the batch form of exactly what
     * Item::totalPurchasedBase() / totalProducedBase() / totalSoldBase() /
     * totalConsumedInProductionBase() compute one item at a time; see
     * the doc comment on inventoryStatement() for why both exist and
     * what keeps them from drifting apart.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $itemIds
     * @return \Illuminate\Support\Collection<int, object{purchased: float, purchase_cost: float, produced: float, produced_cost: float, sold: float, consumed: float}>
     */
    private function itemStockTotals(Collection $itemIds, ?string $asOf): Collection
    {
        $purchased = InventoryPurchaseLine::query()
            ->whereIn('inventory_purchase_lines.item_id', $itemIds)
            ->join('inventory_purchases', 'inventory_purchases.id', '=', 'inventory_purchase_lines.inventory_purchase_id')
            ->when($asOf, fn ($q) => $q->where('inventory_purchases.date', '<=', $asOf))
            ->groupBy('inventory_purchase_lines.item_id')
            ->selectRaw('inventory_purchase_lines.item_id as item_id,
                SUM(inventory_purchase_lines.qty * inventory_purchase_lines.qty_per_uom) as base,
                SUM(inventory_purchase_lines.line_total) as cost')
            ->get()->keyBy('item_id');

        $produced = ProductionOrder::query()
            ->whereIn('item_id', $itemIds)
            ->when($asOf, fn ($q) => $q->where('date', '<=', $asOf))
            ->groupBy('item_id')
            ->selectRaw('item_id, SUM(qty_produced) as base, SUM(total_cost) as cost')
            ->get()->keyBy('item_id');

        $sold = SaleLine::query()
            ->whereIn('sale_lines.item_id', $itemIds)
            ->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
            ->when($asOf, fn ($q) => $q->where('sales.date', '<=', $asOf))
            ->groupBy('sale_lines.item_id')
            ->selectRaw('sale_lines.item_id as item_id, SUM(sale_lines.qty) as base')
            ->get()->keyBy('item_id');

        $consumed = ProductionOrderMaterialLine::query()
            ->whereIn('production_order_material_lines.item_id', $itemIds)
            ->join('production_orders', 'production_orders.id', '=', 'production_order_material_lines.production_order_id')
            ->when($asOf, fn ($q) => $q->where('production_orders.date', '<=', $asOf))
            ->groupBy('production_order_material_lines.item_id')
            ->selectRaw('production_order_material_lines.item_id as item_id, SUM(production_order_material_lines.qty) as base')
            ->get()->keyBy('item_id');

        return $itemIds->mapWithKeys(fn ($id) => [$id => (object) [
            'purchased'     => (float) ($purchased->get($id)->base ?? 0),
            'purchase_cost' => (float) ($purchased->get($id)->cost ?? 0),
            'produced'      => (float) ($produced->get($id)->base ?? 0),
            'produced_cost' => (float) ($produced->get($id)->cost ?? 0),
            'sold'          => (float) ($sold->get($id)->base ?? 0),
            'consumed'      => (float) ($consumed->get($id)->base ?? 0),
        ]]);
    }

    /**
     * The most recent InventoryStockLedger row on or before $asOf,
     * per item — this item's real moving-average cost and stock
     * value AS OF that date, straight from the same table
     * MovingAverageCostingService maintains and actually prices
     * every sale's Cost of Goods Sold against. An item with no row
     * at all (never bought, made, or sold as of this date) simply
     * has no entry in the returned collection, so callers can tell
     * "genuinely no data yet" apart from "cost happens to be zero".
     *
     * Grouped and reduced in PHP rather than a database-specific
     * "latest row per group" query, since ledger rows are one per
     * item per day WITH movement (not per calendar day), so the
     * result set stays small even for a long-running company.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $itemIds
     * @return \Illuminate\Support\Collection<int, InventoryStockLedger>
     */
    private function latestLedgerRows(Collection $itemIds, ?string $asOf): Collection
    {
        return InventoryStockLedger::query()
            ->whereIn('item_id', $itemIds)
            ->when($asOf, fn ($q) => $q->where('date', '<=', $asOf))
            ->orderBy('date')
            ->get()
            ->groupBy('item_id')
            ->map(fn ($rows) => $rows->last());
    }

    private function dayBefore(string $date): string
    {
        return Carbon::parse($date)->subDay()->toDateString();
    }

    /**
     * One item's full activity — purchases, sales, and (new) Production
     * Order output/consumption — merged into a single chronological
     * ledger with a running stock balance. Every quantity here is in
     * base units so the running total lines up with `current_stock`
     * above; it has to, since both are now built from the same
     * relations on Item (purchaseLines/saleLines/producedBatches/
     * consumedInProduction), not two independently-written queries.
     */
    private function inventoryItemHistory(int $itemId, ?string $from = null, ?string $to = null): Collection
    {
        $purchases = InventoryPurchaseLine::query()
            ->where('inventory_purchase_lines.item_id', $itemId)
            ->join('inventory_purchases', 'inventory_purchases.id', '=', 'inventory_purchase_lines.inventory_purchase_id')
            ->select([
                'inventory_purchases.id as doc_id',
                'inventory_purchases.date as date',
                'inventory_purchase_lines.qty as qty',
                'inventory_purchase_lines.qty_per_uom as qty_per_uom',
                'inventory_purchase_lines.unit_price as unit_price',
            ])
            ->get()
            ->map(function ($row) {
                $qtyPerUom = (float) $row->qty_per_uom ?: 1.0;
                $baseQty   = (float) $row->qty * $qtyPerUom;

                return [
                    'date'          => Carbon::parse($row->date)->toDateString(),
                    'type'          => 'purchase',
                    'ref'           => "Purchase #{$row->doc_id}",
                    'qty'           => $baseQty,
                    'unit_price'    => $qtyPerUom > 0 ? (float) $row->unit_price / $qtyPerUom : (float) $row->unit_price,
                ];
            });

        $sales = SaleLine::query()
            ->where('sale_lines.item_id', $itemId)
            ->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
            ->select([
                'sales.id as doc_id',
                'sales.date as date',
                'sale_lines.qty as qty',
                'sale_lines.unit_price as unit_price',
            ])
            ->get()
            ->map(fn ($row) => [
                'date'       => Carbon::parse($row->date)->toDateString(),
                'type'       => 'sale',
                'ref'        => "Sale #{$row->doc_id}",
                'qty'        => -1 * (float) $row->qty,
                'unit_price' => (float) $row->unit_price,
            ]);

        // NEW — this item made via a Production Order (stock in), at
        // the batch's own unit cost. Only meaningful for a 'product'
        // item; simply empty for anything else.
        $produced = ProductionOrder::query()
            ->where('item_id', $itemId)
            ->select(['id as doc_id', 'date', 'qty_produced', 'unit_cost'])
            ->get()
            ->map(fn ($row) => [
                'date'       => Carbon::parse($row->date)->toDateString(),
                'type'       => 'produced',
                'ref'        => "Production order #{$row->doc_id}",
                'qty'        => (float) $row->qty_produced,
                'unit_price' => (float) $row->unit_cost,
            ]);

        // NEW — this item consumed as a raw material by a Production
        // Order (stock out), at the cost it was actually costed at
        // that day (unit_cost_snapshot — see the migration's note on
        // why this is stored, not recalculated). Only meaningful for
        // a 'raw_material' item.
        $consumed = ProductionOrderMaterialLine::query()
            ->where('production_order_material_lines.item_id', $itemId)
            ->join('production_orders', 'production_orders.id', '=', 'production_order_material_lines.production_order_id')
            ->select([
                'production_orders.id as doc_id',
                'production_orders.date as date',
                'production_order_material_lines.qty as qty',
                'production_order_material_lines.unit_cost_snapshot as unit_price',
            ])
            ->get()
            ->map(fn ($row) => [
                'date'       => Carbon::parse($row->date)->toDateString(),
                'type'       => 'consumed',
                'ref'        => "Production order #{$row->doc_id}",
                'qty'        => -1 * (float) $row->qty,
                'unit_price' => (float) $row->unit_price,
            ]);

        $rows = $purchases->concat($sales)->concat($produced)->concat($consumed)->sortBy('date')->values();

        // The running stock has to start from what was already on the
        // shelf when the window opened, not from zero — otherwise a
        // drill-down filtered to March would show an item going
        // negative on its first sale of the month even though there
        // was plenty in stock.
        $running = 0.0;

        if ($from) {
            $running = round($rows->filter(fn (array $row) => $row['date'] < $from)->sum('qty'), 2);
            $rows    = $rows->reject(fn (array $row) => $row['date'] < $from)->values();
        }

        if ($to) {
            $rows = $rows->reject(fn (array $row) => $row['date'] > $to)->values();
        }

        return $rows->map(function (array $row) use (&$running) {
            $running += $row['qty'];
            $row['stock_after'] = round($running, 2);

            return $row;
        });
    }

    public function cashFlow(string $from, string $to): array
    {
        // Same reasoning as profitAndLoss() — an opening balance is
        // a starting position, not a cash movement that happened
        // during this period, so it's excluded here throughout.
        $byMethod = Payment::query()
            ->whereBetween('date', [$from, $to])
            ->where('is_opening_balance', false)
            ->selectRaw('method, direction, SUM(amount) as total')
            ->groupBy('method', 'direction')
            ->get();

        $cashIn  = (float) Payment::query()->whereBetween('date', [$from, $to])->where('direction', 'in')->where('is_opening_balance', false)->sum('amount');
        $cashOut = (float) Payment::query()->whereBetween('date', [$from, $to])->where('direction', 'out')->where('is_opening_balance', false)->sum('amount');

        $movements = Payment::query()
            ->whereBetween('date', [$from, $to])
            ->where('is_opening_balance', false)
            ->with([
                'customer:id,name',
                'vendor:id,name',
                // A payment recorded against an actual sale/bill
                // never gets its own customer_id/vendor_id filled in
                // (only a true standalone receipt/payment does — see
                // PaymentRecorderService::apply() and
                // PaymentController::storeReceipt()/storePayment()),
                // even though the sale/bill it belongs to always
                // knows its customer or vendor. Falling back to that
                // is what actually fixes "Party" always showing "—"
                // for the vast majority of real payments.
                'payable' => fn ($morphTo) => $morphTo->morphWith([
                    Sale::class              => ['customer:id,name'],
                    Expense::class           => ['vendor:id,name'],
                    InventoryPurchase::class => ['vendor:id,name'],
                    EquipmentPurchase::class => ['vendor:id,name'],
                ]),
            ])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Payment $p) => [
                'date'      => $p->date->toDateString(),
                'amount'    => (float) $p->amount,
                'method'    => $p->method,
                'direction' => $p->direction,
                'party'     => $p->customer?->name
                    ?? $p->vendor?->name
                    ?? $p->payable?->customer?->name
                    ?? $p->payable?->vendor?->name
                    ?? '—',
            ]);

        return [
            'from' => $from, 'to' => $to,
            'cash_in'   => $cashIn,
            'cash_out'  => $cashOut,
            'net_flow'  => round($cashIn - $cashOut, 2),
            'by_method' => $byMethod,
            'movements' => $movements,
        ];
    }

    /**
     * Trial Balance — every account's Opening Balance, this period's
     * Total Debit / Total Credit movement, and the resulting End
     * Balance, for the auditor.
     *
     * Opening Balance is NOT "zero" or "skipped" — it's everything
     * ever posted strictly before $from, netted per account. That
     * matters for balance-sheet accounts (cash, bank, customers,
     * suppliers, inventory, equipment, equity): their real balance
     * is cumulative since day one, so a customer who owed 10,000
     * before $from and paid 3,000 during the period must still show
     * an End Balance of 7,000 owed — not 3,000. Filtering those
     * accounts to the period alone (i.e. dropping Opening Balance)
     * would show a number that doesn't match reality and would
     * mislead an auditor rather than help them.
     *
     * Revenue/expense accounts get the same treatment for
     * consistency, even though in a system with formal year-end
     * closing entries their Opening Balance would normally be zero.
     * This app never posts closing entries (see JournalService), so
     * their Opening Balance is genuinely "everything before $from"
     * too — which is what makes End Balance = Opening + this
     * period's movement always hold, for every account, without
     * exception.
     *
     * @return array{
     *   from: string, to: string, rows: Collection,
     *   total_opening_debit: float, total_opening_credit: float,
     *   total_period_debit: float, total_period_credit: float,
     *   total_closing_debit: float, total_closing_credit: float,
     *   is_balanced: bool,
     * }
     */
    public function trialBalance(string $from, string $to): array
    {
        $sumsByAccount = function (?string $before, ?array $between) {
            $query = JournalLine::query()
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id');

            if ($before !== null) {
                $query->where('journal_entries.date', '<', $before);
            }
            if ($between !== null) {
                $query->whereBetween('journal_entries.date', $between);
            }

            return $query
                ->groupBy('journal_lines.account_id')
                ->selectRaw('journal_lines.account_id, SUM(journal_lines.debit) as total_debit, SUM(journal_lines.credit) as total_credit')
                ->get()
                ->keyBy('account_id');
        };

        // Everything strictly before $from, netted — this account's
        // real balance walking into the period.
        $opening = $sumsByAccount($from, null);

        // Just this period's movement — this is "Total Debit" /
        // "Total Credit" on screen.
        $period = $sumsByAccount(null, [$from, $to]);

        $accountIds = $opening->keys()->merge($period->keys())->unique();

        $rows = Account::query()
            ->whereIn('id', $accountIds)
            ->orderBy('code')
            ->get()
            ->map(function (Account $account) use ($opening, $period) {
                $openingNet = round(
                    (float) ($opening[$account->id]->total_debit ?? 0)
                    - (float) ($opening[$account->id]->total_credit ?? 0),
                    2
                );

                $periodDebit  = round((float) ($period[$account->id]->total_debit ?? 0), 2);
                $periodCredit = round((float) ($period[$account->id]->total_credit ?? 0), 2);

                $closingNet = round($openingNet + $periodDebit - $periodCredit, 2);

                return [
                    'code'    => $account->code,
                    'name'    => $account->name,
                    'name_ar' => $account->name_ar,
                    'type'    => $account->type,

                    // Net, shown on whichever side it actually falls —
                    // same reasoning as the old single-date version:
                    // don't force an abnormal balance onto its
                    // "textbook" side, show it where it really is.
                    'opening_debit'  => $openingNet > 0 ? $openingNet : 0.0,
                    'opening_credit' => $openingNet < 0 ? -$openingNet : 0.0,

                    'period_debit'  => $periodDebit,
                    'period_credit' => $periodCredit,

                    'closing_debit'  => $closingNet > 0 ? $closingNet : 0.0,
                    'closing_credit' => $closingNet < 0 ? -$closingNet : 0.0,
                ];
            })
            // Drop accounts with no activity at all in any column —
            // an account that had a balance before $from, moved
            // during the period, or still carries a balance after,
            // stays; a truly untouched account doesn't clutter the
            // report.
            ->filter(fn (array $r) => $r['opening_debit'] || $r['opening_credit']
                || $r['period_debit'] || $r['period_credit']
                || $r['closing_debit'] || $r['closing_credit'])
            ->values();

        return [
            'from' => $from,
            'to'   => $to,
            'rows' => $rows,

            'total_opening_debit'  => round((float) $rows->sum('opening_debit'), 2),
            'total_opening_credit' => round((float) $rows->sum('opening_credit'), 2),
            'total_period_debit'   => round((float) $rows->sum('period_debit'), 2),
            'total_period_credit'  => round((float) $rows->sum('period_credit'), 2),
            'total_closing_debit'  => round((float) $rows->sum('closing_debit'), 2),
            'total_closing_credit' => round((float) $rows->sum('closing_credit'), 2),

            // Every real journal entry balances (debit = credit), so
            // opening, period and closing each balance on their own —
            // checking closing is enough to catch any real problem.
            'is_balanced' => FinancialRules::amountsEqual((float) $rows->sum('closing_debit'), (float) $rows->sum('closing_credit')),
        ];
    }

    /**
     * Balance Sheet — Assets, Liabilities, and Equity as of a single
     * date. Same audience/shape convention as the Trial Balance
     * above: real account names/codes, not simplified language,
     * because this is written for the auditor.
     *
     * The one tricky part: this app never posts formal year-end
     * "closing entries" — Income and Expense accounts just
     * accumulate forever (see JournalService's opening-balance doc
     * comment, and trialBalance()'s doc comment for the same point).
     * That means Retained Earnings (account 3900) sits at zero
     * forever; it's never actually the thing that makes
     * Assets = Liabilities + Equity hold.
     *
     * So this method computes Net Profit itself — every Income
     * account minus every Expense account, from day one through
     * $asOf — and shows it as its own line inside Equity. That's
     * exactly what "closing the year into Retained Earnings" would
     * have produced anyway, just computed live instead of posted and
     * stored — see the earlier decision not to build a year-end
     * closing feature, since the live query is fast enough that
     * there's nothing closing would have bought except this number,
     * and this number is just as correct computed on the fly.
     *
     * @return array{
     *   as_of: string,
     *   assets: Collection, liabilities: Collection, equity: Collection,
     *   net_profit_to_date: float,
     *   total_assets: float, total_liabilities: float, total_equity: float,
     *   is_balanced: bool,
     * }
     */
    public function balanceSheet(string $asOf): array
    {
        $sums = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.date', '<=', $asOf)
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, SUM(journal_lines.debit) as total_debit, SUM(journal_lines.credit) as total_credit')
            ->get()
            ->keyBy('account_id');

        $accounts = Account::query()
            ->whereIn('id', $sums->keys())
            ->orderBy('code')
            ->get()
            ->map(function (Account $account) use ($sums) {
                $debit  = (float) ($sums[$account->id]->total_debit ?? 0);
                $credit = (float) ($sums[$account->id]->total_credit ?? 0);

                // Each type's own "normal" direction — asset/expense
                // shown positive when net debit, liability/equity/
                // income shown positive when net credit. Unlike the
                // Trial Balance (which deliberately shows whichever
                // side a balance actually falls on, abnormal or not),
                // a Balance Sheet has no second column to put an
                // abnormal balance in — a liability that ended up net
                // debit (overpaid) is still shown under Liabilities,
                // just as a negative number, exactly as any real
                // balance sheet would.
                $balance = $account->isDebitNormal()
                    ? round($debit - $credit, 2)
                    : round($credit - $debit, 2);

                return [
                    'code'    => $account->code,
                    'name'    => $account->name,
                    'name_ar' => $account->name_ar,
                    'type'    => $account->type,
                    'balance' => $balance,
                ];
            });

        $noActivity = fn (array $a) => $a['balance'] == 0.0;

        $assets      = $accounts->where('type', 'asset')->reject($noActivity)->values();
        $liabilities = $accounts->where('type', 'liability')->reject($noActivity)->values();
        $equity      = $accounts->where('type', 'equity')->reject($noActivity)->values();

        $netProfit = round(
            (float) $accounts->where('type', 'income')->sum('balance')
            - (float) $accounts->where('type', 'expense')->sum('balance'),
            2
        );

        $totalAssets      = round((float) $assets->sum('balance'), 2);
        $totalLiabilities = round((float) $liabilities->sum('balance'), 2);
        $totalEquity      = round((float) $equity->sum('balance') + $netProfit, 2);

        return [
            'as_of' => $asOf,

            'assets'      => $assets,
            'liabilities' => $liabilities,
            'equity'      => $equity,

            'net_profit_to_date' => $netProfit,

            'total_assets'      => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'total_equity'      => $totalEquity,

            'is_balanced' => FinancialRules::amountsEqual($totalAssets, $totalLiabilities + $totalEquity),
        ];
    }

    /**
     * Journal — every posted entry in a date range with its lines,
     * for the auditor to trace any figure on the Trial Balance back
     * to the transaction(s) that produced it. Reversed/reversing
     * entries are included (they're real, dated postings) so the
     * journal always reconciles to the Trial Balance above.
     */
    public function journalReport(string $from, string $to): Collection
    {
        return JournalEntry::query()
            ->whereBetween('date', [$from, $to])
            ->with('lines.account:id,code,name,name_ar')
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(fn (JournalEntry $entry) => [
                'id'   => $entry->id,
                'date' => $entry->date->toDateString(),
                'memo' => $entry->memo,
                'is_reversal' => (bool) $entry->reverses_id,
                'lines' => $entry->lines->map(fn (JournalLine $line) => [
                    'account_code' => $line->account->code,
                    'account_name' => $line->account->name,
                    'account_name_ar' => $line->account->name_ar,
                    'debit'  => (float) $line->debit,
                    'credit' => (float) $line->credit,
                ])->values(),
            ]);
    }

    /**
     * @param  Sale|Expense|InventoryPurchase|EquipmentPurchase  $record
     */
    private function status($record): string
    {
        if ($record->isPaid()) {
            return 'paid';
        }

        return $record->paidAmount() > 0 ? 'partial' : 'unpaid';
    }
}
