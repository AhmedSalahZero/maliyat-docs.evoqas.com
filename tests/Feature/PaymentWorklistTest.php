<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  The "what do I settle next" lists on the Receive / Pay screen.
//
//  These used to load every sale and every bill the company had ever
//  recorded and decide open-vs-settled in PHP, calling balance() per
//  row — which issued its own SUM query. 200 sales cost ~400 queries
//  and most of a second; the cost grew with the table forever.
//
//  The filter now runs in SQL and the result is capped, so these
//  tests assert both halves: that the right rows come back, and that
//  the query count stays flat as the data grows.
// ══════════════════════════════════════════════════════════════════
class PaymentWorklistTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->user    = User::factory()->companyAdmin($this->company)->create();
    }

    private function sale(float $amount, float $paid = 0, ?string $dueDate = null, ?Customer $customer = null): Sale
    {
        $customer ??= Customer::factory()?->create() ?? Customer::create([
            'name' => 'Customer', 'company_id' => $this->company->id,
        ]);

        $sale = Sale::create([
            'company_id'  => $this->company->id,
            'customer_id' => $customer->id,
            'date'        => now()->toDateString(),
            'due_date'    => $dueDate,
            'subtotal'    => $amount,
            'vat_rate'    => 0,
            'vat_amount'  => 0,
            'amount'      => $amount,
            'created_by'  => $this->user->id,
        ]);

        if ($paid > 0) {
            $sale->payments()->create([
                'company_id' => $this->company->id,
                'date'       => now()->toDateString(),
                'amount'     => $paid,
                'direction'  => 'in',
                'method'     => 'cash',
            ]);
        }

        return $sale;
    }

    private function customer(string $name): Customer
    {
        return Customer::create(['name' => $name, 'company_id' => $this->company->id]);
    }

    private function openInvoices(array $query = []): array
    {
        return $this->actingAs($this->user)
            ->getJson(route('app.payments.open-invoices', $query))
            ->assertOk()
            ->json();
    }

    // ── Correctness ──────────────────────────────────────────────

    public function test_only_records_with_something_left_to_pay_are_listed(): void
    {
        $customer = $this->customer('Acme');

        $unpaid  = $this->sale(100, 0, null, $customer);
        $partial = $this->sale(100, 40, null, $customer);
        $settled = $this->sale(100, 100, null, $customer);
        $over    = $this->sale(100, 120, null, $customer);

        $ids = array_column($this->openInvoices()['data'], 'id');

        $this->assertContains($unpaid->id, $ids, 'An unpaid invoice must be listed.');
        $this->assertContains($partial->id, $ids, 'A part-paid invoice must be listed.');
        $this->assertNotContains($settled->id, $ids, 'A fully paid invoice must not be listed.');
        $this->assertNotContains($over->id, $ids, 'An overpaid invoice must not be listed.');
    }

    public function test_a_rounding_level_shortfall_counts_as_settled(): void
    {
        $customer = $this->customer('Acme');

        // Half a millieme short — that's float noise, not a debt.
        $rounded = $this->sale(100, 99.998, null, $customer);

        $this->assertNotContains($rounded->id, array_column($this->openInvoices()['data'], 'id'));
    }

    public function test_the_balance_shown_is_the_amount_still_owed(): void
    {
        $customer = $this->customer('Acme');
        $sale     = $this->sale(250, 90, null, $customer);

        $row = collect($this->openInvoices()['data'])->firstWhere('id', $sale->id);

        // JSON numbers carry no int/float distinction, and these are
        // money — compare by value within a cent.
        $this->assertEqualsWithDelta(160.0, $row['balance'], 0.001);
        $this->assertEqualsWithDelta(250.0, $row['amount'], 0.001);
    }

    public function test_the_most_urgent_invoices_come_first_and_undated_ones_last(): void
    {
        $customer = $this->customer('Acme');

        $this->sale(100, 0, null, $customer);                                  // no due date
        $later = $this->sale(100, 0, now()->addDays(20)->toDateString(), $customer);
        $soon  = $this->sale(100, 0, now()->addDays(2)->toDateString(), $customer);

        $ids = array_column($this->openInvoices()['data'], 'id');

        $this->assertSame($soon->id, $ids[0], 'The soonest due invoice should lead the list.');
        $this->assertSame($later->id, $ids[1]);
    }

    public function test_another_companys_invoices_are_never_listed(): void
    {
        $this->sale(100, 0, null, $this->customer('Mine'));

        $otherCompany  = Company::factory()->create();
        $otherCustomer = Customer::create(['name' => 'Theirs', 'company_id' => $otherCompany->id]);
        $theirSale     = Sale::create([
            'company_id' => $otherCompany->id, 'customer_id' => $otherCustomer->id,
            'date' => now()->toDateString(), 'subtotal' => 500, 'vat_rate' => 0,
            'vat_amount' => 0, 'amount' => 500,
        ]);

        $ids = array_column($this->openInvoices()['data'], 'id');

        $this->assertNotContains($theirSale->id, $ids);
        $this->assertCount(1, $ids);
    }

    // ── Capping and search ───────────────────────────────────────

    public function test_the_list_is_capped_and_says_so(): void
    {
        $customer = $this->customer('Acme');

        for ($i = 0; $i < 55; $i++) {
            $this->sale(100, 0, now()->addDays($i)->toDateString(), $customer);
        }

        $body = $this->openInvoices();

        $this->assertCount(50, $body['data'], 'The worklist should return one capped page.');
        $this->assertTrue($body['has_more'], 'has_more must tell the frontend there is more to find.');
    }

    public function test_a_short_list_does_not_claim_there_is_more(): void
    {
        $this->sale(100, 0, null, $this->customer('Acme'));

        $this->assertFalse($this->openInvoices()['has_more']);
    }

    public function test_invoices_can_be_searched_by_customer_name(): void
    {
        $this->sale(100, 0, null, $this->customer('Northwind Trading'));
        $this->sale(100, 0, null, $this->customer('Southgate Supplies'));

        $this->assertCount(1, $this->openInvoices(['q' => 'Northwind'])['data']);
        $this->assertCount(0, $this->openInvoices(['q' => 'nothing-matches'])['data']);
        $this->assertCount(2, $this->openInvoices()['data']);
    }

    // ── Cost ─────────────────────────────────────────────────────

    /**
     * SOLVED (QA audit, Sep 2026): the diagnostic added earlier
     * caught it on the next run. Comparing the two query lists, the
     * first three queries are IDENTICAL in both — that's the real
     * openInvoices() cost, and it doesn't grow with the table, which
     * is exactly what this test set out to prove. The extra 3
     * queries that only showed up on the 5-row call were
     * PostDueDepreciation — the middleware that catches up a
     * company's depreciation once on the first page it visits each
     * day (see bootstrap/app.php). Both calls in this test land on
     * the same simulated "day", so whichever one runs FIRST
     * triggers that one-time catch-up and the second one correctly
     * skips it — nothing to do with the number of invoices at all,
     * just an accident of call order.
     *
     * Fixed by spending that one-time cost on an unrelated request
     * before measuring either call, so both measured calls land
     * after the day is already marked checked and reflect only what
     * the invoices endpoint itself actually costs.
     */
    public function test_the_query_count_does_not_grow_with_the_number_of_invoices(): void
    {
        $customer = $this->customer('Acme');

        // Spend PostDueDepreciation's once-a-day catch-up here, on a
        // throwaway request, so it can't land on whichever of the
        // two measured calls below happens to go first.
        $this->actingAs($this->user)->get(route('app.dashboard'));

        for ($i = 0; $i < 5; $i++) {
            $this->sale(100, 0, null, $customer);
        }

        [$small, $smallQueries] = $this->countQueriesForOpenInvoices();

        for ($i = 0; $i < 60; $i++) {
            $this->sale(100, 0, null, $customer);
        }

        [$large, $largeQueries] = $this->countQueriesForOpenInvoices();

        if ($small !== $large) {
            $describe = fn (array $log) => implode("\n", array_map(
                fn ($q) => '  '.$q['query'].' '.json_encode($q['bindings']),
                $log
            ));

            $this->fail(
                "Listing open invoices cost {$small} queries for 5 rows but {$large} for 65.\n\n".
                "--- Queries with 5 rows ---\n".$describe($smallQueries)."\n\n".
                "--- Queries with 65 rows ---\n".$describe($largeQueries)
            );
        }

        $this->assertSame($small, $large);
    }

    /**
     * @return array{0: int, 1: array}
     */
    private function countQueriesForOpenInvoices(): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($this->user)->getJson(route('app.payments.open-invoices'))->assertOk();

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return [count($log), $log];
    }

    // ── Bills, which merge three tables ──────────────────────────

    public function test_open_bills_merge_every_source_and_drop_settled_ones(): void
    {
        $vendor   = Vendor::create(['name' => 'Supplier', 'company_id' => $this->company->id]);
        $category = \App\Models\Category::create([
            'name' => 'Rent', 'kind' => 'expense', 'company_id' => $this->company->id,
        ]);

        $open = Expense::create([
            'company_id' => $this->company->id, 'vendor_id' => $vendor->id,
            'category_id' => $category->id, 'date' => now()->toDateString(),
            'amount' => 300, 'due_date' => now()->addDay()->toDateString(),
        ]);

        $settled = Expense::create([
            'company_id' => $this->company->id, 'vendor_id' => $vendor->id,
            'category_id' => $category->id, 'date' => now()->toDateString(),
            'amount' => 100, 'due_date' => now()->addDays(5)->toDateString(),
        ]);
        $settled->payments()->create([
            'company_id' => $this->company->id, 'date' => now()->toDateString(),
            'amount' => 100, 'direction' => 'out', 'method' => 'cash',
        ]);

        $body = $this->actingAs($this->user)
            ->getJson(route('app.payments.open-bills'))
            ->assertOk()
            ->json();

        $ids = array_column($body['data'], 'id');

        $this->assertContains($open->id, $ids);
        $this->assertNotContains($settled->id, $ids);
        $this->assertSame('expense', $body['data'][0]['payable_type']);
        $this->assertEqualsWithDelta(300.0, $body['data'][0]['balance'], 0.001);
    }
}
