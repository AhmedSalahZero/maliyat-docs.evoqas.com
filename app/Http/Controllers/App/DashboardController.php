<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\EquipmentPurchase;
use App\Models\Expense;
use App\Models\InventoryPurchase;
use App\Models\Payment;
use App\Models\Sale;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — DashboardController (company side)
//  Location: app/Http/Controllers/App/DashboardController.php
//
//  Landing page after login for company_admin/employee: this
//  month's cash position plus what needs attention (open invoices
//  and bills), so the first thing a shop owner sees is "do I need
//  to collect or pay anything today".
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

        return Inertia::render('App/Dashboard', [
            'income_this_month'   => $incomeThisMonth,
            'expenses_this_month' => $expensesThisMonth,
            'net_this_month'      => round($incomeThisMonth - $expensesThisMonth, 2),
            'open_invoices_count' => $openInvoicesCount,
            'open_bills_count'    => $openBillsCount,
        ]);
    }
}
