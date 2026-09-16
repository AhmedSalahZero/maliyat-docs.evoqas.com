<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\EquipmentPurchase;
use App\Models\Expense;
use App\Models\InventoryPurchase;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — DashboardController (company side)
//
//  The landing page after login. It answers, in this order:
//    1. Where do I stand this period (money in, out, net, cash)?
//    2. What needs chasing (open invoices and bills)?
//    3. What is actually selling, and who am I doing business with?
//
//  Deliberately NOT here: gross profit. The people using this are
//  shop owners, not accountants — a margin figure that depends on
//  weighted-average cost invites the wrong conclusion far more often
//  than it informs. The underlying COGS is still posted to the
//  ledger (JournalService::postCostOfGoodsSold) for anyone who needs
//  it from a real report.
//
//  Every figure below is a grouped aggregate computed in SQL. The
//  previous version called Sale::all()->filter(...) and then
//  balance() per row — four unbounded table loads and a query per
//  record, exactly the pattern that made the Receive/Pay screen
//  unusable at a few hundred rows.
//
//  ⚠ TENANT ISOLATION — READ BEFORE EDITING (QA audit, Sep 2026):
//  Every other controller in this app gets its company isolation for
//  free, automatically, from BelongsToCompany's global scope on each
//  Eloquent model. This controller opts OUT of that safety net on
//  purpose, for the raw-query performance reasons above (DB::table()
//  and DB::raw() do not go through Eloquent, so the global scope
//  never runs). That means every query below has been written to
//  filter by `company_id` (or `sales.company_id`, etc.) BY HAND —
//  verified correct as of this audit — and there is nothing that
//  will warn you if a new or edited query in this file forgets that
//  filter. If you add a query here, add its own `where('company_id',
//  $companyId)` explicitly; do not assume it's scoped for you.
// ══════════════════════════════════════════════════════════════════
class DashboardController extends Controller
{
    /** How many rows each "top" list and the donut chart carry. */
    private const TOP_LIMIT = 6;

    public function index(Request $request): Response
    {
        $companyId = (int) auth()->user()->company_id;

        [$from, $to, $period] = $this->resolvePeriod($request);

        return Inertia::render('App/Dashboard', [
            // ── Period selector state ────────────────────────────
            'period'     => $period,
            'period_from' => $from,
            'period_to'   => $to,

            // ── Headline figures ─────────────────────────────────
            ...$this->cashFigures($companyId, $from, $to),

            // ── What needs attention ─────────────────────────────
            'open_invoices_count' => $this->openInvoicesCount($companyId),
            'open_bills_count'    => $this->openBillsCount($companyId),

            // ── What is selling, and to whom ─────────────────────
            'sku_sales'     => $this->skuSales($companyId, $from, $to),
            'top_customers' => $this->topCustomers($companyId, $from, $to),
            'top_suppliers' => $this->topSuppliers($companyId, $from, $to),
        ]);
    }

    /**
     * @return array{0:string,1:string,2:string}  [from, to, period key]
     */
    private function resolvePeriod(Request $request): array
    {
        $period = $request->query('period');
        $period = in_array($period, ['month', 'quarter', 'year'], true) ? $period : 'month';

        $now = Carbon::today();

        $from = match ($period) {
            'quarter' => $now->copy()->startOfQuarter(),
            'year'    => $now->copy()->startOfYear(),
            default   => $now->copy()->startOfMonth(),
        };

        $to = match ($period) {
            'quarter' => $now->copy()->endOfQuarter(),
            'year'    => $now->copy()->endOfYear(),
            default   => $now->copy()->endOfMonth(),
        };

        return [$from->toDateString(), $to->toDateString(), $period];
    }

    /**
     * Money actually moved in the period, plus the running cash
     * position (which is all-time, not period-bound — a balance is
     * only meaningful as of today).
     */
    private function cashFigures(int $companyId, string $from, string $to): array
    {
        // Opening-balance cash/bank rows (is_opening_balance = true)
        // are deliberately excluded from period_in/period_out — they
        // are a starting position, not income earned this period —
        // but ARE included in cash_balance, which is meant to be the
        // real, all-time amount of cash the company actually has.
        $rows = DB::table('payments')
            ->where('company_id', $companyId)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN direction = 'in'  AND date BETWEEN ? AND ? AND is_opening_balance = 0 THEN amount END), 0) AS period_in,
                COALESCE(SUM(CASE WHEN direction = 'out' AND date BETWEEN ? AND ? AND is_opening_balance = 0 THEN amount END), 0) AS period_out,
                COALESCE(SUM(CASE WHEN direction = 'in'  THEN amount ELSE -amount END), 0) AS cash_balance
            ", [$from, $to, $from, $to])
            ->first();

        $in  = (float) ($rows->period_in ?? 0);
        $out = (float) ($rows->period_out ?? 0);

        return [
            'income_this_month'   => round($in, 2),
            'expenses_this_month' => round($out, 2),
            'net_this_month'      => round($in - $out, 2),
            'cash_balance'        => round((float) ($rows->cash_balance ?? 0), 2),
        ];
    }

    /**
     * Invoices with anything still owed. The balance test runs as a
     * correlated subquery so the count comes back as one number
     * rather than a table scan plus a query per row.
     */
    private function openInvoicesCount(int $companyId): int
    {
        return (int) DB::table('sales')
            ->where('company_id', $companyId)
            ->whereRaw($this->unsettledCondition('sales', Sale::class), [$companyId])
            ->count();
    }

    private function openBillsCount(int $companyId): int
    {
        $sources = [
            'expenses'            => Expense::class,
            'inventory_purchases' => InventoryPurchase::class,
            'equipment_purchases' => EquipmentPurchase::class,
        ];

        $total = 0;

        foreach ($sources as $table => $model) {
            $total += (int) DB::table($table)
                ->where('company_id', $companyId)
                ->whereRaw($this->unsettledCondition($table, $model), [$companyId])
                ->count();
        }

        return $total;
    }

    /**
     * "Still owes something", as SQL. Shared by both counts above so
     * the definition of open can't drift between them — and it
     * matches PaymentController's worklist, including the 0.004
     * tolerance that keeps float noise from reading as a debt.
     */
    private function unsettledCondition(string $table, string $model): string
    {
        return "COALESCE((
            SELECT SUM(p.amount) FROM payments p
            WHERE p.payable_type = ".DB::getPdo()->quote($model)."
              AND p.payable_id = {$table}.id
              AND p.company_id = ?
        ), 0) < {$table}.amount - 0.004";
    }

    /**
     * Sales per stock item: how much moved, what it earned, and how
     * many separate sales it appeared in. Drives both the donut and
     * the three "top item" cards, so all four read from one query.
     *
     * Lines with no item_id are free-text/service lines with nothing
     * to attribute, so they're excluded rather than lumped together
     * under a misleading label.
     */
    private function skuSales(int $companyId, string $from, string $to): array
    {
        return DB::table('sale_lines')
            ->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
            ->join('items', 'items.id', '=', 'sale_lines.item_id')
            ->where('sales.company_id', $companyId)
            ->whereBetween('sales.date', [$from, $to])
            ->groupBy('items.id', 'items.name')
            ->select([
                'items.id',
                'items.name',
                DB::raw('COALESCE(SUM(sale_lines.qty), 0) AS volume'),
                DB::raw('COALESCE(SUM(sale_lines.line_total), 0) AS value'),
                DB::raw('COUNT(DISTINCT sale_lines.sale_id) AS transactions'),
            ])
            ->orderByDesc('value')
            ->limit(self::TOP_LIMIT)
            ->get()
            ->map(fn ($row) => [
                'id'           => (int) $row->id,
                'name'         => $row->name,
                'volume'       => round((float) $row->volume, 2),
                'value'        => round((float) $row->value, 2),
                'transactions' => (int) $row->transactions,
            ])
            ->all();
    }

    /**
     * Who buys the most. Volume is summed across the customer's sale
     * lines, so a customer who buys many cheap units ranks on volume
     * even when they don't rank on value — which is the whole point
     * of showing all three measures side by side.
     */
    private function topCustomers(int $companyId, string $from, string $to): array
    {
        // No join to sale_lines here on purpose: joining would repeat
        // each invoice once per line and inflate SUM(sales.amount).
        // Volume comes from its own correlated subquery instead, so
        // both figures stay correct without fighting each other.
        $volume = "COALESCE((
            SELECT SUM(l.qty)
            FROM sale_lines l
            JOIN sales s2 ON s2.id = l.sale_id
            WHERE s2.customer_id = customers.id
              AND s2.company_id = sales.company_id
              AND s2.date BETWEEN ? AND ?
        ), 0)";

        return DB::table('sales')
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->where('sales.company_id', $companyId)
            ->whereBetween('sales.date', [$from, $to])
            ->groupBy('customers.id', 'customers.name', 'sales.company_id')
            ->select([
                'customers.id',
                'customers.name',
                DB::raw('COALESCE(SUM(sales.amount), 0) AS value'),
                DB::raw('COUNT(*) AS transactions'),
                DB::raw("{$volume} AS volume"),
            ])
            ->addBinding([$from, $to], 'select')
            ->orderByDesc('value')
            ->limit(self::TOP_LIMIT)
            ->get()
            ->map(fn ($row) => [
                'id'           => (int) $row->id,
                'name'         => $row->name,
                'volume'       => round((float) $row->volume, 2),
                'value'        => round((float) $row->value, 2),
                'transactions' => (int) $row->transactions,
            ])
            ->all();
    }

    /**
     * Who we buy the most from, across all three purchase tables.
     *
     * Volume comes from stock purchases only — an expense or a piece
     * of equipment has no comparable unit count, so counting them
     * would produce a number that means nothing.
     */
    private function topSuppliers(int $companyId, string $from, string $to): array
    {
        $spend = DB::query()
            ->fromSub(
                DB::table('expenses')
                    ->where('company_id', $companyId)
                    ->whereBetween('date', [$from, $to])
                    ->whereNotNull('vendor_id')
                    ->select(['vendor_id', 'amount', DB::raw('0 AS volume')])
                    ->unionAll(
                        DB::table('inventory_purchases')
                            ->where('company_id', $companyId)
                            ->whereBetween('date', [$from, $to])
                            ->whereNotNull('vendor_id')
                            ->select([
                                'vendor_id',
                                'amount',
                                DB::raw('COALESCE((
                                    SELECT SUM(l.qty) FROM inventory_purchase_lines l
                                    WHERE l.inventory_purchase_id = inventory_purchases.id
                                ), 0) AS volume'),
                            ])
                    )
                    ->unionAll(
                        DB::table('equipment_purchases')
                            ->where('company_id', $companyId)
                            ->whereBetween('date', [$from, $to])
                            ->whereNotNull('vendor_id')
                            ->select(['vendor_id', 'amount', DB::raw('0 AS volume')])
                    ),
                'purchases'
            )
            // Belt-and-suspenders (QA audit, Sep 2026): vendor_id here
            // is already guaranteed to belong to this company — every
            // Store*Request validates vendor_id against the caller's
            // own company_id before it can ever be saved — but this
            // file has opted out of the automatic global scope (see
            // the class doc comment), so every join in it should
            // state its own company filter explicitly rather than
            // lean on an assumption holding true elsewhere.
            ->join('vendors', function ($join) use ($companyId) {
                $join->on('vendors.id', '=', 'purchases.vendor_id')
                    ->where('vendors.company_id', $companyId);
            })
            ->groupBy('vendors.id', 'vendors.name')
            ->select([
                'vendors.id',
                'vendors.name',
                DB::raw('COALESCE(SUM(purchases.volume), 0) AS volume'),
                DB::raw('COALESCE(SUM(purchases.amount), 0) AS value'),
                DB::raw('COUNT(*) AS transactions'),
            ])
            ->orderByDesc('value')
            ->limit(self::TOP_LIMIT)
            ->get();

        return $spend->map(fn ($row) => [
            'id'           => (int) $row->id,
            'name'         => $row->name,
            'volume'       => round((float) $row->volume, 2),
            'value'        => round((float) $row->value, 2),
            'transactions' => (int) $row->transactions,
        ])->all();
    }
}
