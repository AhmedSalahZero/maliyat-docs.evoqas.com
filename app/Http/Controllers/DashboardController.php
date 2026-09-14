<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\EquipmentPurchase;
use App\Models\Expense;
use App\Models\InventoryPurchase;
use App\Models\Item;
use App\Models\Payment;
use App\Models\Sale;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — DashboardController (company side)
//  Location: app/Http/Controllers/App/DashboardController.php
//
//  Home / landing page after login for company_admin/employee.
//  On mobile this sits above the six quick-record cards; on
//  desktop it's the first step-nav tab and stands on its own as
//  the results-summary dashboard (income / expenses / net / top
//  product / cash balance / what needs attention).
//
//  income/expenses/net/top-product are THIS MONTH (calendar month
//  to date). Cash balance is ALL-TIME — it's a running balance, not
//  a monthly figure, so scoping it to the month would be wrong.
// ══════════════════════════════════════════════════════════════════
class DashboardController extends Controller
{
    public function index(): Response
    {
        $from = now()->startOfMonth()->toDateString();
        $to   = now()->endOfMonth()->toDateString();

        $incomeThisMonth = (float) Payment::query()
            ->whereBetween('date', [$from, $to])->where('direction', 'in')->sum('amount');

        $expensesThisMonth = (float) Payment::query()
            ->whereBetween('date', [$from, $to])->where('direction', 'out')->sum('amount');

        $openInvoicesCount = Sale::all()->filter(fn (Sale $s) => $s->balance() > 0.004)->count();

        $openBillsCount = Expense::all()->filter(fn (Expense $e) => $e->balance() > 0.004)->count()
            + InventoryPurchase::all()->filter(fn (InventoryPurchase $p) => $p->balance() > 0.004)->count()
            + EquipmentPurchase::all()->filter(fn (EquipmentPurchase $p) => $p->balance() > 0.004)->count();

        // All-time running cash balance — every receipt in, every
        // payment out, regardless of month. This is a snapshot of
        // "how much cash does the business actually have right now".
        $cashBalance = (float) Payment::query()->where('direction', 'in')->sum('amount')
            - (float) Payment::query()->where('direction', 'out')->sum('amount');

        // Best-selling item this month by revenue. withSum stays
        // scoped to this company via Item's BelongsToCompany trait —
        // no raw joins, so the company global scope still applies.
        $topProduct = Item::query()
            ->withSum(['saleLines as revenue' => function ($query) use ($from, $to) {
                $query->whereHas('sale', fn ($sale) => $sale->whereBetween('date', [$from, $to]));
            }], 'line_total')
            ->orderByDesc('revenue')
            ->first();

        return Inertia::render('App/Dashboard', [
            'income_this_month'   => $incomeThisMonth,
            'expenses_this_month' => $expensesThisMonth,
            'net_this_month'      => round($incomeThisMonth - $expensesThisMonth, 2),
            'cash_balance'        => round($cashBalance, 2),
            'open_invoices_count' => $openInvoicesCount,
            'open_bills_count'    => $openBillsCount,
            'top_product'         => ($topProduct && $topProduct->revenue > 0) ? [
                'name'    => $topProduct->name,
                'revenue' => (float) $topProduct->revenue,
            ] : null,
        ]);
    }
}
