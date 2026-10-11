<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\InventoryPurchase;
use App\Models\Item;
use App\Models\JournalLine;
use App\Models\Sale;
use App\Models\User;
use App\Models\Vendor;
use App\Services\JournalService;
use App\Services\Reports\ReportDataService;
use App\Support\LineTax;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  VAT per product line, and Withholding Tax per product line.
//
//  Sales        — VAT per line + DEBIT withholding per line
//                 (the customer keeps part of the invoice back and
//                 pays it to the tax authority for us).
//  Purchases    — VAT per line + CREDIT withholding per line
//                 (we keep part of the bill back and pay it to the
//                 tax authority).
//
//  Rules confirmed with the business owner:
//    * Withholding = line amount BEFORE VAT x withholding %.
//    * `amount` on the invoice = subtotal + VAT - withholding = what
//      is really owed, so balances / payments keep working.
//    * The withholding appears on the customer / supplier statement
//      as its own line, and the statement balance is what is owed.
// ══════════════════════════════════════════════════════════════════
class LineVatAndWithholdingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $admin = User::factory()->companyAdmin($this->company)->create();

        app(JournalService::class)->seedChartOfAccounts($this->company);
        Category::seedDefaults($this->company->id);

        $this->actingAs($admin);
    }

    private function customer(): Customer
    {
        return Customer::create(['company_id' => $this->company->id, 'name' => 'Acme']);
    }

    private function vendor(): Vendor
    {
        return Vendor::create(['company_id' => $this->company->id, 'name' => 'Supplies Co']);
    }

    private function reports(): ReportDataService
    {
        return app(ReportDataService::class);
    }

    /** Debits minus credits posted to one account (by code). */
    private function ledger(string $code): float
    {
        $account = Account::query()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();

        return round(
            (float) JournalLine::query()->where('account_id', $account->id)->sum('debit')
            - (float) JournalLine::query()->where('account_id', $account->id)->sum('credit'),
            2
        );
    }

    /**
     * Two sale lines: 10 x 100 with 14% VAT and 5% withholding,
     * and 2 x 50 with no tax at all.
     *
     *   subtotal 1,100.00   VAT 140.00   withholding 50.00
     *   invoice on paper 1,240.00   customer owes 1,190.00
     *
     * @return array<string, mixed>
     */
    private function saleLines(): array
    {
        return [
            ['item_id' => null, 'qty' => 10, 'unit_price' => 100, 'vat_rate' => 14, 'withholding_rate' => 5],
            ['item_id' => null, 'qty' => 2,  'unit_price' => 50,  'vat_rate' => 0,  'withholding_rate' => 0],
        ];
    }

    private function recordSale(Customer $customer, array $extra = []): void
    {
        $this->post('/app/sales', array_merge([
            'customer_id' => $customer->id,
            'date'        => '2026-09-01',
            'lines'       => $this->saleLines(),
            'mode'        => 'later',
        ], $extra))->assertSessionHasNoErrors();

        Cache::flush(); // the duplicate-submission guard is not what these tests are about
    }

    // ── The calculator ───────────────────────────────────────────

    public function test_line_tax_works_out_each_line_then_adds_them_up(): void
    {
        $tax = LineTax::compute($this->saleLines());

        $this->assertSame(1100.0, $tax['subtotal']);
        $this->assertSame(140.0, $tax['vat_amount']);
        $this->assertSame(50.0, $tax['withholding_amount']);
        $this->assertSame(1190.0, $tax['total']);
        $this->assertSame(140.0, $tax['lines'][0]['vat_amount']);
        $this->assertSame(50.0, $tax['lines'][0]['withholding_amount']);
        $this->assertSame(0.0, $tax['lines'][1]['vat_amount']);
    }

    public function test_withholding_is_calculated_before_vat(): void
    {
        // 1,000 net, 14% VAT, 10% withholding: 10% of 1,000 = 100 —
        // NOT 10% of 1,140.
        $tax = LineTax::compute([['qty' => 1, 'unit_price' => 1000, 'vat_rate' => 14, 'withholding_rate' => 10]]);

        $this->assertSame(100.0, $tax['withholding_amount']);
        $this->assertSame(1040.0, $tax['total']);
    }

    // ── Sales: VAT per line + debit withholding ──────────────────

    public function test_a_sale_keeps_vat_and_withholding_on_each_line(): void
    {
        $customer = $this->customer();
        $this->recordSale($customer);

        $sale = Sale::query()->with('lines')->firstOrFail();

        $this->assertEquals(1100.00, $sale->subtotal);
        $this->assertEquals(140.00, $sale->vat_amount);
        $this->assertEquals(50.00, $sale->withholding_amount);
        $this->assertEquals(1190.00, $sale->amount, 'amount is what the customer really owes');
        $this->assertEquals(1240.00, $sale->grossAmount(), 'the invoice as it reads on paper');

        $first = $sale->lines->first();
        $this->assertEquals(14.0, $first->vat_rate);
        $this->assertEquals(140.0, $first->vat_amount);
        $this->assertEquals(5.0, $first->withholding_rate);
        $this->assertEquals(50.0, $first->withholding_amount);

        $second = $sale->lines->last();
        $this->assertEquals(0.0, $second->vat_amount);
        $this->assertEquals(0.0, $second->withholding_amount);
    }

    public function test_different_vat_rates_on_different_lines_are_each_applied(): void
    {
        $this->post('/app/sales', [
            'customer_id' => $this->customer()->id,
            'date'        => '2026-09-01',
            'lines'       => [
                ['item_id' => null, 'qty' => 1, 'unit_price' => 100, 'vat_rate' => 14],
                ['item_id' => null, 'qty' => 1, 'unit_price' => 100, 'vat_rate' => 5],
            ],
            'mode'        => 'later',
        ])->assertSessionHasNoErrors();

        $sale = Sale::query()->firstOrFail();

        $this->assertEquals(19.0, $sale->vat_amount, '14 + 5');
        $this->assertEquals(219.0, $sale->amount);
    }

    public function test_an_old_style_sale_with_one_invoice_vat_rate_still_works(): void
    {
        // No per-line VAT sent — the invoice-level rate is used for every line.
        $this->post('/app/sales', [
            'customer_id' => $this->customer()->id,
            'date'        => '2026-09-01',
            'lines'       => [['item_id' => null, 'qty' => 1, 'unit_price' => 1000]],
            'vat_rate'    => 14,
            'mode'        => 'later',
        ])->assertSessionHasNoErrors();

        $sale = Sale::query()->with('lines')->firstOrFail();

        $this->assertEquals(140.0, $sale->vat_amount);
        $this->assertEquals(1140.0, $sale->amount);
        $this->assertEquals(0.0, $sale->withholding_amount);
        $this->assertEquals(14.0, $sale->lines->first()->vat_rate);
    }

    public function test_a_sale_with_withholding_posts_it_to_its_own_account(): void
    {
        $this->recordSale($this->customer());

        $this->assertSame(1190.0, $this->ledger(Account::ACCOUNTS_RECEIVABLE), 'the customer owes the net amount');
        $this->assertSame(50.0, $this->ledger(Account::WITHHOLDING_TAX_RECEIVABLE), 'a claim on the tax authority');
        $this->assertSame(-140.0, $this->ledger(Account::VAT_PAYABLE));
        $this->assertSame(-1100.0, $this->ledger(Account::SALES_REVENUE));
    }

    public function test_the_customer_statement_shows_the_withholding_and_the_right_balance(): void
    {
        $customer = $this->customer();
        $this->recordSale($customer);

        $statement = $this->reports()->customerStatement($customer->fresh());
        $entries   = collect($statement['entries']);

        $invoice = $entries->firstWhere('type', 'sale');
        $holdout = $entries->firstWhere('type', 'withholding_tax');

        $this->assertEquals(1240.0, $invoice['debit'], 'the invoice as it reads on paper');
        $this->assertEquals(50.0, $holdout['credit'], 'the withholding the customer keeps back');
        $this->assertEquals(1190.0, $statement['balance'], 'what the customer really owes');
    }

    public function test_paying_the_net_amount_settles_a_sale_with_withholding(): void
    {
        $customer = $this->customer();
        $this->recordSale($customer, ['mode' => 'now', 'method' => 'cash']);

        $sale = Sale::query()->with('payments')->firstOrFail();

        $this->assertEquals(1190.0, $sale->paidAmount(), 'paid in full means the net amount');
        $this->assertTrue($sale->isPaid());
        $this->assertEquals(0.0, $sale->balance());

        $statement = $this->reports()->customerStatement($customer->fresh());
        $this->assertEquals(0.0, $statement['balance']);

        $this->assertSame(0.0, $this->ledger(Account::ACCOUNTS_RECEIVABLE));
    }

    public function test_paid_now_cannot_be_more_than_the_net_amount(): void
    {
        $this->post('/app/sales', [
            'customer_id' => $this->customer()->id,
            'date'        => '2026-09-01',
            'lines'       => $this->saleLines(),
            'mode'        => 'partial',
            'method'      => 'cash',
            'amount_now'  => 1200, // more than the 1,190 really owed
        ])->assertSessionHasErrors('amount_now');

        $this->assertSame(0, Sale::query()->count());
    }

    public function test_editing_a_sale_replaces_its_withholding_in_the_books(): void
    {
        $customer = $this->customer();
        $this->recordSale($customer);

        $sale = Sale::query()->firstOrFail();

        // Change: one line, 1,000 net, 14% VAT, 10% withholding.
        $this->put('/app/sales/'.$sale->id, [
            'customer_id' => $customer->id,
            'date'        => '2026-09-01',
            'lines'       => [['item_id' => null, 'qty' => 1, 'unit_price' => 1000, 'vat_rate' => 14, 'withholding_rate' => 10]],
        ])->assertSessionHasNoErrors();

        $sale = $sale->fresh();

        $this->assertEquals(100.0, $sale->withholding_amount);
        $this->assertEquals(1040.0, $sale->amount);

        // The old entry was reversed and the new one posted — only the
        // new withholding is left in the books.
        $this->assertSame(100.0, $this->ledger(Account::WITHHOLDING_TAX_RECEIVABLE));
        $this->assertSame(1040.0, $this->ledger(Account::ACCOUNTS_RECEIVABLE));
    }

    public function test_a_sale_without_withholding_is_unchanged(): void
    {
        $customer = $this->customer();

        $this->post('/app/sales', [
            'customer_id' => $customer->id,
            'date'        => '2026-09-01',
            'lines'       => [['item_id' => null, 'qty' => 1, 'unit_price' => 500]],
            'mode'        => 'later',
        ])->assertSessionHasNoErrors();

        $statement = $this->reports()->customerStatement($customer->fresh());

        $this->assertCount(1, $statement['entries'], 'no withholding line when there is no withholding');
        $this->assertEquals(500.0, $statement['balance']);
        $this->assertSame(0.0, $this->ledger(Account::WITHHOLDING_TAX_RECEIVABLE));
    }

    public function test_a_withholding_percentage_over_100_is_refused(): void
    {
        $this->post('/app/sales', [
            'customer_id' => $this->customer()->id,
            'date'        => '2026-09-01',
            'lines'       => [['item_id' => null, 'qty' => 1, 'unit_price' => 100, 'withholding_rate' => 150]],
            'mode'        => 'later',
        ])->assertSessionHasErrors('lines.0.withholding_rate');
    }

    // ── Inventory purchases: VAT per line + credit withholding ───

    /**
     * 10 Cartons x 240 = 2,400 net, 14% VAT = 336, 2% withholding = 48.
     * Bill on paper 2,736.00 — we owe the supplier 2,688.00.
     */
    private function recordPurchase(Vendor $vendor, array $extra = []): void
    {
        $item = Item::create([
            'company_id'     => $this->company->id,
            'name'           => 'Juice',
            'qty_per_uom'    => 24,
            'base_unit_name' => 'piece',
        ]);

        $this->post('/app/inventory-purchases', array_merge([
            'vendor_id' => $vendor->id,
            'date'      => '2026-09-01',
            'lines'     => [[
                'item_id'          => $item->id,
                'qty'              => 10,
                'uom'              => 'Carton',
                'qty_per_uom'      => 24,
                'base_unit_name'   => 'piece',
                'unit_price'       => 240,
                'vat_rate'         => 14,
                'withholding_rate' => 2,
            ]],
            'mode'      => 'later',
        ], $extra))->assertSessionHasNoErrors();

        Cache::flush();
    }

    public function test_a_purchase_keeps_vat_and_withholding_on_each_line(): void
    {
        $this->recordPurchase($this->vendor());

        $purchase = InventoryPurchase::query()->with('lines')->firstOrFail();

        $this->assertEquals(2400.0, $purchase->subtotal);
        $this->assertEquals(336.0, $purchase->vat_amount);
        $this->assertEquals(48.0, $purchase->withholding_amount);
        $this->assertEquals(2688.0, $purchase->amount, 'amount is what we really owe the supplier');
        $this->assertEquals(2736.0, $purchase->grossAmount());

        $line = $purchase->lines->first();
        $this->assertEquals(14.0, $line->vat_rate);
        $this->assertEquals(336.0, $line->vat_amount);
        $this->assertEquals(2.0, $line->withholding_rate);
        $this->assertEquals(48.0, $line->withholding_amount);
    }

    public function test_a_purchase_with_withholding_posts_it_to_its_own_account(): void
    {
        $this->recordPurchase($this->vendor());

        $this->assertSame(2400.0, $this->ledger(Account::INVENTORY_ASSET), 'withholding does not change stock value');
        $this->assertSame(336.0, $this->ledger(Account::VAT_RECEIVABLE));
        $this->assertSame(-2688.0, $this->ledger(Account::ACCOUNTS_PAYABLE), 'we owe the supplier the net amount');
        $this->assertSame(-48.0, $this->ledger(Account::WITHHOLDING_TAX_PAYABLE), 'we owe this to the tax authority');
    }

    public function test_the_supplier_statement_shows_the_withholding_and_the_right_balance(): void
    {
        $vendor = $this->vendor();
        $this->recordPurchase($vendor);

        $statement = $this->reports()->supplierStatement($vendor->fresh());
        $entries   = collect($statement['entries']);

        $bill    = $entries->firstWhere('type', 'inventory_purchase');
        $holdout = $entries->firstWhere('type', 'withholding_tax');

        $this->assertEquals(2736.0, $bill['credit_owed'], 'the bill as it reads on paper');
        $this->assertEquals(48.0, $holdout['debit'], 'the withholding we keep back');
        $this->assertEquals(2688.0, $statement['balance'], 'what we really owe the supplier');
    }

    public function test_paying_the_net_amount_settles_a_purchase_with_withholding(): void
    {
        $vendor = $this->vendor();
        $this->recordPurchase($vendor, ['mode' => 'now', 'method' => 'cash']);

        $purchase = InventoryPurchase::query()->with('payments')->firstOrFail();

        $this->assertEquals(2688.0, $purchase->paidAmount());
        $this->assertTrue($purchase->isPaid());

        $statement = $this->reports()->supplierStatement($vendor->fresh());
        $this->assertEquals(0.0, $statement['balance']);
        $this->assertSame(0.0, $this->ledger(Account::ACCOUNTS_PAYABLE));
    }
}
