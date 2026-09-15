<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Sale;
use App\Models\User;
use App\Services\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  Correcting an old record must not disturb the period it was in.
//
//  JournalService::reverse() used to date every reversal `now()`.
//  Correcting a March invoice therefore left three entries: the
//  original in March, the reversal in September, and the corrected
//  one back in March again — so March reported the sum of the old
//  figure AND the new one. Every closed month silently drifted
//  upward each time somebody fixed a typo, and nothing about the
//  screen gave any sign of it.
//
//  The reversal now carries its original's date, so the pair cancels
//  inside the period it belongs to. These tests pin that down from
//  both ends: the entry dates themselves, and the account totals a
//  report for that month would actually read.
// ══════════════════════════════════════════════════════════════════
class JournalReversalDateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->admin   = User::factory()->companyAdmin($this->company)->create();

        app(JournalService::class)->seedChartOfAccounts($this->company);
        Category::seedDefaults($this->company->id);

        $this->actingAs($this->admin);

        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'name'       => 'Acme',
        ]);
    }

    /**
     * Net movement on one account within a date window — i.e. what a
     * report for that period would show.
     */
    private function movementBetween(string $code, string $from, string $to): float
    {
        $account = Account::query()
            ->where('company_id', $this->company->id)
            ->where('code', $code)
            ->firstOrFail();

        return round(
            JournalLine::query()
                ->where('account_id', $account->id)
                ->whereHas('journalEntry', fn ($q) => $q->whereBetween('date', [$from, $to]))
                ->get()
                ->sum(fn (JournalLine $line) => (float) $line->debit - (float) $line->credit),
            2
        );
    }

    private function createSale(string $date, float $unitPrice): Sale
    {
        $this->post('/app/sales', [
            'customer_id' => $this->customer->id,
            'date'        => $date,
            'lines'       => [['item_id' => null, 'qty' => 1, 'unit_price' => $unitPrice]],
            'vat_rate'    => 0,
            'mode'        => 'later',
        ])->assertSessionHasNoErrors();

        return Sale::query()->latest('id')->firstOrFail();
    }

    private function editSale(Sale $sale, string $date, float $unitPrice): void
    {
        $this->put("/app/sales/{$sale->id}", [
            'customer_id' => $this->customer->id,
            'date'        => $date,
            'lines'       => [['item_id' => null, 'qty' => 1, 'unit_price' => $unitPrice]],
            'vat_rate'    => 0,
        ])->assertSessionHasNoErrors();
    }

    public function test_a_reversal_carries_the_date_of_the_entry_it_reverses(): void
    {
        $sale = $this->createSale('2026-03-10', 1000);

        $original = JournalEntry::query()->where('memo', 'Sale invoiced')->firstOrFail();

        $this->editSale($sale, '2026-03-10', 1200);

        $reversal = JournalEntry::query()->where('reverses_id', $original->id)->firstOrFail();

        $this->assertSame(
            $original->date->toDateString(),
            $reversal->date->toDateString(),
            'A reversal belongs in the same period as the entry it cancels'
        );
    }

    public function test_correcting_a_sale_leaves_its_month_showing_only_the_corrected_figure(): void
    {
        $sale = $this->createSale('2026-03-10', 1000);

        $this->assertEquals(-1000.0, $this->movementBetween(Account::SALES_REVENUE, '2026-03-01', '2026-03-31'));

        $this->editSale($sale, '2026-03-10', 1200);

        // Revenue is a credit balance, so it reads negative here.
        $this->assertEquals(
            -1200.0,
            $this->movementBetween(Account::SALES_REVENUE, '2026-03-01', '2026-03-31'),
            'March should report the corrected 1,200 — not 1,000 + 1,200'
        );
    }

    public function test_correcting_a_sale_leaves_no_trace_in_the_month_it_was_corrected_in(): void
    {
        $sale = $this->createSale('2026-03-10', 1000);
        $this->editSale($sale, '2026-03-10', 1200);

        $this->assertEquals(
            0.0,
            $this->movementBetween(Account::SALES_REVENUE, '2026-09-01', '2026-09-30'),
            'September had no sales — fixing a March typo must not put revenue there'
        );
    }

    /**
     * Moving a record to a different month is a different case from
     * correcting one in place: the old month must lose it entirely
     * and the new month must gain it, with nothing left in between.
     */
    public function test_moving_a_sale_to_another_month_empties_the_month_it_left(): void
    {
        $sale = $this->createSale('2026-03-10', 1000);

        $this->editSale($sale, '2026-05-10', 1000);

        $this->assertEquals(
            0.0,
            $this->movementBetween(Account::SALES_REVENUE, '2026-03-01', '2026-03-31'),
            'March no longer contains this sale'
        );

        $this->assertEquals(
            -1000.0,
            $this->movementBetween(Account::SALES_REVENUE, '2026-05-01', '2026-05-31'),
            'May now contains it'
        );
    }

    public function test_deleting_an_old_sale_empties_its_month(): void
    {
        $sale = $this->createSale('2026-03-10', 1000);

        $this->delete("/app/sales/{$sale->id}")->assertSessionHasNoErrors();

        $this->assertEquals(
            0.0,
            $this->movementBetween(Account::SALES_REVENUE, '2026-03-01', '2026-03-31'),
            'A deleted sale leaves nothing behind in its own month'
        );

        $this->assertEquals(
            0.0,
            $this->movementBetween(Account::SALES_REVENUE, '2026-09-01', '2026-09-30'),
            'and nothing in the month it was deleted in'
        );
    }

    /**
     * The ledger still has to balance overall — dating the reversal
     * differently must not have broken the invariant post() enforces.
     */
    public function test_every_entry_still_balances_after_a_correction(): void
    {
        $sale = $this->createSale('2026-03-10', 1000);
        $this->editSale($sale, '2026-03-10', 1200);

        JournalEntry::query()->get()->each(
            fn (JournalEntry $entry) => $this->assertTrue(
                $entry->isBalanced(),
                "Entry #{$entry->id} ({$entry->memo}) does not balance"
            )
        );
    }

    /**
     * created_at is what answers "when was this corrected" — the
     * audit trail must survive the date change.
     */
    public function test_the_reversal_still_records_when_it_was_actually_made(): void
    {
        $sale = $this->createSale('2026-03-10', 1000);
        $this->editSale($sale, '2026-03-10', 1200);

        $reversal = JournalEntry::query()->whereNotNull('reverses_id')->firstOrFail();

        $this->assertTrue(
            $reversal->created_at->isToday(),
            'The correction was made today, whatever period it was posted into'
        );
    }
}
