<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Company;
use App\Models\Expense;
use App\Models\InventoryPurchase;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\ProductionOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Services\JournalService;
use App\Services\Reports\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  The two findings the audit left open, pinned.
//
//    H-1  The Profit & Loss disagreed with itself. The headline
//         summed every outgoing payment; the breakdown underneath
//         read the `expenses` table plus custody settlements. They
//         differed by whatever had been paid for stock and
//         equipment, and nobody comparing the two halves of one
//         report could reconcile them.
//
//    H-2  Every "other cost" on a production run credited Cash
//         unconditionally. A workshop recording a cost it had not
//         actually paid saw Cash driven down — possibly negative —
//         while the money was still in the bank.
//
//  H-1 was closed by building both halves from one set of rows, with
//  capital spend excluded from both. H-2 was closed by parking the
//  "other costs" repeater altogether: this app is for workshops, so
//  a run is materials + labor, and any other cost is a normal
//  Expense entered where it can be marked unpaid.
//
//  Both changed figures the owner had already read, which is why
//  each one is asserted here with a worked number rather than a
//  "greater than zero".
// ══════════════════════════════════════════════════════════════════
class ProductionCostAndProfitTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Vendor $supplier;

    private Category $rentCategory;

    private Item $flour;

    private Item $bread;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(['business_types' => ['trading', 'production']]);
        $this->admin   = User::factory()->companyAdmin($this->company)->create();

        app(JournalService::class)->seedChartOfAccounts($this->company);
        Category::seedDefaults($this->company->id);

        $this->actingAs($this->admin);

        $this->supplier = Vendor::create(['company_id' => $this->company->id, 'name' => 'Mill']);

        $this->rentCategory = Category::query()
            ->where('company_id', $this->company->id)->where('kind', 'expense')->firstOrFail();

        $this->flour = Item::create(['company_id' => $this->company->id, 'name' => 'Flour',
            'type' => 'raw_material', 'qty_per_uom' => 1, 'base_unit_name' => 'kg']);
        $this->bread = Item::create(['company_id' => $this->company->id, 'name' => 'Bread',
            'type' => 'product', 'qty_per_uom' => 1, 'base_unit_name' => 'loaf']);
    }

    // ── Helpers ──────────────────────────────────────────────────

    /**
     * Cache::flush() between posts because the duplicate-submission
     * guard fingerprints a few seconds of identical bodies, and a
     * scripted scenario moves faster than a person does.
     */
    private function submit(string $url, array $data): void
    {
        $this->post($url, $data)->assertSessionHasNoErrors();
        Cache::flush();
    }

    /** 100kg of flour at 5, bought on credit. */
    private function buyFlour(): InventoryPurchase
    {
        $this->submit('/app/inventory-purchases', [
            'vendor_id' => $this->supplier->id,
            'date'      => '2026-03-01',
            'lines'     => [['item_id' => $this->flour->id, 'qty' => 100, 'qty_per_uom' => 1, 'unit_price' => 5]],
            'vat_rate'  => 0, 'mode' => 'later',
        ]);

        return InventoryPurchase::query()->latest('id')->firstOrFail();
    }

    /** @param  array<string, mixed>  $extra */
    private function produce(array $extra = []): ProductionOrder
    {
        $this->submit('/app/production-orders', array_merge([
            'item_id'      => $this->bread->id,
            'date'         => '2026-03-05',
            'qty_produced' => 10,
            'materials'    => [['item_id' => $this->flour->id, 'qty' => 20]],
            'labor_cost'   => 100,
        ], $extra));

        return ProductionOrder::query()->latest('id')->firstOrFail();
    }

    private function ledger(string $code): float
    {
        $account = Account::query()
            ->where('company_id', $this->company->id)->where('code', $code)->first();

        if (! $account) {
            return 0.0;
        }

        return round(
            JournalLine::query()->where('account_id', $account->id)->get()
                ->sum(fn (JournalLine $line) => (float) $line->debit - (float) $line->credit),
            2
        );
    }

    private function assertEveryEntryBalances(): void
    {
        JournalEntry::query()->get()->each(
            fn (JournalEntry $entry) => $this->assertTrue($entry->isBalanced(), "Entry #{$entry->id} does not balance")
        );
    }

    private function pl(string $from = '2026-03-01', string $to = '2026-03-31'): array
    {
        return app(ReportDataService::class)->profitAndLoss($from, $to);
    }

    // ══════════════════════════════════════════════════════════
    //  H-2 · A production run must not move cash
    // ══════════════════════════════════════════════════════════

    public function test_h2_a_production_run_touches_no_cash_account(): void
    {
        $this->buyFlour();

        $cashBefore = $this->ledger(Account::CASH);
        $bankBefore = $this->ledger(Account::BANK);

        $order = $this->produce();

        $this->assertEquals($cashBefore, $this->ledger(Account::CASH), 'A run is not a cash payment');
        $this->assertEquals($bankBefore, $this->ledger(Account::BANK));

        // Said again against the entry itself, so this still fails if
        // some later change routes the same money through a different
        // account that happens to net to zero.
        $entry = JournalEntry::query()
            ->where('source_type', ProductionOrder::class)
            ->where('source_id', $order->id)
            ->firstOrFail();

        $cashAccountIds = Account::query()
            ->where('company_id', $this->company->id)
            ->whereIn('code', [Account::CASH, Account::BANK])
            ->pluck('id');

        $this->assertSame(
            0,
            $entry->lines->whereIn('account_id', $cashAccountIds)->count(),
            'The production entry still has a cash line'
        );
    }

    public function test_h2_a_run_costs_materials_plus_labor_and_nothing_else(): void
    {
        $this->buyFlour();

        $order = $this->produce();

        $this->assertEquals(100.00, (float) $order->material_cost, '20kg of flour at 5');
        $this->assertEquals(100.00, (float) $order->labor_cost);
        $this->assertEquals(0.00, (float) $order->other_cost_total, 'The repeater is parked');
        $this->assertEquals(200.00, (float) $order->total_cost);
        $this->assertEquals(20.0000, (float) $order->unit_cost, '200 over 10 loaves');
    }

    /**
     * The form no longer offers the field, but the form is not the
     * guard — a hand-rolled request must not get the old behaviour
     * back. Nothing here should be accepted into the run.
     */
    public function test_h2_other_costs_posted_directly_are_ignored(): void
    {
        $this->buyFlour();

        $cashBefore = $this->ledger(Account::CASH);

        $order = $this->produce([
            'other_costs' => [
                ['category_id' => $this->rentCategory->id, 'amount' => 750],
            ],
        ]);

        $this->assertEquals(0.00, (float) $order->other_cost_total);
        $this->assertEquals(200.00, (float) $order->total_cost, 'Materials 100 + labor 100, and not a piastre more');
        $this->assertSame(0, $order->otherCostLines()->count());
        $this->assertEquals($cashBefore, $this->ledger(Account::CASH));
        $this->assertEveryEntryBalances();
    }

    /**
     * A run entered before the repeater was parked still carries its
     * other costs. Correcting it re-costs from scratch, which zeroes
     * them — the intended behaviour, and the case most likely to
     * throw, since the ledger entry only balances if the stored
     * total drops with them.
     */
    public function test_h2_correcting_a_legacy_run_zeroes_its_other_costs_and_still_balances(): void
    {
        $this->buyFlour();
        $order = $this->produce();

        // Forge the shape the old code produced: 50 of "other costs"
        // in the total, a line to match, and the Cash credit that
        // used to accompany them.
        DB::table('production_orders')->where('id', $order->id)->update([
            'other_cost_total' => 50,
            'total_cost'       => 250,
            'unit_cost'        => 25,
        ]);
        $order->otherCostLines()->create([
            'category_id' => $this->rentCategory->id,
            'description' => $this->rentCategory->name,
            'amount'      => 50,
        ]);

        $this->put("/app/production-orders/{$order->id}", [
            'item_id'      => $order->item_id,
            'date'         => '2026-03-05',
            'qty_produced' => 10,
            'materials'    => [['item_id' => $this->flour->id, 'qty' => 20]],
            'labor_cost'   => 100,
        ])->assertSessionHasNoErrors();

        $order->refresh();

        $this->assertEquals(0.00, (float) $order->other_cost_total);
        $this->assertEquals(200.00, (float) $order->total_cost);
        $this->assertSame(0, $order->otherCostLines()->count(), 'The lines have to go with the total');
        $this->assertEveryEntryBalances();
    }

    /**
     * Parking the repeater must not have disturbed the one cost a run
     * DOES carry. Labor is an estimate parked in a clearing account
     * and settled when the real payroll is entered — the mechanism
     * the owner is now meant to use for everything else too.
     */
    public function test_h2_production_labor_still_clears_through_the_expenses_screen(): void
    {
        $this->buyFlour();
        $this->produce();

        $this->assertEquals(
            -100.00,
            $this->ledger(Account::PRODUCTION_LABOR_ACCRUED),
            'The estimate is parked as a liability, credit balance'
        );

        // The real wages, entered the normal way and ticked.
        $this->submit('/app/expenses', [
            'vendor_id'           => $this->supplier->id,
            'category_id'         => $this->rentCategory->id,
            'date'                => '2026-03-28',
            'amount'              => 100,
            'mode'                => 'later',
            'is_production_labor' => true,
        ]);

        $this->assertEquals(
            0.00,
            $this->ledger(Account::PRODUCTION_LABOR_ACCRUED),
            'The estimate should be cleared by the real payroll'
        );
        $this->assertEveryEntryBalances();
    }

    // ══════════════════════════════════════════════════════════
    //  H-1 · The Profit & Loss has to agree with itself
    // ══════════════════════════════════════════════════════════

    /**
     * The identity that was broken. Asserted over a scenario that
     * deliberately contains every shape of cash-out the app has, so
     * it cannot pass by avoiding the awkward one.
     */
    public function test_h1_the_headline_is_always_the_sum_of_the_breakdown(): void
    {
        $purchase = $this->buyFlour();

        // Stock paid for — the payment that used to split the report.
        $this->submit('/app/payments/pay', [
            'payable_type' => 'inventory_purchase',
            'payable_id'   => $purchase->id,
            'date'         => '2026-03-15',
            'amount'       => 300,
            'method'       => 'bank',
        ]);

        // A bill paid on the spot.
        $this->submit('/app/expenses', [
            'vendor_id' => $this->supplier->id, 'category_id' => $this->rentCategory->id,
            'date' => '2026-03-10', 'amount' => 400, 'mode' => 'now', 'method' => 'cash',
        ]);

        // A payment with no bill behind it.
        $this->submit('/app/payments/pay', [
            'date' => '2026-03-20', 'amount' => 75, 'method' => 'cash',
            'category_id' => $this->rentCategory->id,
        ]);

        $pl = $this->pl();

        $this->assertEquals(475.00, $pl['operating_expenses'], 'rent 400 + generic 75 — the 300 of stock is not an expense');
        $this->assertEquals(
            $pl['operating_expenses'],
            round(collect($pl['expenses_by_category'])->sum('total'), 2),
            'One report cannot hold two different totals'
        );
    }

    /**
     * The symptom the owner actually saw: profit dropping every time
     * they restocked, even though nothing had been consumed.
     */
    public function test_h1_restocking_does_not_read_as_a_loss(): void
    {
        $purchase = $this->buyFlour();

        $before = $this->pl();

        $this->submit('/app/payments/pay', [
            'payable_type' => 'inventory_purchase',
            'payable_id'   => $purchase->id,
            'date'         => '2026-03-15',
            'amount'       => 500,
            'method'       => 'bank',
        ]);

        $this->assertEquals(
            $before['net_profit'],
            $this->pl()['net_profit'],
            'Paying for stock swaps cash for goods; it does not make the business poorer'
        );
    }

    /**
     * A part-settled bill is an expense in FULL, and the two halves
     * of the report still agree about it.
     *
     * Read the history here, because this test now asserts the
     * opposite of what it once did. The original defect was that the
     * headline counted payments while the breakdown read
     * `expenses.amount`, so a bill only part-paid put the two halves
     * out by the rest of it. The first fix made both halves cash
     * basis. The report has since moved to accrual — it is read
     * straight off the general ledger — so both halves now count the
     * whole bill on its bill date, and the 700 still owed is a
     * payable rather than a smaller expense.
     *
     * The figure changed twice; the property being guarded never did:
     * the headline and the breakdown must be the same number.
     */
    public function test_h1_a_part_paid_bill_is_an_expense_in_full(): void
    {
        $this->submit('/app/expenses', [
            'vendor_id' => $this->supplier->id, 'category_id' => $this->rentCategory->id,
            'date' => '2026-03-02', 'amount' => 1000, 'mode' => 'partial',
            'method' => 'cash', 'amount_now' => 300,
        ]);

        $pl = $this->pl();

        $this->assertEquals(1000.00, $pl['operating_expenses'], 'Accrual: the whole bill is this month\'s cost');
        $this->assertEquals(
            1000.00,
            round(collect($pl['expenses_by_category'])->firstWhere('category', $this->rentCategory->name)?->total, 2),
            'The breakdown has to say the same thing the headline does'
        );

        // Paying only part of it changes what is OWED, not what it cost.
        $this->assertEquals(700.00, Expense::query()->latest('id')->firstOrFail()->balance());
    }

    /**
     * A generic payment tagged with nothing has to land somewhere,
     * and it has to be the same somewhere the ledger uses — else the
     * headline counts it and the breakdown drops it on the floor.
     */
    public function test_h1_an_uncategorised_payment_falls_under_miscellaneous(): void
    {
        $this->submit('/app/payments/pay', [
            'date' => '2026-03-20', 'amount' => 60, 'method' => 'cash', 'note' => 'Tea and sugar',
        ]);

        $pl = $this->pl();

        $this->assertEquals(60.00, $pl['operating_expenses']);

        // The breakdown is drawn from ledger ACCOUNTS now, not from
        // category names, so an uncategorised payment appears under
        // whatever Account::MISC_EXPENSE is called rather than under
        // a literal "Miscellaneous". Looked up rather than hardcoded
        // so renaming the account cannot silently orphan this row.
        $miscAccount = Account::query()
            ->where('company_id', $this->company->id)
            ->where('code', Account::MISC_EXPENSE)
            ->firstOrFail();

        $this->assertEquals(
            60.00,
            round(collect($pl['expenses_by_category'])->firstWhere('category', $miscAccount->name)?->total, 2),
            'An uncategorised payment fell out of the breakdown entirely'
        );
    }

    /**
     * A production run costs the company nothing in cash, so it must
     * not reach the P&L at all — the two fixes meeting in one place.
     */
    public function test_a_production_run_does_not_appear_in_the_profit_and_loss(): void
    {
        $this->buyFlour();

        $before = $this->pl();
        $this->produce();

        $this->assertEquals($before['operating_expenses'], $this->pl()['operating_expenses']);
        $this->assertEquals($before['net_profit'], $this->pl()['net_profit']);
        $this->assertEquals($before['cost_of_goods_sold'], $this->pl()['cost_of_goods_sold'],
            'Making stock is not selling it — COGS waits for the sale');
    }
}
