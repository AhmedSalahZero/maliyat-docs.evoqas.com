<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Company;
use App\Models\Custody;
use App\Models\Customer;
use App\Models\DeletionLog;
use App\Models\EquipmentPurchase;
use App\Models\Expense;
use App\Models\InventoryPurchase;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use App\Models\Vendor;
use App\Services\DepreciationService;
use App\Services\JournalService;
use App\Services\RecurringExpenseService;
use App\Support\FinancialRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  The second round of audit fixes (27 Sep 2026):
//    3.1 recurring expenses reach the ledger
//    3.2 a custody is settled once
//    3.3 opening stock/equipment is not an unpaid bill
//    4.1 opening-balance reset never deletes real payments
//    4.2 opening equipment keeps the depreciation it already had
//    4.3 production labour accrued (2200) clears across months
//    4.6 customer overpayments are credit, never revenue
// ══════════════════════════════════════════════════════════════════
class AuditRoundTwoFixesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Vendor $vendor;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(['business_types' => ['service', 'trading', 'production']]);
        $this->admin   = User::factory()->companyAdmin($this->company)->create();

        app(JournalService::class)->seedChartOfAccounts($this->company);
        Category::seedDefaults($this->company->id);

        $this->actingAs($this->admin);

        $this->vendor   = Vendor::create(['company_id' => $this->company->id, 'name' => 'Landlord Co']);
        $this->customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Acme']);
    }

    // ── Helpers ──────────────────────────────────────────────────

    /** Net debit balance of one account (credit-normal accounts read negative). */
    private function ledger(string $code): float
    {
        $account = Account::query()->where('company_id', $this->company->id)->where('code', $code)->first();

        if (! $account) {
            return 0.0;
        }

        return round(JournalLine::query()->where('account_id', $account->id)->get()
            ->sum(fn (JournalLine $l) => (float) $l->debit - (float) $l->credit), 2);
    }

    private function expenseCategory(): Category
    {
        return Category::query()->where('company_id', $this->company->id)->where('kind', 'expense')->firstOrFail();
    }

    private function entriesFor($model): int
    {
        return JournalEntry::query()->where('source_type', $model::class)->where('source_id', $model->id)->count();
    }

    private function today(): string
    {
        return FinancialRules::latestAllowedDate();
    }

    private function openInvoice(float $amount, string $on = '2026-09-01'): Sale
    {
        $this->post('/app/sales', [
            'customer_id' => $this->customer->id,
            'date'        => $on,
            'lines'       => [['item_id' => null, 'qty' => 1, 'unit_price' => $amount]],
            'vat_rate'    => 0,
            'mode'        => 'later',
        ])->assertSessionHasNoErrors();
        Cache::flush();

        return Sale::query()->latest('id')->firstOrFail();
    }

    private function assertEveryEntryBalances(): void
    {
        JournalEntry::query()->get()->each(
            fn (JournalEntry $e) => $this->assertTrue($e->isBalanced(), "Entry #{$e->id} does not balance")
        );
    }

    // ══ 3.1 Recurring expenses ═══════════════════════════════════

    public function test_3_1_a_new_series_posts_what_is_due_now_and_nothing_in_the_future(): void
    {
        $this->post('/app/expenses/recurring', [
            'vendor_id'   => $this->vendor->id,
            'category_id' => $this->expenseCategory()->id,
            'date'        => $this->today(),
            'amount'      => 5000,
            'frequency'   => 'monthly',
            'count'       => 3,
            'mode'        => 'now',
            'method'      => 'cash',
        ])->assertSessionHasNoErrors();

        $occurrences = Expense::query()->orderBy('recurring_index')->get();
        $this->assertCount(3, $occurrences);

        $this->assertSame(1, $this->entriesFor($occurrences[0]), 'This month\'s rent is billed at once');
        $this->assertSame(0, $this->entriesFor($occurrences[1]), 'Next month\'s is not an expense yet');
        $this->assertSame(0, $this->entriesFor($occurrences[2]));

        $this->assertEquals(-5000.0, $this->ledger(Account::CASH), 'The first month paid now leaves cash');
        $this->assertEquals(0.0, $this->ledger(Account::ACCOUNTS_PAYABLE), 'Billed and paid — nothing owed');
        $this->assertEveryEntryBalances();
    }

    public function test_3_1_occurrences_are_posted_once_their_date_arrives_and_only_once(): void
    {
        $category = $this->expenseCategory();

        foreach (['2026-07-01', '2026-08-01', '2026-09-01'] as $i => $date) {
            Expense::create([
                'vendor_id' => $this->vendor->id, 'category_id' => $category->id,
                'date' => $date, 'amount' => 1000, 'due_date' => $date,
                'recurring_id' => 'series-1', 'recurring_index' => $i + 1,
                'recurring_count' => 3, 'recurring_frequency' => 'monthly',
            ]);
        }

        $service = app(RecurringExpenseService::class);

        $this->assertSame(3, $service->postDueOccurrences($this->company->id));
        $this->assertSame(0, $service->postDueOccurrences($this->company->id), 'Never posted twice');
        $this->assertEquals(-3000.0, $this->ledger(Account::ACCOUNTS_PAYABLE), 'Three months of rent owed');
    }

    public function test_3_1_the_first_page_of_the_day_catches_recurring_expenses_up(): void
    {
        Expense::create([
            'vendor_id' => $this->vendor->id, 'category_id' => $this->expenseCategory()->id,
            'date' => '2026-09-01', 'amount' => 750, 'due_date' => '2026-09-01',
            'recurring_id' => 'series-2', 'recurring_index' => 1,
            'recurring_count' => 2, 'recurring_frequency' => 'monthly',
        ]);

        $this->company->forceFill(['depreciation_checked_on' => null])->save();

        $this->get(route('app.dashboard'))->assertOk();

        $this->assertEquals(-750.0, $this->ledger(Account::ACCOUNTS_PAYABLE));
    }

    public function test_3_1_cancelling_the_rest_of_a_series_is_logged(): void
    {
        $this->post('/app/expenses/recurring', [
            'vendor_id'   => $this->vendor->id,
            'category_id' => $this->expenseCategory()->id,
            'date'        => $this->today(),
            'amount'      => 100,
            'frequency'   => 'monthly',
            'count'       => 3,
            'mode'        => 'later',
        ])->assertSessionHasNoErrors();

        $recurringId = Expense::query()->value('recurring_id');

        $this->delete("/app/expenses/recurring/{$recurringId}")->assertSessionHasNoErrors();

        $this->assertSame(1, Expense::count(), 'Only the occurrence already due is kept');
        $this->assertSame(2, DeletionLog::query()->count(), 'Each cancelled occurrence is in the deletion log');
    }

    // ══ 3.2 Custody settled once ═════════════════════════════════

    public function test_3_2_a_custody_cannot_be_settled_twice(): void
    {
        $this->post('/app/custodies', [
            'holder_id' => $this->vendor->id, 'amount' => 2000, 'method' => 'cash', 'given_at' => '2026-09-01',
        ])->assertSessionHasNoErrors();
        Cache::flush();

        $custody = Custody::query()->firstOrFail();
        $payload = [
            'settlement_date' => '2026-09-05',
            'lines' => [['description' => 'Fuel', 'category_id' => $this->expenseCategory()->id, 'amount' => 1500]],
        ];

        $this->patch("/app/custodies/{$custody->id}/settle", $payload)->assertSessionHasNoErrors();
        Cache::flush();
        $cashAfterFirst = $this->ledger(Account::CASH);

        $this->patch("/app/custodies/{$custody->id}/settle", $payload)->assertSessionHas('error');

        $this->assertEquals($cashAfterFirst, $this->ledger(Account::CASH), 'The returned 500 is counted once');
        $this->assertSame(1, $custody->payments()->where('direction', 'in')->count());
        $this->assertEquals(-2000.0 + 500.0, $this->ledger(Account::CASH));
    }

    // ══ 3.3 Opening stock/equipment is not a bill ════════════════

    private function postOpeningBalance(array $overrides): void
    {
        $this->post('/app/opening-balance', array_merge([
            'opening_date' => '2026-09-01',
            'cash_amount'  => null,
            'bank_amount'  => null,
            'customers'    => [['customer_id' => null, 'amount' => null]],
            'suppliers'    => [['vendor_id' => null, 'amount' => null]],
            'inventory'    => [['item_id' => null, 'qty' => null, 'unit_price' => null]],
            'equipment'    => [['name' => '', 'category_id' => null, 'amount' => null, 'date' => '2026-09-01']],
        ], $overrides))->assertSessionHasNoErrors();
        Cache::flush();
    }

    public function test_3_3_opening_stock_and_equipment_are_not_open_bills_but_supplier_balances_are(): void
    {
        $item = Item::create(['company_id' => $this->company->id, 'name' => 'Cement', 'qty_per_uom' => 1, 'base_unit_name' => 'bag']);

        $this->postOpeningBalance([
            'suppliers' => [['vendor_id' => $this->vendor->id, 'amount' => 700]],
            'inventory' => [['item_id' => $item->id, 'qty' => 10, 'unit_price' => 50]],
            'equipment' => [['name' => 'Van', 'category_id' => Category::query()->equipmentKind()->firstOrFail()->id, 'amount' => 20000, 'date' => '2026-09-01']],
        ]);

        $types = collect($this->getJson(route('app.payments.open-bills'))->assertOk()->json('data'))->pluck('payable_type');

        $this->assertContains('expense', $types, 'Money really owed to a supplier stays on the list');
        $this->assertNotContains('inventory_purchase', $types);
        $this->assertNotContains('equipment_purchase', $types);

        $stock = InventoryPurchase::query()->where('is_opening_balance', true)->firstOrFail();
        $this->assertEquals(0.0, $stock->balance(), 'Nobody is owed for stock already owned');

        $this->post('/app/payments/pay', [
            'payable_type' => 'inventory_purchase', 'payable_id' => $stock->id,
            'date' => '2026-09-05', 'amount' => 500, 'method' => 'cash',
        ])->assertSessionHasErrors('payable_id');

        $this->assertSame(0, $stock->payments()->count());
    }

    // ══ 4.1 Reset keeps real payments ════════════════════════════

    public function test_4_1_reset_is_refused_while_real_payments_exist_against_the_opening_balance(): void
    {
        $this->postOpeningBalance(['customers' => [['customer_id' => $this->customer->id, 'amount' => 500]]]);

        $receivable = Sale::query()->where('is_opening_balance', true)->firstOrFail();

        $this->post('/app/payments/receive', [
            'sale_id' => $receivable->id, 'date' => '2026-09-10', 'amount' => 200, 'method' => 'cash',
        ])->assertSessionHasNoErrors();
        Cache::flush();

        $payment = Payment::query()->where('is_opening_balance', false)->firstOrFail();

        $this->delete(route('app.opening-balance.reset'))->assertSessionHas('error');

        $this->assertNotNull($payment->fresh(), 'The real collection survives');
        $this->assertEquals(200.0, $this->ledger(Account::CASH));

        // Remove it deliberately first — then the reset is allowed.
        $this->delete("/app/payments/{$payment->id}")->assertSessionHasNoErrors();
        $this->delete(route('app.opening-balance.reset'))->assertSessionMissing('error');

        $this->assertSame(0, Sale::query()->where('is_opening_balance', true)->count());
    }

    // ══ 4.2 Opening equipment depreciation ═══════════════════════

    public function test_4_2_opening_equipment_brings_its_past_depreciation_and_resumes_from_the_opening_month(): void
    {
        $category = Category::query()->equipmentKind()->firstOrFail();

        $this->postOpeningBalance([
            'equipment' => [['name' => 'Oven', 'category_id' => $category->id, 'amount' => 12000, 'date' => '2024-09-10']],
        ]);

        $asset    = EquipmentPurchase::query()->sole();
        $expected = round(min(12000, $asset->monthlyDepreciationAmount() * 24), 2); // Sep 2024 … Aug 2026

        $this->assertEquals($expected, (float) $asset->accumulated_depreciation);
        $this->assertSame('2026-08-31', Carbon::parse($asset->last_depreciated_through)->toDateString());

        $this->assertEquals(-$expected, $this->ledger(Account::ACCUMULATED_DEPRECIATION));
        $this->assertEquals(-(12000 - $expected), $this->ledger(Account::OWNERS_EQUITY), 'Equity gets the book value, not the full cost');
        $this->assertEquals(0.0, $this->ledger(Account::DEPRECIATION_EXPENSE), 'None of the old years hits this P&L');

        // The app's own depreciation starts with September 2026 only.
        $posted = app(DepreciationService::class)->catchUpAsset($asset->fresh(), Carbon::parse('2026-10-15'));
        $this->assertSame(1, $posted);
        $this->assertEquals($asset->monthlyDepreciationAmount(), $this->ledger(Account::DEPRECIATION_EXPENSE), 'One month — September 2026');
        $this->assertEveryEntryBalances();
    }

    public function test_4_2_a_depreciation_figure_the_user_knows_is_used_as_given(): void
    {
        $category = Category::query()->equipmentKind()->firstOrFail();

        $this->postOpeningBalance([
            'equipment' => [['name' => 'Car', 'category_id' => $category->id, 'amount' => 300000,
                'date' => '2023-01-01', 'accumulated_depreciation' => 90000]],
        ]);

        $this->assertEquals(90000.0, (float) EquipmentPurchase::query()->sole()->accumulated_depreciation);
        $this->assertEquals(-210000.0, $this->ledger(Account::OWNERS_EQUITY));
    }

    public function test_4_2_depreciation_already_taken_cannot_exceed_the_cost(): void
    {
        $category = Category::query()->equipmentKind()->firstOrFail();

        $this->post('/app/opening-balance', [
            'opening_date' => '2026-09-01',
            'equipment'    => [['name' => 'Car', 'category_id' => $category->id, 'amount' => 1000,
                'date' => '2023-01-01', 'accumulated_depreciation' => 5000]],
        ])->assertSessionHasErrors('equipment.0.accumulated_depreciation');

        $this->assertSame(0, EquipmentPurchase::count());
    }

    // ══ 4.3 Production labour clears across months ═══════════════

    public function test_4_3_wages_paid_the_month_after_production_clear_the_accrual(): void
    {
        $flour = Item::create(['company_id' => $this->company->id, 'name' => 'Flour', 'type' => 'raw_material', 'qty_per_uom' => 1, 'base_unit_name' => 'kg']);
        $bread = Item::create(['company_id' => $this->company->id, 'name' => 'Bread', 'type' => 'product', 'qty_per_uom' => 1, 'base_unit_name' => 'loaf']);

        $this->post('/app/inventory-purchases', [
            'vendor_id' => $this->vendor->id, 'date' => '2026-08-01',
            'lines' => [['item_id' => $flour->id, 'qty' => 100, 'qty_per_uom' => 1, 'unit_price' => 5]],
            'vat_rate' => 0, 'mode' => 'later',
        ])->assertSessionHasNoErrors();
        Cache::flush();

        $this->post('/app/production-orders', [
            'item_id' => $bread->id, 'date' => '2026-08-20', 'qty_produced' => 10,
            'materials' => [['item_id' => $flour->id, 'qty' => 20]], 'labor_cost' => 100,
        ])->assertSessionHasNoErrors();
        Cache::flush();

        $this->assertEquals(-100.0, $this->ledger(Account::PRODUCTION_LABOR_ACCRUED));
        $cogsBefore = $this->ledger(Account::COST_OF_GOODS_SOLD);

        // August wages, paid on 5 September.
        $this->post('/app/expenses', [
            'vendor_id' => $this->vendor->id, 'category_id' => $this->expenseCategory()->id,
            'date' => '2026-09-05', 'amount' => 100, 'mode' => 'later', 'is_production_labor' => true,
        ])->assertSessionHasNoErrors();

        $this->assertEquals(0.0, $this->ledger(Account::PRODUCTION_LABOR_ACCRUED), 'August\'s estimate is cleared');
        $this->assertEquals($cogsBefore, $this->ledger(Account::COST_OF_GOODS_SOLD), 'Paid exactly the estimate — no variance');
        $this->assertEveryEntryBalances();
    }

    // ══ 4.6 Customer overpayments are credit ═════════════════════

    public function test_4_6_an_overpayment_is_refused_unless_the_extra_is_kept_as_credit(): void
    {
        $sale = $this->openInvoice(100);

        $this->post('/app/payments/receive', [
            'sale_id' => $sale->id, 'date' => '2026-09-02', 'amount' => 120, 'method' => 'cash',
        ])->assertSessionHasErrors('amount');

        $this->post('/app/payments/receive', [
            'sale_id' => $sale->id, 'date' => '2026-09-02', 'amount' => 120, 'method' => 'cash',
            'keep_extra_as_credit' => true,
        ])->assertSessionHasNoErrors();

        $this->assertTrue($sale->fresh()->isPaid());
        $this->assertEquals(120.0, $this->ledger(Account::CASH), 'All 120 really came in');
        $this->assertEquals(-20.0, $this->ledger(Account::CUSTOMER_CREDITS), 'The extra 20 is owed back to the customer');
        $this->assertEquals(-100.0, $this->ledger(Account::SALES_REVENUE), 'Revenue is the invoice, not the overpayment');
        $this->assertEveryEntryBalances();
    }

    public function test_4_6_credit_pays_the_next_invoice_without_moving_cash(): void
    {
        $first = $this->openInvoice(100);
        $this->post('/app/payments/receive', [
            'sale_id' => $first->id, 'date' => '2026-09-02', 'amount' => 120, 'method' => 'cash',
            'keep_extra_as_credit' => true,
        ])->assertSessionHasNoErrors();
        Cache::flush();

        $second = $this->openInvoice(50, '2026-09-03');

        $this->post('/app/payments/receive', [
            'sale_id' => $second->id, 'date' => '2026-09-04', 'amount' => 30, 'use_credit' => true,
        ])->assertSessionHasErrors('amount'); // only 20 of credit exists

        $this->post('/app/payments/receive', [
            'sale_id' => $second->id, 'date' => '2026-09-04', 'amount' => 20, 'use_credit' => true,
        ])->assertSessionHasNoErrors();

        $this->assertEquals(30.0, $second->fresh()->balance());
        $this->assertEquals(0.0, $this->ledger(Account::CUSTOMER_CREDITS), 'The credit is used up');
        $this->assertEquals(120.0, $this->ledger(Account::CASH), 'Using credit moves no cash');

        $statement = app(\App\Services\Reports\ReportDataService::class)->customerStatement($this->customer->fresh());
        $this->assertEquals(30.0, $statement['balance'], 'Statement and ledger agree: 150 invoiced − 120 received');
        $this->assertEquals(30.0, $this->ledger(Account::ACCOUNTS_RECEIVABLE));
    }

    public function test_4_6_a_receipt_from_a_customer_with_no_invoice_is_an_advance_not_revenue(): void
    {
        $this->post('/app/payments/receive', [
            'customer_id' => $this->customer->id, 'date' => '2026-09-02', 'amount' => 400, 'method' => 'cash',
        ])->assertSessionHasNoErrors();

        $this->assertEquals(0.0, $this->ledger(Account::SALES_REVENUE));
        $this->assertEquals(-400.0, $this->ledger(Account::CUSTOMER_CREDITS));
    }

    public function test_4_6_credit_already_used_cannot_be_deleted(): void
    {
        $first = $this->openInvoice(100);
        $this->post('/app/payments/receive', [
            'sale_id' => $first->id, 'date' => '2026-09-02', 'amount' => 120, 'method' => 'cash',
            'keep_extra_as_credit' => true,
        ])->assertSessionHasNoErrors();
        Cache::flush();

        $second = $this->openInvoice(50, '2026-09-03');
        $this->post('/app/payments/receive', [
            'sale_id' => $second->id, 'date' => '2026-09-04', 'amount' => 20, 'use_credit' => true,
        ])->assertSessionHasNoErrors();

        $credit = Payment::query()->where('is_customer_credit', true)->firstOrFail();

        $this->delete("/app/payments/{$credit->id}")->assertSessionHas('error');
        $this->assertNotNull($credit->fresh());
    }
}
