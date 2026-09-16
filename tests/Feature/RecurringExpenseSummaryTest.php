<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Expense;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  QA audit (Sep 2026), Finding 5: the "Recurring plans" summary on
//  the Expenses page used to load every occurrence of every
//  recurring series a company had ever had into PHP memory just to
//  group and count them. Rewritten to aggregate in SQL instead (see
//  ExpenseController::recurringSeriesSummary()). This test protects
//  two things:
//
//    • The numbers it reports are still correct — rewriting how a
//      figure is computed is exactly the kind of change that can
//      quietly change what it says.
//    • The cost is now flat — the whole point of the fix — proven
//      the same way DashboardTest does it: run the page with a
//      little data, then with a lot, and the query count must not
//      have grown.
// ══════════════════════════════════════════════════════════════════
class RecurringExpenseSummaryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Vendor $vendor;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company  = Company::factory()->create();
        $this->user     = User::factory()->companyAdmin($this->company)->create();
        $this->vendor   = Vendor::create(['name' => 'Landlord Co', 'company_id' => $this->company->id]);
        $this->category = Category::create(['name' => 'Rent', 'kind' => 'expense', 'company_id' => $this->company->id]);
    }

    /**
     * @param  array<int, bool>  $paidFlags  one entry per occurrence,
     *         true = record a full payment against it.
     */
    private function makeSeries(int $companyId, array $paidFlags, float $amount = 1000): string
    {
        $recurringId = (string) Str::uuid();
        $count       = count($paidFlags);

        foreach ($paidFlags as $index => $paid) {
            $occurrenceNumber = $index + 1;
            $date             = now()->addMonths($index)->toDateString();

            $expense = Expense::create([
                'company_id'          => $companyId,
                'vendor_id'           => $this->vendor->id,
                'category_id'         => $this->category->id,
                'date'                => $date,
                'due_date'            => $date,
                'amount'              => $amount,
                'recurring_id'        => $recurringId,
                'recurring_index'     => $occurrenceNumber,
                'recurring_count'     => $count,
                'recurring_frequency' => 'monthly',
            ]);

            if ($paid) {
                $expense->payments()->create([
                    'company_id' => $companyId,
                    'date'       => $date,
                    'amount'     => $amount,
                    'direction'  => 'out',
                    'method'     => 'cash',
                ]);
            }
        }

        return $recurringId;
    }

    private function inertiaProps(): array
    {
        $page = null;

        $this->actingAs($this->user)
            ->get(route('app.expenses.index'))
            ->assertInertia(function ($inertia) use (&$page) {
                $page = $inertia;
            });

        return $page->toArray()['props'];
    }

    public function test_the_summary_counts_paid_and_unpaid_occurrences_correctly(): void
    {
        // 4 occurrences: first two paid, last two unpaid.
        $this->makeSeries($this->company->id, [true, true, false, false], amount: 750);

        $series = collect($this->inertiaProps()['recurringSeries'])->sole();

        $this->assertSame('Landlord Co', $series['vendor']);
        $this->assertSame('Rent', $series['category']);
        $this->assertEqualsWithDelta(750.0, $series['amount'], 0.001,
            'Amount should come from the FIRST occurrence, not any other.');
        $this->assertSame('monthly', $series['frequency']);
        $this->assertSame(4, $series['total_count']);
        $this->assertSame(2, $series['paid_count']);
        $this->assertTrue($series['has_unpaid']);
    }

    public function test_a_fully_paid_series_reports_no_unpaid_and_no_next_due(): void
    {
        $this->makeSeries($this->company->id, [true, true, true]);

        $series = collect($this->inertiaProps()['recurringSeries'])->sole();

        $this->assertSame(3, $series['paid_count']);
        $this->assertSame(3, $series['total_count']);
        $this->assertFalse($series['has_unpaid']);
        $this->assertNull($series['next_due']);
    }

    public function test_next_due_is_the_soonest_unpaid_occurrences_own_due_date(): void
    {
        // Occurrence 1 is paid; 2 and 3 are unpaid, 2 is due first.
        $this->makeSeries($this->company->id, [true, false, false]);

        $series = collect($this->inertiaProps()['recurringSeries'])->sole();

        $expectedDueDate = now()->addMonths(1)->toDateString(); // occurrence #2

        $this->assertSame($expectedDueDate, $series['next_due']);
    }

    public function test_another_companys_recurring_expenses_never_appear(): void
    {
        $this->makeSeries($this->company->id, [false, false]);

        $other        = Company::factory()->create();
        $otherVendor   = Vendor::create(['name' => 'Theirs', 'company_id' => $other->id]);
        $otherCategory = Category::create(['name' => 'Rent', 'kind' => 'expense', 'company_id' => $other->id]);

        $otherRecurringId = (string) Str::uuid();
        Expense::create([
            'company_id' => $other->id, 'vendor_id' => $otherVendor->id, 'category_id' => $otherCategory->id,
            'date' => now()->toDateString(), 'amount' => 999999,
            'recurring_id' => $otherRecurringId, 'recurring_index' => 1,
            'recurring_count' => 1, 'recurring_frequency' => 'monthly',
        ]);

        $series = collect($this->inertiaProps()['recurringSeries']);

        $this->assertCount(1, $series, 'Only my own company\'s series should appear.');
        $this->assertFalse($series->pluck('vendor')->contains('Theirs'));
    }

    /**
     * The whole point of the fix: cost must not grow with the number
     * of series or occurrences. Same technique as
     * DashboardTest::test_the_query_count_does_not_grow_with_the_data().
     */
    public function test_the_query_count_does_not_grow_with_the_number_of_series(): void
    {
        $this->makeSeries($this->company->id, [true, false]);
        $this->actingAs($this->user)->get(route('app.expenses.index'))->assertOk(); // prime

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->user)->get(route('app.expenses.index'))->assertOk();
        $small = count(DB::getQueryLog());
        DB::disableQueryLog();

        // 25 more independent recurring series, several occurrences each.
        for ($i = 0; $i < 25; $i++) {
            $this->makeSeries($this->company->id, [true, false, false]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->user)->get(route('app.expenses.index'))->assertOk();
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $small,
            $large,
            "The recurring-expense summary cost {$small} queries with 1 series and {$large} with 26 — it is scaling with the data again."
        );
    }
}
