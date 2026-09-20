<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use App\Models\Vendor;
use App\Services\JournalService;
use App\Support\FinancialRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  Bounds on money and on dates.
//
//  Validation used to say only "numeric" and "at least 0.01" about
//  every amount in the app, and only "a date" about every date.
//  Three separate things went wrong as a result, all of them from a
//  user simply mistyping:
//
//    - A figure past decimal(12,2) reached MySQL and came back as a
//      500 error page, with nothing saying which field was wrong.
//    - A receipt could settle more than the invoice it pointed at
//      was worth, leaving that invoice on a NEGATIVE balance which
//      then fed the open-invoice worklist, the customer statement
//      and Accounts Receivable.
//    - A year typed as 2099 was accepted in silence and sat in the
//      ledger skewing every report that spanned it.
//
//  See App\Support\FinancialRules for the bounds themselves, and the
//  GuardsPaymentAmount / GuardsDocumentTotal concerns for the two
//  checks that need more than a rule string.
// ══════════════════════════════════════════════════════════════════
class AmountAndDateLimitsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Customer $customer;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->admin   = User::factory()->companyAdmin($this->company)->create();

        app(JournalService::class)->seedChartOfAccounts($this->company);
        Category::seedDefaults($this->company->id);

        $this->actingAs($this->admin);

        $this->customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Acme']);
        $this->vendor   = Vendor::create(['company_id' => $this->company->id, 'name' => 'Supplies Co']);
    }

    private function expenseCategory(): Category
    {
        return Category::query()->where('company_id', $this->company->id)
            ->where('kind', 'expense')->firstOrFail();
    }

    private function salePayload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'date'        => '2026-09-01',
            'lines'       => [['item_id' => null, 'qty' => 1, 'unit_price' => 100]],
            'vat_rate'    => 0,
            'mode'        => 'later',
        ], $overrides);
    }

    /** An unpaid 100 invoice to point payments at. */
    private function openInvoice(float $amount = 100): Sale
    {
        $this->post('/app/sales', $this->salePayload([
            'lines' => [['item_id' => null, 'qty' => 1, 'unit_price' => $amount]],
        ]))->assertSessionHasNoErrors();

        return Sale::query()->latest('id')->firstOrFail();
    }

    // ── Money ceiling ────────────────────────────────────────────

    public function test_an_amount_past_the_column_range_is_rejected_not_crashed(): void
    {
        $this->post('/app/sales', $this->salePayload([
            'lines' => [['item_id' => null, 'qty' => 1, 'unit_price' => 99999999999999]],
        ]))->assertSessionHasErrors('lines.0.unit_price');

        $this->assertSame(0, Sale::count());
    }

    public function test_a_single_expense_past_the_ceiling_is_rejected(): void
    {
        $this->post('/app/expenses', [
            'vendor_id'   => $this->vendor->id,
            'category_id' => $this->expenseCategory()->id,
            'date'        => '2026-09-01',
            'amount'      => 99999999999999,
            'mode'        => 'later',
        ])->assertSessionHasErrors('amount');

        $this->assertSame(0, Expense::count());
    }

    /**
     * Each line can be under the cap while the total is not — the
     * per-field rule alone would let this through to MySQL.
     */
    public function test_lines_that_individually_fit_but_together_do_not_are_rejected(): void
    {
        $justUnder = FinancialRules::MAX_AMOUNT - 1;

        $this->post('/app/sales', $this->salePayload([
            'lines' => [
                ['item_id' => null, 'qty' => 1, 'unit_price' => $justUnder],
                ['item_id' => null, 'qty' => 1, 'unit_price' => $justUnder],
            ],
        ]))->assertSessionHasErrors('lines');

        $this->assertSame(0, Sale::count());
    }

    /**
     * A quantity is capped too — qty x unit_price is what overflows.
     */
    public function test_an_absurd_quantity_is_rejected(): void
    {
        $this->post('/app/sales', $this->salePayload([
            'lines' => [['item_id' => null, 'qty' => 999999999, 'unit_price' => 100]],
        ]))->assertSessionHasErrors('lines.0.qty');
    }

    public function test_an_ordinary_amount_is_still_accepted(): void
    {
        $this->post('/app/sales', $this->salePayload([
            'lines' => [['item_id' => null, 'qty' => 3, 'unit_price' => 1500.50]],
        ]))->assertSessionHasNoErrors();

        $this->assertEquals(4501.50, (float) Sale::query()->firstOrFail()->amount);
    }

    // ── Date window ──────────────────────────────────────────────

    public function test_a_sale_dated_far_in_the_future_is_rejected(): void
    {
        $this->post('/app/sales', $this->salePayload(['date' => '2099-01-01']))
            ->assertSessionHasErrors('date');

        $this->assertSame(0, Sale::count());
    }

    public function test_a_sale_dated_before_the_window_is_rejected(): void
    {
        $this->post('/app/sales', $this->salePayload(['date' => '1899-01-01']))
            ->assertSessionHasErrors('date');
    }

    public function test_a_date_inside_the_window_is_accepted(): void
    {
        $this->post('/app/sales', $this->salePayload(['date' => now()->subYears(2)->toDateString()]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Sale::count());
    }

    /**
     * A transaction date can never be in the future, not even by a
     * day.
     *
     * This test asserted the opposite until the date window was
     * tightened (see FinancialRules' class doc comment): a
     * transaction date records when something HAPPENED, and nothing
     * in this app legitimately happens later than today. There is no
     * post-dated payment method here — every method settles the
     * moment it is recorded — and "pay later" is the separate
     * due_date field, which keeps its own much wider window.
     */
    public function test_a_post_dated_document_is_rejected(): void
    {
        $this->post('/app/sales', $this->salePayload(['date' => now()->addMonth()->toDateString()]))
            ->assertSessionHasErrors('date');

        $this->assertSame(0, Sale::count());
    }

    /** Today itself is the boundary, and it is inclusive. */
    public function test_a_document_dated_today_is_accepted(): void
    {
        $this->post('/app/sales', $this->salePayload([
            'date' => \Illuminate\Support\Carbon::today('Africa/Cairo')->toDateString(),
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1, Sale::count());
    }

    /**
     * But a due date still reaches years ahead — tightening the
     * transaction date must not have tightened this with it.
     */
    public function test_a_due_date_a_year_out_is_still_allowed(): void
    {
        $this->post('/app/sales', $this->salePayload([
            'mode'     => 'later',
            'due_date' => now()->addYear()->toDateString(),
        ]))->assertSessionHasNoErrors();
    }

    public function test_a_payment_dated_far_in_the_future_is_rejected(): void
    {
        $sale = $this->openInvoice();

        $this->post('/app/payments/receive', [
            'sale_id' => $sale->id,
            'date'    => '2099-01-01',
            'amount'  => 50,
            'method'  => 'cash',
        ])->assertSessionHasErrors('date');
    }

    public function test_a_due_date_centuries_out_is_rejected(): void
    {
        $this->post('/app/sales', $this->salePayload([
            'mode'     => 'later',
            'due_date' => '2500-01-01',
        ]))->assertSessionHasErrors('due_date');
    }

    // ── Overpayment ──────────────────────────────────────────────

    public function test_a_receipt_cannot_exceed_the_invoice_balance(): void
    {
        $sale = $this->openInvoice(100);

        $this->post('/app/payments/receive', [
            'sale_id' => $sale->id,
            'date'    => '2026-09-02',
            'amount'  => 100000,
            'method'  => 'cash',
        ])->assertSessionHasErrors('amount');

        $this->assertSame(0, Payment::count());
        $this->assertEquals(100.0, $sale->fresh()->balance());
    }

    public function test_a_receipt_settling_the_invoice_exactly_is_accepted(): void
    {
        $sale = $this->openInvoice(100);

        $this->post('/app/payments/receive', [
            'sale_id' => $sale->id,
            'date'    => '2026-09-02',
            'amount'  => 100,
            'method'  => 'cash',
        ])->assertSessionHasNoErrors();

        $this->assertTrue($sale->fresh()->isPaid());
    }

    public function test_a_second_receipt_cannot_exceed_what_is_left(): void
    {
        $sale = $this->openInvoice(100);

        $this->post('/app/payments/receive', [
            'sale_id' => $sale->id, 'date' => '2026-09-02', 'amount' => 60, 'method' => 'cash',
        ])->assertSessionHasNoErrors();

        $this->post('/app/payments/receive', [
            'sale_id' => $sale->id, 'date' => '2026-09-03', 'amount' => 50, 'method' => 'cash',
        ])->assertSessionHasErrors('amount');

        $this->post('/app/payments/receive', [
            'sale_id' => $sale->id, 'date' => '2026-09-03', 'amount' => 40, 'method' => 'cash',
        ])->assertSessionHasNoErrors();

        $this->assertTrue($sale->fresh()->isPaid());
    }

    /**
     * A genuine overpayment still has a home — the standalone
     * receipt, which is revenue rather than a negative debt.
     */
    public function test_a_standalone_receipt_is_still_unbounded_by_any_invoice(): void
    {
        $this->openInvoice(100);

        $this->post('/app/payments/receive', [
            'customer_id' => $this->customer->id,
            'date'        => '2026-09-02',
            'amount'      => 100000,
            'method'      => 'cash',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Payment::count());
    }

    public function test_a_payment_cannot_exceed_the_bill_balance(): void
    {
        $this->post('/app/expenses', [
            'vendor_id'   => $this->vendor->id,
            'category_id' => $this->expenseCategory()->id,
            'date'        => '2026-09-01',
            'amount'      => 500,
            'mode'        => 'later',
        ])->assertSessionHasNoErrors();

        $expense = Expense::query()->firstOrFail();

        $this->post('/app/payments/pay', [
            'payable_type' => 'expense',
            'payable_id'   => $expense->id,
            'date'         => '2026-09-02',
            'amount'       => 5000,
            'method'       => 'cash',
        ])->assertSessionHasErrors('amount');

        $this->assertEquals(500.0, $expense->fresh()->balance());
    }

    public function test_correcting_a_payment_cannot_push_the_invoice_negative(): void
    {
        $sale = $this->openInvoice(100);

        $this->post('/app/payments/receive', [
            'sale_id' => $sale->id, 'date' => '2026-09-02', 'amount' => 100, 'method' => 'cash',
        ])->assertSessionHasNoErrors();

        $payment = Payment::query()->firstOrFail();

        $this->patch("/app/payments/{$payment->id}", [
            'date' => '2026-09-02', 'amount' => 900, 'method' => 'cash',
        ])->assertSessionHasErrors('amount');

        $this->assertEquals(100.0, (float) $payment->fresh()->amount);
    }

    /**
     * The guard credits the payment's own amount back before asking
     * what is left, so re-saving it unchanged must not be read as an
     * overpayment of itself.
     */
    public function test_correcting_a_payment_to_the_same_amount_is_allowed(): void
    {
        $sale = $this->openInvoice(100);

        $this->post('/app/payments/receive', [
            'sale_id' => $sale->id, 'date' => '2026-09-02', 'amount' => 100, 'method' => 'cash',
        ])->assertSessionHasNoErrors();

        $payment = Payment::query()->firstOrFail();

        $this->patch("/app/payments/{$payment->id}", [
            'date' => '2026-09-04', 'amount' => 100, 'method' => 'bank',
        ])->assertSessionHasNoErrors();

        $this->assertSame('bank', $payment->fresh()->method);
    }

    public function test_reducing_a_payment_is_allowed(): void
    {
        $sale = $this->openInvoice(100);

        $this->post('/app/payments/receive', [
            'sale_id' => $sale->id, 'date' => '2026-09-02', 'amount' => 100, 'method' => 'cash',
        ])->assertSessionHasNoErrors();

        $payment = Payment::query()->firstOrFail();

        $this->patch("/app/payments/{$payment->id}", [
            'date' => '2026-09-02', 'amount' => 40, 'method' => 'cash',
        ])->assertSessionHasNoErrors();

        $this->assertEquals(60.0, $sale->fresh()->balance());
    }

    // ── "Paid now" on a partial ──────────────────────────────────

    public function test_paying_more_than_the_document_up_front_is_rejected(): void
    {
        $this->post('/app/sales', $this->salePayload([
            'lines'      => [['item_id' => null, 'qty' => 1, 'unit_price' => 500]],
            'mode'       => 'partial',
            'method'     => 'cash',
            'amount_now' => 5000,
        ]))->assertSessionHasErrors('amount_now');

        $this->assertSame(0, Sale::count());
    }

    public function test_a_normal_partial_payment_is_still_accepted(): void
    {
        $this->post('/app/sales', $this->salePayload([
            'lines'      => [['item_id' => null, 'qty' => 1, 'unit_price' => 500]],
            'mode'       => 'partial',
            'method'     => 'cash',
            'amount_now' => 200,
        ]))->assertSessionHasNoErrors();

        $this->assertEquals(300.0, Sale::query()->firstOrFail()->balance());
    }
}
