<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\EquipmentPurchase;
use App\Models\InventoryPurchase;
use App\Models\Item;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — ReportController
//  Location: app/Http/Controllers/App/ReportController.php
//
//  All reports here are CASH-BASIS: "income received" / "expenses
//  paid" come from actual Payment rows, not invoice/bill totals —
//  matching the prototype's simple bookkeeping model built for
//  micro-companies (no accrual accounting). Every method accepts
//  optional ?from=&to= (Y-m-d) date-range filters; both default to
//  the current calendar month.
// ══════════════════════════════════════════════════════════════════
class ReportController extends Controller
{
    /**
     * Reports hub — the destination for the "Reports" tab in the
     * bottom nav / sidebar. Just a card grid linking to the six
     * report methods below plus the ledger; no data needed here.
     */
    public function index(): Response
    {
        return Inertia::render('App/Reports/Index');
    }

    /**
     * "All Entries" — every sale, expense, inventory/equipment
     * purchase, and custody hand-out in one chronological list.
     */
    public function ledger(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        $rows = collect();

        Sale::query()->whereBetween('date', [$from, $to])->with('customer:id,name')->get()
            ->each(fn (Sale $s) => $rows->push([
                'type' => 'sale', 'id' => $s->id, 'date' => $s->date->toDateString(),
                'party' => $s->customer?->name, 'amount' => (float) $s->amount,
                'balance' => $s->balance(), 'status' => $this->status($s),
            ]));

        Expense::query()->whereBetween('date', [$from, $to])->with('vendor:id,name')->get()
            ->each(fn (Expense $e) => $rows->push([
                'type' => 'expense', 'id' => $e->id, 'date' => $e->date->toDateString(),
                'party' => $e->vendor?->name, 'amount' => (float) $e->amount,
                'balance' => $e->balance(), 'status' => $this->status($e),
            ]));

        InventoryPurchase::query()->whereBetween('date', [$from, $to])->with('vendor:id,name')->get()
            ->each(fn (InventoryPurchase $p) => $rows->push([
                'type' => 'inventory_purchase', 'id' => $p->id, 'date' => $p->date->toDateString(),
                'party' => $p->vendor?->name, 'amount' => (float) $p->amount,
                'balance' => $p->balance(), 'status' => $this->status($p),
            ]));

        EquipmentPurchase::query()->whereBetween('date', [$from, $to])->with('vendor:id,name')->get()
            ->each(fn (EquipmentPurchase $p) => $rows->push([
                'type' => 'equipment_purchase', 'id' => $p->id, 'date' => $p->date->toDateString(),
                'party' => $p->vendor?->name, 'amount' => (float) $p->amount,
                'balance' => $p->balance(), 'status' => $this->status($p),
            ]));

        $rows = $rows->sortByDesc('date')->values();

        return Inertia::render('App/Reports/Ledger', [
            'entries' => $rows, 'from' => $from, 'to' => $to,
        ]);
    }

    public function profitAndLoss(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        $incomeReceived = (float) Payment::query()
            ->whereBetween('date', [$from, $to])
            ->where('direction', 'in')
            ->sum('amount');

        $expensesPaid = (float) Payment::query()
            ->whereBetween('date', [$from, $to])
            ->where('direction', 'out')
            ->sum('amount');

        $expensesByCategory = Expense::query()
            ->whereBetween('date', [$from, $to])
            ->join('categories', 'categories.id', '=', 'expenses.category_id')
            ->selectRaw('categories.name as category, SUM(expenses.amount) as total')
            ->groupBy('categories.name')
            ->orderByDesc('total')
            ->get();

        $incomeByItem = Sale::query()
            ->whereBetween('sales.date', [$from, $to])
            ->join('sale_lines', 'sale_lines.sale_id', '=', 'sales.id')
            ->leftJoin('items', 'items.id', '=', 'sale_lines.item_id')
            ->selectRaw("COALESCE(items.name, 'Other') as item, SUM(sale_lines.line_total) as total")
            ->groupBy('item')
            ->orderByDesc('total')
            ->get();

        return Inertia::render('App/Reports/ProfitAndLoss', [
            'from' => $from, 'to' => $to,
            'income_received'      => $incomeReceived,
            'expenses_paid'        => $expensesPaid,
            'net_profit'           => round($incomeReceived - $expensesPaid, 2),
            'expenses_by_category' => $expensesByCategory,
            'income_by_item'       => $incomeByItem,
        ]);
    }

    public function customerStatement(Request $request, ?Customer $customer = null): Response
    {
        $customers = Customer::query()->orderBy('name')->get(['id', 'name']);

        if (! $customer) {
            return Inertia::render('App/Reports/CustomerStatement', [
                'customers' => $customers, 'customer' => null, 'entries' => [], 'balance' => 0,
            ]);
        }

        $entries = collect();

        $customer->sales()->with('payments')->orderBy('date')->get()->each(function (Sale $sale) use (&$entries) {
            $entries->push([
                'date' => $sale->date->toDateString(), 'type' => 'sale',
                'ref' => "Sale #{$sale->id}", 'debit' => (float) $sale->amount, 'credit' => 0,
            ]);

            foreach ($sale->payments as $payment) {
                $entries->push([
                    'date' => $payment->date->toDateString(), 'type' => 'payment',
                    'ref' => "Payment #{$payment->id}", 'debit' => 0, 'credit' => (float) $payment->amount,
                ]);
            }
        });

        $entries = $entries->sortBy('date')->values();

        $running = 0;
        $entries = $entries->map(function (array $entry) use (&$running) {
            $running += $entry['debit'] - $entry['credit'];
            $entry['running_balance'] = round($running, 2);

            return $entry;
        });

        return Inertia::render('App/Reports/CustomerStatement', [
            'customers' => $customers,
            'customer'  => $customer->only(['id', 'name']),
            'entries'   => $entries,
            'balance'   => round($running, 2),
        ]);
    }

    public function supplierStatement(Request $request, ?Vendor $vendor = null): Response
    {
        $vendors = Vendor::query()->orderBy('name')->get(['id', 'name', 'type']);

        if (! $vendor) {
            return Inertia::render('App/Reports/SupplierStatement', [
                'vendors' => $vendors, 'vendor' => null, 'entries' => [], 'balance' => 0,
            ]);
        }

        $entries = collect();

        $addBills = function ($bills, string $label) use (&$entries) {
            foreach ($bills as $bill) {
                $entries->push([
                    'date' => $bill->date->toDateString(), 'type' => $label,
                    'ref' => ucfirst($label)." #{$bill->id}", 'debit' => 0, 'credit_owed' => (float) $bill->amount,
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

        $entries = $entries->sortBy('date')->values();

        $running = 0;
        $entries = $entries->map(function (array $entry) use (&$running) {
            // Owed to the vendor increases the balance; paying them decreases it.
            $running += $entry['credit_owed'] - $entry['debit'];
            $entry['running_balance'] = round($running, 2);

            return $entry;
        });

        return Inertia::render('App/Reports/SupplierStatement', [
            'vendors' => $vendors,
            'vendor'  => $vendor->only(['id', 'name', 'type']),
            'entries' => $entries,
            'balance' => round($running, 2),
        ]);
    }

    /**
     * Per-item stock levels — reuses Item's own math (Item model
     * already implements totalPurchasedBase/totalSoldBase/etc.).
     */
    public function inventoryStatement(): Response
    {
        $items = Item::all()->map(function (Item $item) {
            $stock   = $item->currentStock();
            $avgCost = $item->averagePurchaseCost();

            return [
                'id'                   => $item->id,
                'name'                 => $item->name,
                'total_purchased_base' => $item->totalPurchasedBase(),
                'total_sold_base'      => $item->totalSoldBase(),
                'current_stock'        => $stock,
                'avg_purchase_cost'    => $avgCost,
                'stock_value'          => $avgCost !== null ? round($stock * $avgCost, 2) : 0.0,
                'base_unit_name'       => $item->base_unit_name,
            ];
        });

        return Inertia::render('App/Reports/InventoryStatement', [
            'items'            => $items,
            'total_stock_value'=> round($items->sum('stock_value'), 2),
        ]);
    }

    public function cashFlow(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        $byMethod = Payment::query()
            ->whereBetween('date', [$from, $to])
            ->selectRaw('method, direction, SUM(amount) as total')
            ->groupBy('method', 'direction')
            ->get();

        $cashIn  = (float) Payment::query()->whereBetween('date', [$from, $to])->where('direction', 'in')->sum('amount');
        $cashOut = (float) Payment::query()->whereBetween('date', [$from, $to])->where('direction', 'out')->sum('amount');

        return Inertia::render('App/Reports/CashFlow', [
            'from' => $from, 'to' => $to,
            'cash_in'   => $cashIn,
            'cash_out'  => $cashOut,
            'net_flow'  => round($cashIn - $cashOut, 2),
            'by_method' => $byMethod,
        ]);
    }

    /**
     * @return array{0:string,1:string} [from, to] as Y-m-d, defaulting to the current month.
     */
    private function range(Request $request): array
    {
        $from = $request->filled('from') ? Carbon::parse($request->string('from')) : now()->startOfMonth();
        $to   = $request->filled('to') ? Carbon::parse($request->string('to')) : now()->endOfMonth();

        return [$from->toDateString(), $to->toDateString()];
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
