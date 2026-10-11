<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Payment;
use App\Models\User;
use App\Services\CustomerCreditService;
use App\Services\JournalService;
use App\Services\Reports\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  Beginning Cash & Banks  +  Cash in  -  Cash out  =  Current Cash & Banks
//
//  The Cash Flow report opens with the money held on the From date
//  and closes with the money held on the To date; the Dashboard
//  shows the same opening figure for its period. All methods count
//  (cash, bank, cards, wallets), customer credit never does, and an
//  opening balance is a starting position — never "cash in".
// ══════════════════════════════════════════════════════════════════
class BeginningCashTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->user    = User::factory()->companyAdmin($this->company)->create();

        app(JournalService::class)->seedChartOfAccounts($this->company);
        Category::seedDefaults($this->company->id);

        $this->actingAs($this->user);
    }

    private function pay(string $date, float $amount, string $direction, string $method = 'cash', bool $opening = false): void
    {
        Payment::create([
            'company_id' => $this->company->id, 'date' => $date, 'amount' => $amount,
            'direction' => $direction, 'method' => $method, 'is_opening_balance' => $opening,
        ]);
    }

    private function cashFlow(string $from, string $to): array
    {
        return app(ReportDataService::class)->cashFlow($from, $to);
    }

    public function test_beginning_is_everything_before_the_from_date_across_all_methods(): void
    {
        $this->pay('2026-07-01', 5000, 'in', 'cash', true);   // opening balance, cash
        $this->pay('2026-07-01', 12000, 'in', 'bank', true);  // opening balance, bank
        $this->pay('2026-08-15', 3000, 'in', 'cash');
        $this->pay('2026-08-20', 1000, 'out', 'bank');
        $this->pay('2026-08-25', 777, 'in', CustomerCreditService::METHOD); // moved no money
        $this->pay('2026-09-05', 500, 'in', 'wallet');
        $this->pay('2026-09-10', 200, 'out', 'bank');

        $report = $this->cashFlow('2026-09-01', '2026-09-30');

        $this->assertEquals(19000.0, $report['beginning_cash'], '17,000 opening + 3,000 - 1,000');
        $this->assertEquals(500.0, $report['cash_in']);
        $this->assertEquals(200.0, $report['cash_out']);
        $this->assertEquals(19300.0, $report['current_cash']);
    }

    public function test_the_equation_always_holds(): void
    {
        $this->pay('2026-06-01', 8000, 'in', 'bank', true);
        $this->pay('2026-08-02', 1234.56, 'in', 'visa');
        $this->pay('2026-09-03', 400.25, 'out', 'cash');
        $this->pay('2026-09-20', 99.99, 'in', 'cash');

        $r = $this->cashFlow('2026-09-01', '2026-09-30');

        $this->assertEquals(
            round($r['beginning_cash'] + $r['cash_in'] - $r['cash_out'], 2),
            $r['current_cash']
        );
    }

    public function test_an_opening_balance_inside_the_period_counts_as_beginning_not_cash_in(): void
    {
        $this->pay('2026-09-01', 4000, 'in', 'cash', true);
        $this->pay('2026-09-05', 100, 'in', 'cash');

        $r = $this->cashFlow('2026-09-01', '2026-09-30');

        $this->assertEquals(4000.0, $r['beginning_cash']);
        $this->assertEquals(100.0, $r['cash_in'], 'the opening balance is not income of the period');
        $this->assertEquals(4100.0, $r['current_cash']);
    }

    public function test_an_opening_balance_after_the_period_is_not_counted(): void
    {
        $this->pay('2026-10-01', 4000, 'in', 'cash', true);

        $r = $this->cashFlow('2026-09-01', '2026-09-30');

        $this->assertEquals(0.0, $r['beginning_cash']);
        $this->assertEquals(0.0, $r['current_cash']);
    }

    public function test_current_cash_to_today_equals_the_dashboard_cash_balance(): void
    {
        $this->pay('2026-01-02', 5000, 'in', 'bank', true);
        $this->pay('2026-03-10', 700, 'in', 'cash');
        $this->pay('2026-04-11', 250, 'out', 'wallet');

        $r = $this->cashFlow('2026-02-01', now()->toDateString());

        $dashboard = $this->actingAs($this->user)->get(route('app.dashboard'))->viewData('page')['props'];

        $this->assertEquals($dashboard['cash_balance'], $r['current_cash']);
    }

    public function test_the_dashboard_beginning_card_follows_its_period(): void
    {
        $yearStart = now()->startOfYear();

        $this->pay($yearStart->copy()->subDays(10)->toDateString(), 1000, 'in', 'cash');  // last year
        $this->pay($yearStart->copy()->subDays(5)->toDateString(), 300, 'out', 'bank');   // last year
        $this->pay($yearStart->toDateString(), 50, 'in', 'cash');                          // this year

        $props = $this->get(route('app.dashboard', ['period' => 'year']))->viewData('page')['props'];

        $this->assertEquals(700.0, $props['beginning_cash'], '1,000 - 300 held when the year began');
        $this->assertEquals(50.0, $props['total_cash_in']);
        $this->assertEquals(750.0, $props['cash_balance']);
    }

    public function test_another_companys_cash_never_leaks_in(): void
    {
        $other = Company::factory()->create();
        Payment::create([
            'company_id' => $other->id, 'date' => '2026-01-05', 'amount' => 999999,
            'direction' => 'in', 'method' => 'cash',
        ]);
        $this->pay('2026-01-05', 10, 'in', 'cash');

        $this->assertEquals(10.0, $this->cashFlow('2026-02-01', '2026-02-28')['beginning_cash']);
    }

    public function test_the_page_and_the_exports_carry_the_new_cards(): void
    {
        $this->pay('2026-08-01', 1000, 'in', 'cash');

        $props = $this->get(route('app.reports.cash-flow', ['from' => '2026-09-01', 'to' => '2026-09-30']))
            ->viewData('page')['props'];

        $this->assertEquals(1000.0, $props['beginning_cash']);
        $this->assertEquals(1000.0, $props['current_cash']);

        foreach (['excel', 'pdf'] as $format) {
            $this->get(route('app.reports.cash-flow.export', ['format' => $format, 'from' => '2026-09-01', 'to' => '2026-09-30']))
                ->assertOk();
        }
    }
}
