<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\EquipmentPurchase;
use App\Models\Expense;
use App\Models\InventoryPurchase;
use App\Models\Payment;
use App\Models\Sale;
use App\Services\Reports\ReportDataService;
use App\Support\FinancialRules;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — DashboardController (company side)
//
//  The landing page after login. It answers, in this order:
//    1. Where do I stand this period (revenue, COGS, real net
//       profit, and separately, actual cash movement)?
//    2. What needs chasing (open invoices and bills)?
//    3. What is actually selling, and who am I doing business with?
//
//  Net Profit here is the real, accrual figure — Revenue − Cost of
//  Goods Sold − Operating Expenses, read from ReportDataService::
//  profitAndLoss(), the exact same calculation the P&L report uses.
//  This used to be cash in minus cash out for the period, mislabeled
//  "Net Profit" — that figure is still shown, honestly relabeled
//  "Net Cash Flow", since it answers a real and different question
//  (did money actually move) that accrual profit doesn't.
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
//  accrualFigures() is the one exception — it delegates to
//  ReportDataService::profitAndLoss(), which reads through Eloquent
//  and so IS scoped by the global company scope as normal.
// ══════════════════════════════════════════════════════════════════
class DashboardController extends Controller
{
    /** How many rows each "top" list and the donut chart carry. */
    private const TOP_LIMIT = 6;

    public function __construct(private readonly ReportDataService $reports) {}

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
            ...$this->accrualFigures($from, $to),

            // ── What needs attention ─────────────────────────────
            'open_invoices_count' => $this->openInvoicesCount($companyId),
            'open_bills_count'    => $this->openBillsCount($companyId),

            // ── What is selling, and to whom ─────────────────────
            'sku_sales'     => $this->skuSales($companyId, $from, $to),
            'sales_by_channel' => $this->salesByChannel($companyId, $from, $to),
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
        $period = in_array($period, ['month', 'quarter', 'year'], true) ? $period : 'year';

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
            // Renamed from income_this_month / expenses_this_month:
            // those old names read like accrual revenue/expense
            // figures (and one of them — "income" — sat right next
            // to COGS on the dashboard as if they came from the same
            // report). They never did; this is cash that physically
            // moved in/out of the payments table. Naming it "cash
            // in/out" everywhere — prop, blade, translation key —
            // keeps that honest at a glance.
            'total_cash_in'  => round($in, 2),
            'total_cash_out' => round($out, 2),
            'net_cash_flow'  => round($in - $out, 2),
            'cash_balance'   => round((float) ($rows->cash_balance ?? 0), 2),
        ];
    }

    /**
     * The real, accrual figures for the period — Revenue, Cost of
     * Goods Sold, and Net Profit (Revenue − COGS − Operating
     * Expenses) — read straight from ReportDataService::profitAndLoss(),
     * the exact same calculation the P&L report uses, rather than a
     * second calculation kept in step by hand. See the class doc
     * comment for why this replaces what used to be called "Net
     * Profit" here (it was cash in minus cash out).
     */
    private function accrualFigures(string $from, string $to): array
    {
        $pl = $this->reports->profitAndLoss($from, $to);

        // All four numbers below come from this ONE profitAndLoss()
        // call — the exact same calculation the P&L report shows —
        // so the dashboard's top row can never disagree with itself
        // (previously "revenue" and "operating_expenses" weren't
        // pulled out here at all, and the card that stood in for
        // "revenue" actually read from the cash-basis Payments table
        // instead, via cashFigures() below — a different source
        // entirely, which is why it never tied to COGS/Net Profit
        // sitting right next to it).
        return [
            'revenue'             => $pl['revenue'],
            'cost_of_goods_sold'  => $pl['cost_of_goods_sold'],
            'operating_expenses'  => $pl['operating_expenses'],
            'net_profit'          => $pl['net_profit'],
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
     * matches PaymentController's worklist, using the same
     * FinancialRules::AMOUNT_TOLERANCE tolerance that keeps float
     * noise from reading as a debt.
     */
    private function unsettledCondition(string $table, string $model): string
    {
        return "COALESCE((
            SELECT SUM(p.amount) FROM payments p
            WHERE p.payable_type = ".DB::getPdo()->quote($model)."
              AND p.payable_id = {$table}.id
              AND p.company_id = ?
        ), 0) < {$table}.amount - ".FinancialRules::AMOUNT_TOLERANCE;
    }

    /**
     * Sales per stock item: how much moved, what it earned, and how
     * many separate sales it appeared in. Drives both the donut and
     * the three "top item" cards, so all four read from one query.
     *
     * Lines with no item_id are free-text/service lines with nothing
     * to attribute, so they're excluded rather than lumped together
     * under a misleading label. This is a REAL, INTENTIONAL reason
     * this donut's total can sit a little below the Sales figure on
     * the top row: any free-text line still counts as revenue, but
     * has no item to show it under here. That's a genuinely
     * different (and smaller) gap than what was wrong with the
     * Sales-by-Channel donut above — see that method's doc comment.
     *
     * `is_opening_balance` sales are excluded explicitly, though in
     * practice they never reach this query anyway — they're created
     * with no sale_lines at all (see OpeningBalanceService), so the
     * join to sale_lines already drops them. Stated here so that
     * stays true if opening-balance sales ever gain lines later.
     */
    private function skuSales(int $companyId, string $from, string $to): array
    {
        return DB::table('sale_lines')
            ->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
            ->join('items', 'items.id', '=', 'sale_lines.item_id')
            ->where('sales.company_id', $companyId)
            ->where('sales.is_opening_balance', false)
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
     * Sales grouped by channel (Direct, Delivery, Online, WhatsApp,
     * or anything the company added itself — see SalesChannel)
     * for the "Sales by Channel" donut on the dashboard.
     *
     * Two fixes here (previously this donut's total matched neither
     * the SKU donut nor the Sales/Revenue card above it):
     *
     *  1. Summed `sales.subtotal`, NOT `sales.amount`. `amount` is
     *     VAT-INCLUSIVE (amount = subtotal + vat_amount — see the
     *     sales table migration), while Revenue on the P&L / top
     *     row is the Sales Revenue ledger account, which is posted
     *     at `subtotal` only (VAT is posted separately to VAT
     *     Payable — see JournalService::postSaleInvoice()). Summing
     *     `amount` was quietly adding collected VAT into "sales".
     *
     *  2. Excludes `is_opening_balance` sales. Those rows exist only
     *     to record what a customer already owed you on day one —
     *     they're posted straight to Accounts Receivable, never to
     *     Sales Revenue (see postOpeningBalanceReceivable()), so
     *     they were never part of Revenue in the first place and
     *     shouldn't be counted here either.
     *
     * A LEFT JOIN, not an inner one: sales recorded before this
     * feature existed have no sales_channel_id at all (the column
     * is nullable — see the 2026_09_26_000002 migration), and those
     * should still show up rather than silently vanishing from the
     * chart. They're grouped under "Direct Sales" — SalesChannel's
     * own default — since that's what they would have been filed
     * under had the feature existed when they were made.
     */
    private function salesByChannel(int $companyId, string $from, string $to): array
    {
        return DB::table('sales')
            ->leftJoin('sales_channels', 'sales_channels.id', '=', 'sales.sales_channel_id')
            ->where('sales.company_id', $companyId)
            ->where('sales.is_opening_balance', false)
            ->whereBetween('sales.date', [$from, $to])
            ->groupBy('sales_channels.id', 'sales_channels.name', 'sales_channels.name_ar')
            ->select([
                'sales_channels.id',
                DB::raw("COALESCE(sales_channels.name, 'Direct Sales') AS name"),
                DB::raw("COALESCE(sales_channels.name_ar, 'بيع مباشر') AS name_ar"),
                DB::raw('COALESCE(SUM(sales.subtotal), 0) AS value'),
                DB::raw('COUNT(*) AS transactions'),
            ])
            ->orderByDesc('value')
            ->limit(self::TOP_LIMIT)
            ->get()
            ->map(fn ($row) => [
                'id'           => $row->id !== null ? (int) $row->id : null,
                'name'         => $row->name,
                'name_ar'      => $row->name_ar,
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
