<?php

use App\Http\Controllers\Admin\ActivityController as AdminActivityController;
use App\Http\Controllers\Admin\CompanyController as AdminCompanyController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\App\CategoryController;
use App\Http\Controllers\App\SaleDraftController;
use App\Http\Controllers\App\PaymentChannelController;
use App\Http\Controllers\App\CustodyController;
use App\Http\Controllers\App\CustomerController;
use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\EquipmentPurchaseController;
use App\Http\Controllers\App\ExpenseController;
use App\Http\Controllers\App\InventoryPurchaseController;
use App\Http\Controllers\App\ItemController;
use App\Http\Controllers\App\OpeningBalanceController;
use App\Http\Controllers\App\OwnerController;
use App\Http\Controllers\App\OwnerTransactionController;
use App\Http\Controllers\App\PaymentController;
use App\Http\Controllers\App\ProductionOrderController;
use App\Http\Controllers\App\ProfileController;
use App\Http\Controllers\App\ReportController;
use App\Http\Controllers\App\ReportExportController;
use App\Http\Controllers\App\SaleController;
use App\Http\Controllers\App\SalesChannelController;
use App\Http\Controllers\App\UserController as AppUserController;
use App\Http\Controllers\App\VendorController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Main Route File
//
//  This file completely replaces InPractice's route file (Cases,
//  Forum, Job Club, Freelance, Surveys, Share Knowledge — none of
//  that applies to a bookkeeping app). See CHANGELOG note in the
//  project docs for what was here before.
//
//  Structure:
//    /                  → redirects by role (guest/super_admin/company)
//    /register          → public company sign-up (creates Company +
//                          its first company_admin)
//    /admin/*           → super_admin only  (EnsureAdmin middleware,
//                          aliased 'admin' — see bootstrap/app.php)
//    /app/*             → company_admin + employee (EnsureMember
//                          middleware, aliased 'member' — same file,
//                          repurposed; see its own doc comment)
//    auth.php           → login, register (POST), password reset,
//                          email verification (Breeze, unchanged)
// ══════════════════════════════════════════════════════════════════

// ── Root → role-based redirect ──────────────────────────────────
Route::get('/', function () {
    if (auth()->check()) {
        $user = auth()->user();

        if ($user->isSuperAdmin()) {
            return redirect()->route('admin.dashboard');
        }

        if (! $user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        return redirect()->route('app.dashboard');
    }

    return redirect()->route('login');
})->name('home');

// ── Register — GET page comes from RegisteredUserController (auth.php);
//    POST /register is also owned by auth.php (guest + throttle) ──────

// ══════════════════════════════════════════════════════════════════
//  ADMIN ROUTES (super_admin) — Prefix: /admin — Name: admin.*
// ══════════════════════════════════════════════════════════════════
Route::middleware(['auth', 'auth.session', 'verified', 'admin', 'no-duplicate'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');

        Route::get('/companies', [AdminCompanyController::class, 'index'])->name('companies.index');
        Route::post('/companies', [AdminCompanyController::class, 'store'])->name('companies.store');
        Route::patch('/companies/{company}/toggle-active', [AdminCompanyController::class, 'toggleActive'])
            ->name('companies.toggle-active');
        Route::delete('/companies/{company}', [AdminCompanyController::class, 'destroy'])->name('companies.destroy');

        // Who is using the app, one row per person per day — see
        // ActivityController for why it is deduped that way.
        Route::get('/activity', [AdminActivityController::class, 'index'])->name('activity.index');
    });

// ══════════════════════════════════════════════════════════════════
//  APP ROUTES (company_admin + employee) — Prefix: /app — Name: app.*
// ══════════════════════════════════════════════════════════════════
Route::middleware(['auth', 'auth.session', 'verified', 'member', 'no-duplicate'])
    ->prefix('app')
    ->name('app.')
    ->group(function () {

        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        // ── Lookups / quick-add (Customers, Vendors, Categories, Items) ──
        Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
        Route::patch('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');

        Route::get('/vendors', [VendorController::class, 'index'])->name('vendors.index');
        Route::post('/vendors', [VendorController::class, 'store'])->name('vendors.store');
        Route::patch('/vendors/{vendor}', [VendorController::class, 'update'])->name('vendors.update');

        Route::get('/owners', [OwnerController::class, 'index'])->name('owners.index');
        Route::post('/owners', [OwnerController::class, 'store'])->name('owners.store');
        Route::patch('/owners/{owner}', [OwnerController::class, 'update'])->name('owners.update');

        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::patch('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');

        Route::get('/payment-channels', [PaymentChannelController::class, 'index'])->name('payment-channels.index');
        Route::post('/payment-channels', [PaymentChannelController::class, 'store'])->name('payment-channels.store');
        Route::patch('/payment-channels/{paymentChannel}', [PaymentChannelController::class, 'update'])->name('payment-channels.update');

        Route::get('/items', [ItemController::class, 'index'])->name('items.index');
        Route::post('/items', [ItemController::class, 'store'])->name('items.store');
        Route::patch('/items/{item}', [ItemController::class, 'update'])->name('items.update');

        // ── Sales ──────────────────────────────────────────────────
        Route::get('/sales', [SaleController::class, 'index'])->name('sales.index');
        Route::post('/sales', [SaleController::class, 'store'])->name('sales.store');
        Route::put('/sales/{sale}', [SaleController::class, 'update'])->name('sales.update');
        Route::delete('/sales/{sale}', [SaleController::class, 'destroy'])->name('sales.destroy');

        // Unfinished sales ("drafts") — no accounting effect, see
        // SaleDraftController. Exempt from the duplicate guard:
        // pressing "Save as draft" twice with nothing changed is
        // harmless, and being told "duplicate submission" for it
        // would only confuse.
        Route::post('/sale-drafts', [SaleDraftController::class, 'store'])->name('sale-drafts.store')->withoutMiddleware('no-duplicate');
        Route::put('/sale-drafts/{saleDraft}', [SaleDraftController::class, 'update'])->name('sale-drafts.update')->withoutMiddleware('no-duplicate');
        Route::delete('/sale-drafts/{saleDraft}', [SaleDraftController::class, 'destroy'])->name('sale-drafts.destroy')->withoutMiddleware('no-duplicate');

        Route::get('/sales-channels', [SalesChannelController::class, 'index'])->name('sales-channels.index');
        Route::post('/sales-channels', [SalesChannelController::class, 'store'])->name('sales-channels.store');
        Route::patch('/sales-channels/{salesChannel}', [SalesChannelController::class, 'update'])->name('sales-channels.update');

        // ── Expenses (one-off + recurring) ──────────────────────────
        Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
        Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
        Route::put('/expenses/{expense}', [ExpenseController::class, 'update'])->name('expenses.update');
        Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');
        Route::post('/expenses/recurring', [ExpenseController::class, 'storeRecurring'])->name('expenses.recurring.store');
        Route::delete('/expenses/recurring/{recurringId}', [ExpenseController::class, 'cancelRecurring'])
            ->name('expenses.recurring.cancel');

        // ── Inventory Purchase ──────────────────────────────────────
        Route::get('/inventory-purchases', [InventoryPurchaseController::class, 'index'])->name('inventory-purchases.index');
        Route::post('/inventory-purchases', [InventoryPurchaseController::class, 'store'])->name('inventory-purchases.store');
        Route::put('/inventory-purchases/{inventoryPurchase}', [InventoryPurchaseController::class, 'update'])->name('inventory-purchases.update');
        Route::delete('/inventory-purchases/{inventoryPurchase}', [InventoryPurchaseController::class, 'destroy'])->name('inventory-purchases.destroy');

        // ── Production Orders ("Day Production") ─────────────────────
        //
        //  Gated on the company having Production switched on, on the
        //  SERVER. It used to be gated only by the frontend choosing
        //  not to draw the menu entry, which is a convention rather
        //  than a rule — see EnsureBusinessType.
        Route::middleware('business-type:production')->group(function () {
            Route::get('/production-orders', [ProductionOrderController::class, 'index'])->name('production-orders.index');
            Route::post('/production-orders', [ProductionOrderController::class, 'store'])->name('production-orders.store');
            Route::put('/production-orders/{productionOrder}', [ProductionOrderController::class, 'update'])->name('production-orders.update');
            Route::delete('/production-orders/{productionOrder}', [ProductionOrderController::class, 'destroy'])->name('production-orders.destroy');
        });

        // ── Equipment & Vehicles ─────────────────────────────────────
        Route::get('/equipment-purchases', [EquipmentPurchaseController::class, 'index'])->name('equipment-purchases.index');
        Route::post('/equipment-purchases', [EquipmentPurchaseController::class, 'store'])->name('equipment-purchases.store');
        Route::put('/equipment-purchases/{equipmentPurchase}', [EquipmentPurchaseController::class, 'update'])->name('equipment-purchases.update');
        Route::delete('/equipment-purchases/{equipmentPurchase}', [EquipmentPurchaseController::class, 'destroy'])->name('equipment-purchases.destroy');

        // ── Custody (عهدة) ───────────────────────────────────────────
        Route::get('/custodies', [CustodyController::class, 'index'])->name('custodies.index');
        Route::post('/custodies', [CustodyController::class, 'store'])->name('custodies.store');
        Route::put('/custodies/{custody}', [CustodyController::class, 'update'])->name('custodies.update');
        Route::delete('/custodies/{custody}', [CustodyController::class, 'destroy'])->name('custodies.destroy');
        Route::patch('/custodies/{custody}/settle', [CustodyController::class, 'settle'])->name('custodies.settle');

        // ── Receive / Pay Money ──────────────────────────────────────
        Route::get('/payments', [PaymentController::class, 'page'])->name('payments.index');
        Route::get('/payments/open-invoices', [PaymentController::class, 'openInvoices'])->name('payments.open-invoices');
        Route::get('/payments/open-bills', [PaymentController::class, 'openBills'])->name('payments.open-bills');
        Route::post('/payments/receive', [PaymentController::class, 'storeReceipt'])->name('payments.receive');
        Route::post('/payments/pay', [PaymentController::class, 'storePayment'])->name('payments.pay');
        // Correct or remove a single payment — used by the edit view
        // of a sale or bill when a payment entered at creation time
        // was wrong.
        Route::patch('/payments/{payment}', [PaymentController::class, 'update'])->name('payments.update');
        Route::delete('/payments/{payment}', [PaymentController::class, 'destroy'])->name('payments.destroy');

        // ── Owner Injection / Withdrawal ─────────────────────────────
        //  Sits right after Receive / Pay in the step-nav (see
        //  quickRecordActions.js) — a deliberately separate screen
        //  from it, not folded into the Payment worklist above, since
        //  an owner transaction is never against an invoice or bill.
        Route::get('/owner-transactions', [OwnerTransactionController::class, 'index'])->name('owner-transactions.index');
        Route::post('/owner-transactions', [OwnerTransactionController::class, 'store'])->name('owner-transactions.store');
        Route::put('/owner-transactions/{ownerTransaction}', [OwnerTransactionController::class, 'update'])->name('owner-transactions.update');
        Route::delete('/owner-transactions/{ownerTransaction}', [OwnerTransactionController::class, 'destroy'])->name('owner-transactions.destroy');

        // ── Reports ───────────────────────────────────────────────────
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/ledger', [ReportController::class, 'ledger'])->name('reports.ledger');
        Route::get('/reports/profit-loss', [ReportController::class, 'profitAndLoss'])->name('reports.profit-loss');
        Route::get('/reports/customer-statement/{customer?}', [ReportController::class, 'customerStatement'])
            ->name('reports.customer-statement');
        Route::get('/reports/supplier-statement/{vendor?}', [ReportController::class, 'supplierStatement'])
            ->name('reports.supplier-statement');
        Route::get('/reports/inventory-statement', [ReportController::class, 'inventoryStatement'])
            ->name('reports.inventory-statement');
        Route::get('/reports/cash-flow', [ReportController::class, 'cashFlow'])->name('reports.cash-flow');
        Route::get('/reports/owner-statement/{owner?}', [ReportController::class, 'ownerStatement'])
            ->name('reports.owner-statement');

        // ── Report exports (Excel / PDF, {format} is "excel" or "pdf") ──
        Route::get('/reports/ledger/export/{format}', [ReportExportController::class, 'ledger'])
            ->name('reports.ledger.export');
        Route::get('/reports/profit-loss/export/{format}', [ReportExportController::class, 'profitAndLoss'])
            ->name('reports.profit-loss.export');
        Route::get('/reports/customer-statement/{customer}/export/{format}', [ReportExportController::class, 'customerStatement'])
            ->name('reports.customer-statement.export');
        Route::get('/reports/supplier-statement/{vendor}/export/{format}', [ReportExportController::class, 'supplierStatement'])
            ->name('reports.supplier-statement.export');
        Route::get('/reports/inventory-statement/export/{format}', [ReportExportController::class, 'inventoryStatement'])
            ->name('reports.inventory-statement.export');
        Route::get('/reports/cash-flow/export/{format}', [ReportExportController::class, 'cashFlow'])
            ->name('reports.cash-flow.export');

        // ── External Audit (for the company's auditor) ──────────────
        //  The Trial Balance, the Balance Sheet, and the Journal
        //  behind one entry point, with a toggle inside — they are
        //  read together, so they are reached together. ?view=
        //  picks which one shows. See ReportController::externalAudit().
        Route::get('/reports/external-audit', [ReportController::class, 'externalAudit'])
            ->name('reports.external-audit');

        // The two links these replaced. Kept as redirects so an
        // auditor's bookmark, or a link in an old email, still lands
        // on the right half rather than a 404.
        Route::get('/reports/trial-balance', fn () => redirect()->route('app.reports.external-audit', ['view' => 'trial-balance'] + request()->query()))
            ->name('reports.trial-balance');
        Route::get('/reports/journal', fn () => redirect()->route('app.reports.external-audit', ['view' => 'journal'] + request()->query()))
            ->name('reports.journal');
        Route::get('/reports/trial-balance/export/{format}', [ReportExportController::class, 'trialBalance'])
            ->name('reports.trial-balance.export');
        Route::get('/reports/balance-sheet/export/{format}', [ReportExportController::class, 'balanceSheet'])
            ->name('reports.balance-sheet.export');
        Route::get('/reports/journal/export/{format}', [ReportExportController::class, 'journal'])
            ->name('reports.journal.export');

        // ── Opening Balances (one-time setup, company_admin) ────────
        Route::get('/opening-balance', [OpeningBalanceController::class, 'index'])->name('opening-balance.index');
        Route::post('/opening-balance', [OpeningBalanceController::class, 'store'])->name('opening-balance.store');
        Route::delete('/opening-balance', [OpeningBalanceController::class, 'reset'])->name('opening-balance.reset');

        // ── Team (company_admin manages employees) ───────────────────
        Route::get('/team', [AppUserController::class, 'index'])->name('team.index');
        Route::post('/team', [AppUserController::class, 'store'])->name('team.store');
        Route::patch('/team/{user}', [AppUserController::class, 'update'])->name('team.update');
        Route::patch('/team/{user}/toggle-active', [AppUserController::class, 'toggleActive'])->name('team.toggle-active');

        // ── Profile & Preferences ─────────────────────────────────────
        Route::get('/profile', [ProfileController::class, 'index'])->name('profile.index');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::patch('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
        // Company-admin only — see ProfileController::updateBusinessTypes().
        Route::patch('/profile/business-types', [ProfileController::class, 'updateBusinessTypes'])->name('profile.business-types');

        // Called by the theme/locale toggles — idempotent, one field, no page reload needed.
        //
        // Exempt from the duplicate guard: switching dark -> light ->
        // dark inside a few seconds sends the same body twice on
        // purpose, and there is nothing to double-record — the value
        // is simply set to what it already was.
        Route::patch('/preferences/theme', function (Request $request) {
            $request->validate(['theme' => ['required', 'string', 'in:light,dark']]);
            $request->user()->update(['theme' => $request->theme]);

            return back();
        })->name('preferences.theme')->withoutMiddleware('no-duplicate');

        Route::patch('/preferences/locale', function (Request $request) {
            $request->validate(['locale' => ['required', 'string', 'in:en,ar']]);
            $request->user()->update(['language' => $request->locale]);

            return back();
        })->name('preferences.locale')->withoutMiddleware('no-duplicate');
    });

// ── Auth Routes (Breeze) ───────────────────────────────────────
require __DIR__.'/auth.php';
