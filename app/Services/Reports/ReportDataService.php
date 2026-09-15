<?php

namespace App\Services\Reports;

use App\Models\Account;
use App\Models\Custody;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\EquipmentPurchase;
use App\Models\InventoryPurchase;
use App\Models\InventoryPurchaseLine;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\Vendor;
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
//  All reports are CASH-BASIS — see the note this class inherited
//  from ReportController's original doc comment: "income received"
//  / "expenses paid" come from actual Payment rows, not invoice/bill
//  totals, matching the prototype's simple bookkeeping model built
//  for micro-companies (no accrual accounting).
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

    public function profitAndLoss(string $from, string $to): array
    {
        // Opening-balance rows are excluded throughout this report —
        // they're a starting position recognised once, not income or
        // expense that happened during the selected period. They
        // still appear correctly on the Trial Balance / Balance Sheet.
        $incomeReceived = round((float) $this->periodPayments($from, $to, 'in')->sum('amount'), 2);

        // Custody is deliberately absent from both of these — see
        // custodyExpenses() for the whole reasoning. What a custody
        // really costs the company is added back in below.
        $expensesPaid = round(
            (float) $this->periodPayments($from, $to, 'out')->sum('amount')
            + $this->custodyExpenses($from, $to)->sum('total'),
            2
        );

        $expensesByCategory = Expense::query()
            ->whereBetween('date', [$from, $to])
            ->where('is_opening_balance', false)
            ->join('categories', 'categories.id', '=', 'expenses.category_id')
            ->selectRaw('categories.name as category, SUM(expenses.amount) as total')
            ->groupBy('categories.name')
            ->orderByDesc('total')
            ->get()
            ->concat($this->custodyExpenses($from, $to))
            ->groupBy('category')
            ->map(fn ($rows, $category) => (object) [
                'category' => $category,
                'total'    => $rows->sum('total'),
            ])
            ->sortByDesc('total')
            ->values();

        $incomeByItem = Sale::query()
            ->whereBetween('sales.date', [$from, $to])
            ->where('sales.is_opening_balance', false)
            ->join('sale_lines', 'sale_lines.sale_id', '=', 'sales.id')
            ->leftJoin('items', 'items.id', '=', 'sale_lines.item_id')
            ->selectRaw("COALESCE(items.name, 'Other') as item, SUM(sale_lines.line_total) as total")
            ->groupBy('item')
            ->orderByDesc('total')
            ->get();

        return [
            'from' => $from, 'to' => $to,
            'income_received'      => $incomeReceived,
            'expenses_paid'        => $expensesPaid,
            'net_profit'           => round($incomeReceived - $expensesPaid, 2),
            'expenses_by_category' => $expensesByCategory,
            'income_by_item'       => $incomeByItem,
        ];
    }

    /**
     * Cash that moved in the period and belongs in the Profit & Loss.
     *
     * Custody movements are excluded on purpose. Handing an employee
     * a float is not spending — it is moving the company's own cash
     * from the till into that employee's pocket, and the company
     * still owns every penny of it. Counting the hand-out as an
     * expense made a 10,000 float read as a 10,000 loss in the month
     * it was given, and the unspent change coming back read as
     * REVENUE in the month it was returned. Neither figure described
     * anything that happened to the business.
     *
     * The Cash Flow report deliberately still shows these movements
     * — cash genuinely left the drawer and came back, and that is
     * exactly the question a cash-flow statement answers.
     */
    private function periodPayments(string $from, string $to, string $direction)
    {
        return Payment::query()
            ->whereBetween('date', [$from, $to])
            ->where('direction', $direction)
            ->where('is_opening_balance', false)
            ->where(function ($query) {
                $query->whereNull('payable_type')
                    ->orWhere('payable_type', '!=', Custody::class);
            });
    }

    /**
     * What custodies settled in this period actually cost, per
     * category.
     *
     * This is the other half of periodPayments(): the real expense
     * is what the holder came back and said they spent, recognised
     * on the day they said it, not the size of the float they were
     * handed weeks earlier. A float still out — handed over but not
     * yet squared up — contributes nothing here, which is correct:
     * nobody knows yet what it bought.
     *
     * A settlement line with no category falls under Miscellaneous,
     * matching how JournalService::expenseAccountFor() posts the
     * same line to the ledger.
     *
     * @return Collection<int, object{category: string, total: float}>
     */
    private function custodyExpenses(string $from, string $to): Collection
    {
        // Based on Custody rather than the settlement line, so
        // BelongsToCompany's global scope applies — custody_settlement_lines
        // carries no company_id of its own and would otherwise reach
        // across tenants.
        return Custody::query()
            ->join('custody_settlement_lines', 'custody_settlement_lines.custody_id', '=', 'custodies.id')
            ->leftJoin('categories', 'categories.id', '=', 'custody_settlement_lines.category_id')
            ->where('custodies.settled', true)
            ->whereBetween('custodies.settlement_date', [$from, $to])
            ->selectRaw("COALESCE(categories.name, 'Miscellaneous') as category, SUM(custody_settlement_lines.amount) as total")
            ->groupBy('category')
            ->get()
            ->map(fn ($row) => (object) [
                'category' => $row->category,
                'total'    => (float) $row->total,
            ]);
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
     * purchase/sale transaction history with a running stock
     * balance — this is the drill-down the prototype had
     * (renderInvItemDetail) that the summary-only table dropped.
     *
     * @return array{items: Collection, total_stock_value: float, selected: ?array, history: Collection}
     */
    public function inventoryStatement(?int $itemId = null, ?string $from = null, ?string $to = null): array
    {
        $items = Item::query()
            ->orderBy('name')
            ->addSelect([
                // Everything up to the END of the window — this is
                // what "stock on hand" means on a given date, and it
                // is what the closing figure and the stock value are
                // built from. A range must never make stock look
                // lower than it is by hiding purchases made before it.
                'purchased_base' => $this->purchasedBase(null, $to),
                'purchase_cost'  => $this->purchaseCost(null, $to),
                'sold_base'      => $this->soldBase(null, $to),

                // Movements inside the window, and the position going
                // into it — so the table can show "started with X,
                // bought Y, sold Z, left with W" instead of a bare
                // total that says nothing about the period asked for.
                'opening_purchased' => $this->purchasedBase(null, $from ? $this->dayBefore($from) : null),
                'opening_sold'      => $this->soldBase(null, $from ? $this->dayBefore($from) : null),
                'period_purchased'  => $this->purchasedBase($from, $to),
                'period_sold'       => $this->soldBase($from, $to),
            ])
            ->get()
            ->map(function (Item $item) use ($from) {
                $purchased = (float) $item->purchased_base;
                $sold      = (float) $item->sold_base;
                $stock     = $purchased - $sold;

                $openingStock = $from
                    ? (float) $item->opening_purchased - (float) $item->opening_sold
                    : 0.0;

                // Weighted average across every purchase, same
                // definition as Item::averagePurchaseCost(); null when
                // the item has never been bought, so the report shows
                // a blank rather than a fabricated zero cost.
                $avgCost = $purchased > 0 ? (float) $item->purchase_cost / $purchased : null;

                return [
                    'id'                   => $item->id,
                    'name'                 => $item->name,
                    'total_purchased_base' => $purchased,
                    'total_sold_base'      => $sold,
                    'current_stock'        => $stock,
                    'opening_stock'        => round($openingStock, 2),
                    'period_purchased'     => round((float) $item->period_purchased, 2),
                    'period_sold'          => round((float) $item->period_sold, 2),
                    'avg_purchase_cost'    => $avgCost,
                    'stock_value'          => $avgCost !== null ? round($stock * $avgCost, 2) : 0.0,
                    'base_unit_name'       => $item->base_unit_name,
                    // Flags the summary table can badge without the
                    // Vue layer re-deriving thresholds of its own.
                    'is_negative'          => $stock < 0,
                    'is_out_of_stock'      => $stock <= 0 && $purchased > 0,
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
     * The three stock subqueries, each optionally bounded by the
     * report's window. They are built here rather than inline so the
     * "up to the end of the range" and "inside the range" versions
     * cannot drift apart — they are the same query with different
     * bounds, and a difference between them would be invisible in
     * the output but wrong in the totals.
     *
     * The date lives on the parent document (inventory_purchases /
     * sales), not on the line, so each one joins back to it.
     */
    private function purchasedBase(?string $from, ?string $to)
    {
        return $this->stockSubquery(
            InventoryPurchaseLine::query()->selectRaw('COALESCE(SUM(inventory_purchase_lines.qty * inventory_purchase_lines.qty_per_uom), 0)'),
            'inventory_purchases', 'inventory_purchase_lines.inventory_purchase_id', $from, $to
        );
    }

    private function purchaseCost(?string $from, ?string $to)
    {
        return $this->stockSubquery(
            InventoryPurchaseLine::query()->selectRaw('COALESCE(SUM(inventory_purchase_lines.line_total), 0)'),
            'inventory_purchases', 'inventory_purchase_lines.inventory_purchase_id', $from, $to
        );
    }

    private function soldBase(?string $from, ?string $to)
    {
        return $this->stockSubquery(
            SaleLine::query()->selectRaw('COALESCE(SUM(sale_lines.qty), 0)'),
            'sales', 'sale_lines.sale_id', $from, $to
        );
    }

    private function stockSubquery($query, string $parentTable, string $foreignKey, ?string $from, ?string $to)
    {
        $query->join($parentTable, "{$parentTable}.id", '=', $foreignKey)
            ->whereColumn('item_id', 'items.id');

        if ($from) {
            $query->where("{$parentTable}.date", '>=', $from);
        }

        if ($to) {
            $query->where("{$parentTable}.date", '<=', $to);
        }

        return $query;
    }

    private function dayBefore(string $date): string
    {
        return Carbon::parse($date)->subDay()->toDateString();
    }

    /**
     * One item's purchase + sale lines merged into a single
     * chronological ledger with a running stock balance — every
     * quantity here is in base units so the running total lines up
     * with `current_stock` above.
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

        $rows = $purchases->concat($sales)->sortBy('date')->values();

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
            ->with(['customer:id,name', 'vendor:id,name'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Payment $p) => [
                'date'      => $p->date->toDateString(),
                'amount'    => (float) $p->amount,
                'method'    => $p->method,
                'direction' => $p->direction,
                'party'     => $p->customer?->name ?? $p->vendor?->name ?? '—',
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
     * Trial Balance — every account's total debits, total credits,
     * and net balance as of a given date, straight from the general
     * ledger (JournalLine). Meant for the company's auditor: this is
     * the standard starting point for preparing financial
     * statements, so it deliberately mirrors the layout an auditor
     * already expects rather than anything simplified for the shop
     * owner. Only accounts with any activity are included, ordered
     * by account code (the conventional asset → liability → equity →
     * income → expense order, since STANDARD_CODES was numbered
     * that way).
     *
     * @return array{as_of: string, rows: Collection, total_debit: float, total_credit: float, is_balanced: bool}
     */
    public function trialBalance(string $asOf): array
    {
        $sums = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.date', '<=', $asOf)
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, SUM(journal_lines.debit) as total_debit, SUM(journal_lines.credit) as total_credit')
            ->get()
            ->keyBy('account_id');

        $rows = Account::query()
            ->whereIn('id', $sums->keys())
            ->orderBy('code')
            ->get()
            ->map(function (Account $account) use ($sums) {
                $debit  = (float) ($sums[$account->id]->total_debit ?? 0);
                $credit = (float) ($sums[$account->id]->total_credit ?? 0);

                // Raw net, debit minus credit — NOT flipped by account
                // type. A real trial balance shows whichever side an
                // account's balance actually falls on, even when that's
                // the "abnormal" side (a liability that ended up net
                // debit because it was overpaid, an asset that went net
                // credit). Forcing every balance onto its textbook
                // normal side — what this used to do — silently zeroed
                // out abnormal balances instead of showing them on the
                // other column, which is exactly what broke the totals.
                $net = round($debit - $credit, 2);

                return [
                    'code'    => $account->code,
                    'name'    => $account->name,
                    'name_ar' => $account->name_ar,
                    'type'    => $account->type,
                    'debit_balance'  => $net > 0 ? $net : 0.0,
                    'credit_balance' => $net < 0 ? -$net : 0.0,
                ];
            });

        return [
            'as_of' => $asOf,
            'rows' => $rows,
            'total_debit'  => round((float) $rows->sum('debit_balance'), 2),
            'total_credit' => round((float) $rows->sum('credit_balance'), 2),
            'is_balanced'  => abs($rows->sum('debit_balance') - $rows->sum('credit_balance')) < 0.01,
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
