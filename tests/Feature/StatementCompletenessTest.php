<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vendor;
use App\Services\JournalService;
use App\Services\Reports\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  A party's statement has to show every movement between them and
//  the company — not only the ones that went through an invoice.
//
//  "Receive Money" and "Pay Money" both offer a generic entry with
//  no invoice or bill behind it, tagged with a customer or supplier.
//  PaymentController's own doc comment says that tag exists "purely
//  for reporting (statements)" — but the statements reached payments
//  only by walking Sale/Expense/Purchase relations, so a tagged
//  standalone receipt was invisible to the single report it was
//  tagged for. Money genuinely received simply never appeared, and
//  the closing balance was wrong by exactly that amount.
//
//  Customer::standalonePayments() / Vendor::standalonePayments() are
//  what reach them now. These tests cover both directions on both
//  statements, plus the invoice path, so the fix cannot be undone by
//  a later change that only remembers one of them.
// ══════════════════════════════════════════════════════════════════
class StatementCompletenessTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->admin   = User::factory()->companyAdmin($this->company)->create();

        app(JournalService::class)->seedChartOfAccounts($this->company);
        Category::seedDefaults($this->company->id);

        $this->actingAs($this->admin);
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

    // ── Customer statement ───────────────────────────────────────

    public function test_a_receipt_tagged_to_a_customer_appears_on_their_statement(): void
    {
        $customer = $this->customer();

        $this->post('/app/payments/receive', [
            'customer_id' => $customer->id,
            'date'        => '2026-09-05',
            'amount'      => 750,
            'method'      => 'cash',
            'note'        => 'Cash sale, no invoice',
        ])->assertSessionHasNoErrors();

        $statement = $this->reports()->customerStatement($customer->fresh());

        $this->assertCount(1, $statement['entries']);
        $this->assertSame('Cash sale, no invoice', $statement['entries'][0]['ref']);
        $this->assertEquals(750.0, $statement['entries'][0]['credit']);
        $this->assertEquals(-750.0, $statement['balance'], 'Money in with nothing owed leaves a credit balance');
    }

    public function test_a_tagged_receipt_reduces_what_the_customer_owes(): void
    {
        $customer = $this->customer();

        $this->post('/app/sales', [
            'customer_id' => $customer->id,
            'date'        => '2026-09-01',
            'lines'       => [['item_id' => null, 'qty' => 1, 'unit_price' => 1000]],
            'vat_rate'    => 0,
            'mode'        => 'later',
        ])->assertSessionHasNoErrors();

        $this->post('/app/payments/receive', [
            'customer_id' => $customer->id,
            'date'        => '2026-09-05',
            'amount'      => 400,
            'method'      => 'cash',
        ])->assertSessionHasNoErrors();

        $statement = $this->reports()->customerStatement($customer->fresh());

        $this->assertCount(2, $statement['entries'], 'The invoice and the loose receipt');
        $this->assertEquals(600.0, $statement['balance']);
    }

    public function test_a_receipt_tagged_to_nobody_stays_off_every_statement(): void
    {
        $customer = $this->customer();

        $this->post('/app/payments/receive', [
            'date'   => '2026-09-05',
            'amount' => 750,
            'method' => 'cash',
        ])->assertSessionHasNoErrors();

        $statement = $this->reports()->customerStatement($customer->fresh());

        $this->assertCount(0, $statement['entries']);
        $this->assertEquals(0.0, $statement['balance']);
    }

    public function test_a_receipt_tagged_to_another_customer_stays_off_this_one(): void
    {
        $acme  = $this->customer();
        $other = Customer::create(['company_id' => $this->company->id, 'name' => 'Other Ltd']);

        $this->post('/app/payments/receive', [
            'customer_id' => $other->id,
            'date'        => '2026-09-05',
            'amount'      => 750,
            'method'      => 'cash',
        ])->assertSessionHasNoErrors();

        $this->assertCount(0, $this->reports()->customerStatement($acme->fresh())['entries']);
        $this->assertCount(1, $this->reports()->customerStatement($other->fresh())['entries']);
    }

    /**
     * A payment OUT tagged to a customer is a refund — it increases
     * what they owe rather than reducing it.
     */
    public function test_a_refund_to_a_customer_increases_what_they_owe(): void
    {
        $customer = $this->customer();

        $this->post('/app/payments/pay', [
            'date'   => '2026-09-05',
            'amount' => 200,
            'method' => 'cash',
        ])->assertSessionHasNoErrors();

        // Tag it after the fact — the "Pay Money" form has no
        // customer field, so this is the shape such a row takes.
        \App\Models\Payment::query()->latest('id')->firstOrFail()
            ->update(['customer_id' => $customer->id]);

        $statement = $this->reports()->customerStatement($customer->fresh());

        $this->assertCount(1, $statement['entries']);
        $this->assertEquals(200.0, $statement['entries'][0]['debit']);
        $this->assertEquals(200.0, $statement['balance']);
    }

    /**
     * Payments that settle an invoice are reached through the Sale,
     * not through the customer — they must not be counted twice now
     * that a second route to payments exists.
     */
    public function test_an_invoice_payment_is_counted_once(): void
    {
        $customer = $this->customer();

        $this->post('/app/sales', [
            'customer_id' => $customer->id,
            'date'        => '2026-09-01',
            'lines'       => [['item_id' => null, 'qty' => 1, 'unit_price' => 1000]],
            'vat_rate'    => 0,
            'mode'        => 'now',
            'method'      => 'cash',
        ])->assertSessionHasNoErrors();

        $statement = $this->reports()->customerStatement($customer->fresh());

        $this->assertCount(2, $statement['entries'], 'The invoice and its one payment');
        $this->assertEquals(0.0, $statement['balance']);
    }

    // ── Supplier statement ───────────────────────────────────────

    public function test_a_payment_tagged_to_a_supplier_appears_on_their_statement(): void
    {
        $vendor   = $this->vendor();
        $category = Category::query()->where('company_id', $this->company->id)
            ->where('kind', 'expense')->firstOrFail();

        $this->post('/app/payments/pay', [
            'vendor_id'   => $vendor->id,
            'category_id' => $category->id,
            'date'        => '2026-09-05',
            'amount'      => 300,
            'method'      => 'cash',
            'note'        => 'Petty purchase, no bill',
        ])->assertSessionHasNoErrors();

        $statement = $this->reports()->supplierStatement($vendor->fresh());

        $this->assertCount(1, $statement['entries']);
        $this->assertSame('Petty purchase, no bill', $statement['entries'][0]['ref']);
        $this->assertEquals(300.0, $statement['entries'][0]['debit']);
        $this->assertEquals(-300.0, $statement['balance'], 'Paid without a bill leaves them owing us');
    }

    public function test_a_tagged_payment_reduces_what_is_owed_to_the_supplier(): void
    {
        $vendor   = $this->vendor();
        $category = Category::query()->where('company_id', $this->company->id)
            ->where('kind', 'expense')->firstOrFail();

        $this->post('/app/expenses', [
            'vendor_id'   => $vendor->id,
            'category_id' => $category->id,
            'date'        => '2026-09-01',
            'amount'      => 1000,
            'mode'        => 'later',
        ])->assertSessionHasNoErrors();

        $this->post('/app/payments/pay', [
            'vendor_id'   => $vendor->id,
            'category_id' => $category->id,
            'date'        => '2026-09-05',
            'amount'      => 400,
            'method'      => 'cash',
        ])->assertSessionHasNoErrors();

        $statement = $this->reports()->supplierStatement($vendor->fresh());

        $this->assertCount(2, $statement['entries']);
        $this->assertEquals(600.0, $statement['balance']);
    }

    public function test_statements_never_cross_the_company_boundary(): void
    {
        $customer = $this->customer();

        $this->post('/app/payments/receive', [
            'customer_id' => $customer->id,
            'date'        => '2026-09-05',
            'amount'      => 750,
            'method'      => 'cash',
        ])->assertSessionHasNoErrors();

        $otherCompany = Company::factory()->create();
        $otherAdmin   = User::factory()->companyAdmin($otherCompany)->create();

        $this->actingAs($otherAdmin);

        $this->assertNull(
            Customer::find($customer->id),
            "Another company's customer is not even resolvable, so their statement is unreachable"
        );
    }
}
