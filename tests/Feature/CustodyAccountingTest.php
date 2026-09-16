<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Custody;
use App\Models\Payment;
use App\Models\User;
use App\Models\Vendor;
use App\Services\JournalService;
use App\Services\Reports\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  Custody — a float handed to an employee, and squaring it up.
//
//  Two separate problems lived here, and both showed up as figures
//  in the Profit & Loss that described nothing real:
//
//  1. The settlement date was hard-coded to today. The form had no
//     field for it at all. A float handed out in July and squared up
//     in July, but keyed in during September, left the hand-out in
//     July and the returned change in September — one event split
//     across two months.
//
//  2. The whole hand-out counted as an expense the day it left the
//     till, and the unspent change counted as INCOME the day it came
//     back. Handing an employee 10,000 is not spending 10,000; the
//     company still owns it. And getting change back is not revenue.
//     The real expense is what the holder reports they spent.
//
//  What the P&L now counts is the settlement lines, on the
//  settlement date. Cash Flow still shows the raw movements, because
//  the cash genuinely did move and that is the question it answers.
// ══════════════════════════════════════════════════════════════════
class CustodyAccountingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Vendor $holder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->admin   = User::factory()->companyAdmin($this->company)->create();

        app(JournalService::class)->seedChartOfAccounts($this->company);
        Category::seedDefaults($this->company->id);

        $this->actingAs($this->admin);

        $this->holder = Vendor::create(['company_id' => $this->company->id, 'name' => 'Ahmed']);
    }

    private function expenseCategory(string $name = null): Category
    {
        $query = Category::query()->where('company_id', $this->company->id)->where('kind', 'expense');

        return $name ? $query->where('name', $name)->firstOrFail() : $query->firstOrFail();
    }

    private function handOut(float $amount = 10000, string $on = '2026-07-01'): Custody
    {
        $this->post('/app/custodies', [
            'holder_id' => $this->holder->id,
            'amount'    => $amount,
            'method'    => 'cash',
            'given_at'  => $on,
        ])->assertSessionHasNoErrors();

        return Custody::query()->latest('id')->firstOrFail();
    }

    private function settle(Custody $custody, array $lines, string $on): \Illuminate\Testing\TestResponse
    {
        return $this->patch("/app/custodies/{$custody->id}/settle", [
            'settlement_date' => $on,
            'lines'           => $lines,
        ]);
    }

    private function pl(string $from, string $to): array
    {
        return app(ReportDataService::class)->profitAndLoss($from, $to);
    }

    // ── The settlement date is the user's, not today's ───────────

    public function test_the_settlement_date_is_taken_from_the_form(): void
    {
        $custody = $this->handOut(10000, '2026-07-01');

        $this->settle($custody, [['category_id' => $this->expenseCategory()->id, 'amount' => 8000]], '2026-07-20')
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-07-20', $custody->fresh()->settlement_date->toDateString());
    }

    public function test_the_returned_change_is_dated_the_settlement_not_today(): void
    {
        $custody = $this->handOut(10000, '2026-07-01');

        $this->settle($custody, [['category_id' => $this->expenseCategory()->id, 'amount' => 8000]], '2026-07-20')
            ->assertSessionHasNoErrors();

        $returned = Payment::query()->where('direction', 'in')->firstOrFail();

        $this->assertEquals(2000.0, (float) $returned->amount);
        $this->assertSame('2026-07-20', $returned->date->toDateString());
    }

    public function test_extra_reimbursed_is_dated_the_settlement_too(): void
    {
        $custody = $this->handOut(10000, '2026-07-01');

        $this->settle($custody, [['category_id' => $this->expenseCategory()->id, 'amount' => 11500]], '2026-07-20')
            ->assertSessionHasNoErrors();

        $reimbursement = Payment::query()
            ->where('direction', 'out')
            ->where('amount', 1500)
            ->firstOrFail();

        $this->assertSame('2026-07-20', $reimbursement->date->toDateString());
    }

    public function test_the_settlement_date_is_required(): void
    {
        $custody = $this->handOut();

        $this->patch("/app/custodies/{$custody->id}/settle", [
            'lines' => [['category_id' => $this->expenseCategory()->id, 'amount' => 8000]],
        ])->assertSessionHasErrors('settlement_date');

        $this->assertFalse($custody->fresh()->settled);
    }

    public function test_a_custody_cannot_be_settled_before_it_was_handed_out(): void
    {
        $custody = $this->handOut(10000, '2026-07-01');

        $this->settle($custody, [['category_id' => $this->expenseCategory()->id, 'amount' => 8000]], '2026-06-15')
            ->assertSessionHasErrors('settlement_date');
    }

    public function test_a_settlement_dated_far_in_the_future_is_refused(): void
    {
        $custody = $this->handOut(10000, '2026-07-01');

        $this->settle($custody, [['category_id' => $this->expenseCategory()->id, 'amount' => 8000]], '2099-01-01')
            ->assertSessionHasErrors('settlement_date');
    }

    // ── What the P&L counts ──────────────────────────────────────

    public function test_handing_out_a_float_is_not_an_expense(): void
    {
        $this->handOut(10000, '2026-07-01');

        $july = $this->pl('2026-07-01', '2026-07-31');

        $this->assertEquals(0.0, $july['expenses_paid'], 'The float is still the company\'s own money');
        $this->assertEquals(0.0, $july['net_profit']);
    }

    public function test_the_expense_appears_when_the_float_is_squared_up(): void
    {
        $custody = $this->handOut(10000, '2026-07-01');

        $this->settle($custody, [['category_id' => $this->expenseCategory()->id, 'amount' => 8000]], '2026-08-10')
            ->assertSessionHasNoErrors();

        $this->assertEquals(0.0, $this->pl('2026-07-01', '2026-07-31')['expenses_paid'], 'July: handed out, nothing known yet');
        $this->assertEquals(8000.0, $this->pl('2026-08-01', '2026-08-31')['expenses_paid'], 'August: what was actually spent');
    }

    public function test_returned_change_is_never_counted_as_income(): void
    {
        $custody = $this->handOut(10000, '2026-07-01');

        $this->settle($custody, [['category_id' => $this->expenseCategory()->id, 'amount' => 8000]], '2026-07-20')
            ->assertSessionHasNoErrors();

        $july = $this->pl('2026-07-01', '2026-07-31');

        $this->assertEquals(0.0, $july['income_received'], 'Getting change back is not revenue');
        $this->assertEquals(8000.0, $july['expenses_paid']);
        $this->assertEquals(-8000.0, $july['net_profit']);
    }

    public function test_spending_more_than_the_float_still_counts_the_whole_spend(): void
    {
        $custody = $this->handOut(10000, '2026-07-01');

        $this->settle($custody, [['category_id' => $this->expenseCategory()->id, 'amount' => 11500]], '2026-07-20')
            ->assertSessionHasNoErrors();

        $july = $this->pl('2026-07-01', '2026-07-31');

        $this->assertEquals(11500.0, $july['expenses_paid']);
        $this->assertEquals(0.0, $july['income_received']);
    }

    public function test_a_float_still_outstanding_contributes_nothing(): void
    {
        $this->handOut(10000, '2026-07-01');

        foreach ([['2026-07-01', '2026-07-31'], ['2026-08-01', '2026-08-31']] as [$from, $to]) {
            $pl = $this->pl($from, $to);
            $this->assertEquals(0.0, $pl['expenses_paid']);
            $this->assertEquals(0.0, $pl['income_received']);
        }
    }

    public function test_the_spend_shows_under_its_own_category(): void
    {
        $custody  = $this->handOut(10000, '2026-07-01');
        $category = $this->expenseCategory();

        $this->settle($custody, [['category_id' => $category->id, 'amount' => 8000]], '2026-07-20')
            ->assertSessionHasNoErrors();

        $breakdown = collect($this->pl('2026-07-01', '2026-07-31')['expenses_by_category']);

        $this->assertEquals(8000.0, $breakdown->firstWhere('category', $category->name)?->total);
    }

    /**
     * Custody no longer breaks the agreement between the P&L's
     * headline and its category breakdown.
     *
     * Read the name precisely: this proves custody is no longer a
     * SOURCE of that disagreement. The separate, older problem —
     * stock and equipment counted in the headline and not in the
     * breakdown — has since been closed too, and
     * ReportConsistencyTest asserts the two halves match in a
     * scenario that actually contains both.
     */
    public function test_custody_no_longer_splits_the_headline_from_the_breakdown(): void
    {
        $custody = $this->handOut(10000, '2026-07-01');

        $this->settle($custody, [['category_id' => $this->expenseCategory()->id, 'amount' => 8000]], '2026-07-20')
            ->assertSessionHasNoErrors();

        $this->post('/app/expenses', [
            'vendor_id'   => $this->holder->id,
            'category_id' => $this->expenseCategory()->id,
            'date'        => '2026-07-05',
            'amount'      => 1200,
            'mode'        => 'now',
            'method'      => 'cash',
        ])->assertSessionHasNoErrors();

        $pl = $this->pl('2026-07-01', '2026-07-31');

        // 8,000 of float actually spent + 1,200 of rent, and the
        // 10,000 hand-out counted in neither.
        $this->assertEquals(9200.00, $pl['expenses_paid']);

        $this->assertEquals(
            $pl['expenses_paid'],
            round(collect($pl['expenses_by_category'])->sum('total'), 2),
            'With no stock or equipment in play, the two halves must match exactly'
        );
    }

    /**
     * Cash Flow answers a different question and must keep showing
     * the movements the P&L now leaves out.
     */
    public function test_cash_flow_still_shows_the_float_leaving_and_returning(): void
    {
        $custody = $this->handOut(10000, '2026-07-01');

        $this->settle($custody, [['category_id' => $this->expenseCategory()->id, 'amount' => 8000]], '2026-07-20')
            ->assertSessionHasNoErrors();

        $cashFlow = app(ReportDataService::class)->cashFlow('2026-07-01', '2026-07-31');

        $this->assertEquals(10000.0, $cashFlow['cash_out'], 'The float really did leave the till');
        $this->assertEquals(2000.0, $cashFlow['cash_in'], 'and the change really did come back');
    }

    /**
     * The ledger entry has to commit with the settlement, not after
     * it — it used to sit outside the transaction.
     */
    public function test_settling_posts_a_balanced_ledger_entry(): void
    {
        $custody = $this->handOut(10000, '2026-07-01');

        $this->settle($custody, [['category_id' => $this->expenseCategory()->id, 'amount' => 8000]], '2026-07-20')
            ->assertSessionHasNoErrors();

        $entry = \App\Models\JournalEntry::query()->where('memo', 'Custody settled')->firstOrFail();

        $this->assertTrue($entry->isBalanced());
        $this->assertSame('2026-07-20', $entry->date->toDateString());
    }
}
