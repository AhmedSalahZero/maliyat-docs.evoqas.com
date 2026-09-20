<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Company;
use App\Models\Custody;
use App\Models\Customer;
use App\Models\InventoryPurchase;
use App\Models\Item;
use App\Models\JournalLine;
use App\Models\Sale;
use App\Models\User;
use App\Models\Vendor;
use App\Services\JournalService;
use App\Services\Reports\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  The reports have to agree with each other.
//
//  Every other test in this suite checks one feature against figures
//  worked out by hand. That catches a broken feature, but it cannot
//  catch a feature that is individually correct and collectively
//  wrong — two reports drifting apart, each self-consistent, each
//  answering the same question differently.
//
//  This file runs ONE realistic month of trading through the app and
//  then asks whether the ledger, the statements, the cash flow and
//  the stock report still describe the same business. These are
//  accounting identities: if any of them fails, a number somewhere
//  is wrong no matter how confidently it is displayed.
//
//    Accounts Receivable      == what every customer statement says
//    Accounts Payable         == what every supplier statement says
//    Cash + Bank              == cash flow's net movement
//    Inventory (asset)        == the stock report's valuation
//    Custody Advances         == floats handed out but not settled
//    debits                   == credits
//
//  The scenario deliberately mixes the paths that have caused
//  trouble: a cash sale and a credit sale, a partial payment, a bill
//  settled in instalments, a petty-cash float, a capital purchase,
//  and a correction to a record from an earlier month.
// ══════════════════════════════════════════════════════════════════
class ReportConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Customer $acme;

    private Customer $beta;

    private Vendor $supplier;

    private Vendor $employee;

    private Item $widget;

    private Category $expenseCategory;

    private Category $equipmentCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->admin   = User::factory()->companyAdmin($this->company)->create();

        app(JournalService::class)->seedChartOfAccounts($this->company);
        Category::seedDefaults($this->company->id);

        $this->actingAs($this->admin);

        $this->acme     = Customer::create(['company_id' => $this->company->id, 'name' => 'Acme']);
        $this->beta     = Customer::create(['company_id' => $this->company->id, 'name' => 'Beta']);
        $this->supplier = Vendor::create(['company_id' => $this->company->id, 'name' => 'Supplier']);
        $this->employee = Vendor::create(['company_id' => $this->company->id, 'name' => 'Employee']);

        $this->widget = Item::create([
            'company_id'     => $this->company->id,
            'name'           => 'Widget',
            'qty_per_uom'    => 1,
            'base_unit_name' => 'pc',
        ]);

        $this->expenseCategory = Category::query()->where('company_id', $this->company->id)
            ->where('kind', 'expense')->firstOrFail();
        $this->equipmentCategory = Category::query()->where('company_id', $this->company->id)
            ->where('kind', 'equipment')->firstOrFail();

        $this->tradeForAMonth();
    }

    /**
     * Cache::flush() between steps because the duplicate-submission
     * guard fingerprints a few seconds of identical bodies, and a
     * scripted month moves faster than a person does.
     */
    private function submit(string $url, array $data): void
    {
        $this->post($url, $data)->assertSessionHasNoErrors();
        Cache::flush();
    }

    private function tradeForAMonth(): void
    {
        // Bought 100 widgets at 10 — 1,000 owed to the supplier.
        $this->submit('/app/inventory-purchases', [
            'vendor_id' => $this->supplier->id,
            'date'      => '2026-03-01',
            'lines'     => [['item_id' => $this->widget->id, 'qty' => 100, 'qty_per_uom' => 1, 'unit_price' => 10]],
            'vat_rate'  => 0,
            'mode'      => 'later',
        ]);

        // Sold 30 at 25 to Acme — 750, paid cash on the spot.
        $this->submit('/app/sales', [
            'customer_id' => $this->acme->id,
            'date'        => '2026-03-05',
            'lines'       => [['item_id' => $this->widget->id, 'qty' => 30, 'unit_price' => 25]],
            'vat_rate'    => 0,
            'mode'        => 'now',
            'method'      => 'cash',
        ]);

        // Sold 20 at 25 to Beta — 500 on credit.
        $this->submit('/app/sales', [
            'customer_id' => $this->beta->id,
            'date'        => '2026-03-08',
            'lines'       => [['item_id' => $this->widget->id, 'qty' => 20, 'unit_price' => 25]],
            'vat_rate'    => 0,
            'mode'        => 'later',
        ]);

        // Beta pays 200 of the 500.
        $this->submit('/app/payments/receive', [
            'sale_id' => Sale::query()->where('customer_id', $this->beta->id)->firstOrFail()->id,
            'date'    => '2026-03-12',
            'amount'  => 200,
            'method'  => 'bank',
        ]);

        // Rent 400, paid cash.
        $this->submit('/app/expenses', [
            'vendor_id'   => $this->supplier->id,
            'category_id' => $this->expenseCategory->id,
            'date'        => '2026-03-10',
            'amount'      => 400,
            'mode'        => 'now',
            'method'      => 'cash',
        ]);

        // 600 off the stock bill.
        $this->submit('/app/payments/pay', [
            'payable_type' => 'inventory_purchase',
            'payable_id'   => InventoryPurchase::query()->firstOrFail()->id,
            'date'         => '2026-03-15',
            'amount'       => 600,
            'method'       => 'bank',
        ]);

        // A 300 float to an employee, settled at 250 five days later.
        $this->submit('/app/custodies', [
            'holder_id' => $this->employee->id,
            'amount'    => 300,
            'method'    => 'cash',
            'given_at'  => '2026-03-20',
        ]);

        $this->patch('/app/custodies/'.Custody::query()->firstOrFail()->id.'/settle', [
            'settlement_date' => '2026-03-25',
            'lines'           => [['category_id' => $this->expenseCategory->id, 'amount' => 250]],
        ])->assertSessionHasNoErrors();
        Cache::flush();

        // A van on credit — a capital purchase, not an expense.
        $this->submit('/app/equipment-purchases', [
            'vendor_id'   => $this->supplier->id,
            'category_id' => $this->equipmentCategory->id,
            'name'        => 'Van',
            'date'        => '2026-03-18',
            'qty'         => 1,
            'unit_price'  => 5000,
            'mode'        => 'later',
        ]);
    }

    private function reports(): ReportDataService
    {
        return app(ReportDataService::class);
    }

    /** Net movement on one ledger account: debits minus credits. */
    private function ledger(string $code): float
    {
        $account = Account::query()
            ->where('company_id', $this->company->id)
            ->where('code', $code)
            ->first();

        if (! $account) {
            return 0.0;
        }

        return round(
            JournalLine::query()->where('account_id', $account->id)->get()
                ->sum(fn (JournalLine $line) => (float) $line->debit - (float) $line->credit),
            2
        );
    }

    // ── The books balance ────────────────────────────────────────

    public function test_the_trial_balance_balances(): void
    {
        $trialBalance = $this->reports()->trialBalance('2026-03-01', '2026-03-31');

        $this->assertTrue($trialBalance['is_balanced']);

        // Closing = opening + the period's movement, for every
        // account. Nothing existed before March, so opening is flat
        // and closing is the whole month.
        $this->assertEquals(0.00, $trialBalance['total_opening_debit']);
        $this->assertEquals(0.00, $trialBalance['total_opening_credit']);
        $this->assertEquals(7050.00, $trialBalance['total_closing_debit']);
        $this->assertEquals(7050.00, $trialBalance['total_closing_credit']);
    }

    // ── Each identity, with the figure worked out by hand ────────

    /**
     * Acme paid in full; Beta owes 500 less the 200 they paid.
     */
    public function test_receivables_match_the_customer_statements(): void
    {
        $fromStatements = round(
            $this->reports()->customerStatement($this->acme->fresh())['balance']
            + $this->reports()->customerStatement($this->beta->fresh())['balance'],
            2
        );

        $this->assertEquals(300.00, $fromStatements, 'Beta still owes 300');
        $this->assertEquals($this->ledger(Account::ACCOUNTS_RECEIVABLE), $fromStatements);
    }

    /**
     * 1,000 stock less 600 paid, plus 400 rent already settled, plus
     * the 5,000 van still owed.
     */
    public function test_payables_match_the_supplier_statement(): void
    {
        $fromStatement = $this->reports()->supplierStatement($this->supplier->fresh())['balance'];

        $this->assertEquals(5400.00, $fromStatement);
        $this->assertEquals(-$this->ledger(Account::ACCOUNTS_PAYABLE), $fromStatement);
    }

    /**
     * In: 750 cash from Acme + 200 from Beta + 50 float returned.
     * Out: 400 rent + 600 to the supplier + 300 float.
     */
    public function test_cash_on_the_books_matches_the_cash_flow_report(): void
    {
        $cashFlow = $this->reports()->cashFlow('2026-03-01', '2026-03-31');

        $this->assertEquals(1000.00, $cashFlow['cash_in']);
        $this->assertEquals(1300.00, $cashFlow['cash_out']);

        $this->assertEquals(
            round($cashFlow['cash_in'] - $cashFlow['cash_out'], 2),
            round($this->ledger(Account::CASH) + $this->ledger(Account::BANK), 2),
            'The ledger and the cash flow report disagree about how much money moved'
        );
    }

    /**
     * Bought 100 at 10, sold 50 — 50 left at a weighted average of 10.
     */
    public function test_the_stock_report_matches_the_inventory_account(): void
    {
        $inventory = $this->reports()->inventoryStatement();
        $row       = collect($inventory['items'])->firstWhere('id', $this->widget->id);

        $this->assertEquals(50.0, $row['current_stock']);
        $this->assertEquals(10.0, $row['avg_purchase_cost']);
        $this->assertEquals(500.00, $inventory['total_stock_value']);

        $this->assertEquals(
            $this->ledger(Account::INVENTORY_ASSET),
            $inventory['total_stock_value'],
            'The stock report and the ledger disagree about what the stock is worth'
        );
    }

    /**
     * Every float has been settled, so the company is holding no
     * advances — the account must be flat, not merely small.
     */
    public function test_a_settled_float_leaves_no_advance_outstanding(): void
    {
        $this->assertEquals(0.00, $this->ledger(Account::CUSTODY_ADVANCES));
    }

    public function test_cost_of_goods_sold_is_what_the_sold_stock_cost(): void
    {
        $this->assertEquals(500.00, $this->ledger(Account::COST_OF_GOODS_SOLD), '50 widgets at 10');
    }

    public function test_revenue_is_the_two_invoices_not_the_cash(): void
    {
        $this->assertEquals(-1250.00, $this->ledger(Account::SALES_REVENUE), '750 + 500, credit balance');
    }

    // ── The P&L ──────────────────────────────────────────────────

    /**
     * Revenue is what was INVOICED — 750 to Acme plus 500 to Beta —
     * not what was collected. Beta has paid only 200 of their 500 and
     * that makes no difference here; this report is accrual, and the
     * uncollected 300 is a receivable, not a reduction in revenue.
     *
     * The 50 of float coming back is still not revenue. That is what
     * this test was originally written to pin, back when the report
     * was cash basis and the returned change was landing in income.
     * The basis changed; the thing being guarded did not.
     */
    public function test_revenue_is_invoiced_sales_and_never_returned_petty_cash(): void
    {
        $profitAndLoss = $this->reports()->profitAndLoss('2026-03-01', '2026-03-31');

        $this->assertEquals(1250.00, $profitAndLoss['revenue'], '750 to Acme + 500 to Beta');

        // Said against the ledger too, so this cannot drift from the
        // account the Trial Balance reads. Revenue is credit-normal.
        $this->assertEquals(
            -$this->ledger(Account::SALES_REVENUE),
            $profitAndLoss['revenue'],
            'The P&L and the general ledger disagree about revenue'
        );
    }

    /**
     * The headline and the breakdown are the same figure.
     *
     * This test used to pin the OPPOSITE — a measured 600 gap between
     * the two halves, left visible on purpose because closing it
     * moves every profit figure the owner has already read. That
     * decision has now been taken: capital spend leaves the P&L, and
     * both halves are built from one set of rows, so they cannot
     * drift apart again whatever is added to the app later.
     *
     * Buying stock is not an expense — it is swapping cash for goods
     * you still own, which is why the ledger books it to Inventory
     * (see the stock test above). The same is true of the van. What
     * the stock cost reaches the books through Cost of Goods Sold
     * when it is sold, and the van through depreciation.
     */
    public function test_the_headline_is_exactly_what_the_breakdown_adds_up_to(): void
    {
        $profitAndLoss = $this->reports()->profitAndLoss('2026-03-01', '2026-03-31');

        $headline  = $profitAndLoss['operating_expenses'];
        $breakdown = round(collect($profitAndLoss['expenses_by_category'])->sum('total'), 2);

        $this->assertEquals(650.00, $headline, 'rent 400 + float spend 250 — no stock, no van');
        $this->assertEquals($headline, $breakdown, 'One report cannot hold two different totals');

        // And the statement itself adds up, top to bottom.
        $this->assertEquals(1250.00, $profitAndLoss['revenue']);
        $this->assertEquals(500.00, $profitAndLoss['cost_of_goods_sold'], '50 widgets at 10');
        $this->assertEquals(750.00, $profitAndLoss['gross_profit'], '1,250 - 500');
        $this->assertEquals(100.00, $profitAndLoss['net_profit'], '750 - 650');
    }

    /**
     * The 600 paid off the stock bill and the 5,000 van are still
     * real money and a real asset — they have moved reports, not
     * vanished. Asserted here so "excluded from the P&L" can never
     * quietly become "excluded from everywhere".
     */
    public function test_capital_spend_leaves_the_p_and_l_but_stays_on_the_other_reports(): void
    {
        $breakdown = collect($this->reports()->profitAndLoss('2026-03-01', '2026-03-31')['expenses_by_category']);

        $this->assertNull(
            $breakdown->firstWhere('category', $this->equipmentCategory->name),
            'A capital purchase is not a running cost'
        );

        // The stock bill payment still shows as cash going out.
        $cashFlow = $this->reports()->cashFlow('2026-03-01', '2026-03-31');

        $this->assertEquals(
            1300.00,
            round((float) $cashFlow['cash_out'], 2),
            'rent 400 + stock 600 + float handed out 300'
        );

        // And the van is still an asset on the books.
        $this->assertEquals(5000.00, $this->ledger(Account::EQUIPMENT_ASSET));
    }

    // ── Corrections must not disturb any of the above ────────────

    /**
     * The reversal-date fix, checked the only way that really counts:
     * correct a record and confirm every identity still holds.
     */
    public function test_correcting_a_sale_leaves_every_report_agreeing(): void
    {
        $sale = Sale::query()->where('customer_id', $this->beta->id)->firstOrFail();

        // Beta's invoice was really 20 at 30, not 20 at 25.
        $this->put("/app/sales/{$sale->id}", [
            'customer_id' => $this->beta->id,
            'date'        => '2026-03-08',
            'lines'       => [['item_id' => $this->widget->id, 'qty' => 20, 'unit_price' => 30]],
            'vat_rate'    => 0,
        ])->assertSessionHasNoErrors();

        $trialBalance = $this->reports()->trialBalance('2026-03-01', '2026-03-31');
        $this->assertTrue($trialBalance['is_balanced'], 'The books stopped balancing after a correction');

        // 500 became 600, so Beta owes 100 more.
        $this->assertEquals(400.00, $this->ledger(Account::ACCOUNTS_RECEIVABLE));
        $this->assertEquals(
            $this->ledger(Account::ACCOUNTS_RECEIVABLE),
            round($this->reports()->customerStatement($this->acme->fresh())['balance']
                + $this->reports()->customerStatement($this->beta->fresh())['balance'], 2)
        );

        // Revenue follows the correction and does not double up.
        $this->assertEquals(-1350.00, $this->ledger(Account::SALES_REVENUE), '750 + 600');

        // And the correction stayed inside March rather than leaking
        // into the month it was made in.
        $march = $this->reports()->trialBalance('2026-03-01', '2026-03-31');
        $april = $this->reports()->trialBalance('2026-03-01', '2026-04-30');
        $this->assertEquals(
            $march['total_closing_debit'],
            $april['total_closing_debit'],
            'Something landed outside March'
        );
    }

    public function test_deleting_a_sale_leaves_every_report_agreeing(): void
    {
        $sale = Sale::query()->where('customer_id', $this->beta->id)->firstOrFail();

        $this->delete("/app/sales/{$sale->id}")->assertSessionHasNoErrors();

        $this->assertTrue($this->reports()->trialBalance('2026-03-01', '2026-03-31')['is_balanced']);

        // Beta's debt is gone, Acme's nil balance remains.
        $this->assertEquals(0.00, $this->ledger(Account::ACCOUNTS_RECEIVABLE));

        // The 20 widgets go back on the shelf.
        $inventory = $this->reports()->inventoryStatement();
        $this->assertEquals(70.0, collect($inventory['items'])->firstWhere('id', $this->widget->id)['current_stock']);
        $this->assertEquals(
            $this->ledger(Account::INVENTORY_ASSET),
            $inventory['total_stock_value'],
            'Deleting a sale left the stock report and the ledger disagreeing'
        );
    }

    /**
     * A date range must not change what the books say — only which
     * slice of them is shown.
     */
    public function test_a_narrowed_statement_still_agrees_with_the_ledger(): void
    {
        $whole  = $this->reports()->customerStatement($this->beta->fresh());
        $window = $this->reports()->customerStatement($this->beta->fresh(), '2026-03-10', '2026-03-31');

        $this->assertEquals($whole['balance'], $window['balance'], 'The closing balance changed with the range');
        $this->assertEquals(500.00, $window['opening_balance'], 'Beta owed 500 coming into 10 March');
        $this->assertEquals(300.00, $window['balance']);
    }
}
