<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  List screens must cost the same whether they show one row or a
//  full page of them.
//
//  They didn't. The controllers eager loaded `payments` correctly,
//  but paidAmount() read through the relation's query builder
//  ($this->payments()->sum()) instead of the loaded collection — so
//  the eager load was wasted and each row issued its own SUM. And
//  because balance() calls paidAmount() and isPaid() calls
//  balance(), that was three queries per row: 60 wasted queries on a
//  20-row page.
// ══════════════════════════════════════════════════════════════════
class IndexQueryEfficiencyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company  = Company::factory()->create();
        $this->user     = User::factory()->companyAdmin($this->company)->create();
        $this->customer = Customer::create(['name' => 'Acme', 'company_id' => $this->company->id]);
    }

    private function makeSales(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $sale = Sale::create([
                'company_id'  => $this->company->id,
                'customer_id' => $this->customer->id,
                'date'        => now()->toDateString(),
                'subtotal'    => 100, 'vat_rate' => 0, 'vat_amount' => 0, 'amount' => 100,
                'created_by'  => $this->user->id,
            ]);

            $sale->payments()->create([
                'company_id' => $this->company->id,
                'date'       => now()->toDateString(),
                'amount'     => 50, 'direction' => 'in', 'method' => 'cash',
            ]);
        }
    }

    private function countQueries(string $routeName): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($this->user)->get(route($routeName))->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_the_sales_list_costs_the_same_for_one_row_as_for_twenty(): void
    {
        $this->makeSales(1);

        // Prime first: the very first request of a session also seeds
        // the company's default categories and chart of accounts, and
        // those one-time writes would otherwise be counted as if they
        // were per-row cost.
        $this->countQueries('app.sales.index');

        $one = $this->countQueries('app.sales.index');

        $this->makeSales(19);
        $twenty = $this->countQueries('app.sales.index');

        $this->assertSame(
            $one,
            $twenty,
            "The sales list used {$one} queries for 1 sale and {$twenty} for 20 — it is querying per row."
        );
    }

    public function test_no_per_row_sum_queries_are_issued_for_a_full_page(): void
    {
        $this->makeSales(20);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->user)->get(route('app.sales.index'))->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $sums = array_filter(
            $log,
            fn (array $query) => str_contains(strtolower($query['query']), 'sum(`amount`)')
                || str_contains(strtolower($query['query']), 'sum("amount")')
        );

        $this->assertCount(
            0,
            $sums,
            'paidAmount() is going back to the database per row instead of using the eager-loaded payments.'
        );
    }

    public function test_the_totals_are_still_correct_when_read_from_the_loaded_relation(): void
    {
        $this->makeSales(1);

        $sale = Sale::with('payments')->sole();

        $this->assertEqualsWithDelta(50.0, $sale->paidAmount(), 0.001);
        $this->assertEqualsWithDelta(50.0, $sale->balance(), 0.001);
        $this->assertFalse($sale->isPaid());
    }

    public function test_the_totals_are_still_correct_without_the_relation_loaded(): void
    {
        $this->makeSales(1);

        // Same answers when nothing was eager loaded — the fallback
        // path still has to work for single-record flows.
        $sale = Sale::sole();

        $this->assertFalse($sale->relationLoaded('payments'));
        $this->assertEqualsWithDelta(50.0, $sale->paidAmount(), 0.001);
        $this->assertEqualsWithDelta(50.0, $sale->balance(), 0.001);
    }

    public function test_a_freshly_settled_record_reports_itself_paid(): void
    {
        $this->makeSales(1);

        $sale = Sale::sole();
        $sale->payments()->create([
            'company_id' => $this->company->id, 'date' => now()->toDateString(),
            'amount' => 50, 'direction' => 'in', 'method' => 'cash',
        ]);

        $this->assertTrue(Sale::with('payments')->sole()->isPaid());
    }
}
