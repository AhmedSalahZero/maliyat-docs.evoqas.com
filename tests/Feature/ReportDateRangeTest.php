<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\User;
use App\Models\Vendor;
use App\Services\JournalService;
use App\Services\Reports\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  Every report takes a date range — and narrowing one must not make
//  it lie.
//
//  Three reports had no range at all: the customer statement, the
//  supplier statement and the inventory statement each returned all
//  of history with no way to ask about a period.
//
//  Adding one naively would have been worse than not having it. A
//  customer who owed 5,000 coming into March and paid 2,000 during
//  March would show a closing balance of -2,000 if the range simply
//  dropped everything before it. So everything before the window is
//  collapsed into a brought-forward figure and the window's
//  movements run on top of it — which is how a statement of account
//  is supposed to read.
//
//  Stock has the same trap in a different shape: a range must never
//  hide purchases made before it, or an item bought in January and
//  sold in March would look like it went negative. The closing
//  figure counts everything up to the END of the window; only the
//  movement columns are bounded by the start.
// ══════════════════════════════════════════════════════════════════
class ReportDateRangeTest extends TestCase
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

    private function reports(): ReportDataService
    {
        return app(ReportDataService::class);
    }

    private function sell(float $amount, string $on, ?Item $item = null, float $qty = 1): void
    {
        $this->post('/app/sales', [
            'customer_id' => $this->customer->id,
            'date'        => $on,
            'lines'       => [[
                'item_id'    => $item?->id,
                'qty'        => $qty,
                'unit_price' => $item ? round($amount / $qty, 2) : $amount,
            ]],
            'vat_rate' => 0,
            'mode'     => 'later',
        ])->assertSessionHasNoErrors();

        Cache::flush();
    }

    private function receive(float $amount, string $on): void
    {
        $this->post('/app/payments/receive', [
            'customer_id' => $this->customer->id,
            'date'        => $on,
            'amount'      => $amount,
            'method'      => 'cash',
        ])->assertSessionHasNoErrors();

        Cache::flush();
    }

    private function buyStock(Item $item, float $qty, string $on): void
    {
        $this->post('/app/inventory-purchases', [
            'vendor_id' => $this->vendor->id,
            'date'      => $on,
            'lines'     => [['item_id' => $item->id, 'qty' => $qty, 'qty_per_uom' => 1, 'unit_price' => 10]],
            'vat_rate'  => 0,
            'mode'      => 'later',
        ])->assertSessionHasNoErrors();

        Cache::flush();
    }

    private function item(): Item
    {
        return Item::create([
            'company_id'     => $this->company->id,
            'name'           => 'Widget',
            'qty_per_uom'    => 1,
            'base_unit_name' => 'pc',
        ]);
    }

    // ── Every report page accepts a range ────────────────────────

    /**
     * @return array<string, array{0: string}>
     */
    public static function rangedReports(): array
    {
        return [
            'ledger'              => ['app.reports.ledger'],
            'profit & loss'       => ['app.reports.profit-loss'],
            'cash flow'           => ['app.reports.cash-flow'],
            'inventory statement' => ['app.reports.inventory-statement'],
            'customer statement'  => ['app.reports.customer-statement'],
            'supplier statement'  => ['app.reports.supplier-statement'],
            'external audit'      => ['app.reports.external-audit'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rangedReports')]
    public function test_the_report_accepts_a_date_range(string $routeName): void
    {
        $this->get(route($routeName, ['from' => '2026-03-01', 'to' => '2026-03-31']))
            ->assertOk();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rangedReports')]
    public function test_the_report_echoes_the_range_back_to_the_page(string $routeName): void
    {
        $props = $this->get(route($routeName, ['from' => '2026-03-01', 'to' => '2026-03-31']))
            ->viewData('page')['props'];

        $this->assertArrayHasKey('from', $props, "{$routeName} does not tell the page what range it used");
        $this->assertArrayHasKey('to', $props);
    }

    // ── Customer statement ───────────────────────────────────────

    public function test_a_statement_with_no_range_is_the_whole_history(): void
    {
        $this->sell(1000, '2026-01-10');
        $this->sell(500, '2026-03-10');

        $statement = $this->reports()->customerStatement($this->customer);

        $this->assertCount(2, $statement['entries']);
        $this->assertEquals(0.0, $statement['opening_balance']);
        $this->assertEquals(1500.0, $statement['balance']);
    }

    public function test_a_range_shows_only_that_periods_movements(): void
    {
        $this->sell(1000, '2026-01-10');
        $this->sell(500, '2026-03-10');

        $statement = $this->reports()->customerStatement($this->customer, '2026-03-01', '2026-03-31');

        $this->assertCount(1, $statement['entries']);
        $this->assertSame('2026-03-10', $statement['entries'][0]['date']);
    }

    /**
     * The whole reason the brought-forward figure exists.
     */
    public function test_the_closing_balance_still_includes_what_came_before(): void
    {
        $this->sell(5000, '2026-01-10');
        $this->receive(2000, '2026-03-15');

        $statement = $this->reports()->customerStatement($this->customer, '2026-03-01', '2026-03-31');

        $this->assertEquals(5000.0, $statement['opening_balance'], 'They owed 5,000 coming into March');
        $this->assertEquals(3000.0, $statement['balance'], 'and 3,000 leaving it — not -2,000');
    }

    public function test_the_running_balance_starts_from_the_brought_forward_figure(): void
    {
        $this->sell(5000, '2026-01-10');
        $this->receive(2000, '2026-03-15');

        $statement = $this->reports()->customerStatement($this->customer, '2026-03-01', '2026-03-31');

        $this->assertEquals(3000.0, $statement['entries'][0]['running_balance']);
    }

    public function test_an_open_ended_range_works_from_a_start_date(): void
    {
        $this->sell(1000, '2026-01-10');
        $this->sell(500, '2026-03-10');

        $statement = $this->reports()->customerStatement($this->customer, '2026-02-01', null);

        $this->assertCount(1, $statement['entries']);
        $this->assertEquals(1000.0, $statement['opening_balance']);
        $this->assertEquals(1500.0, $statement['balance']);
    }

    public function test_a_range_with_nothing_in_it_still_reports_the_position(): void
    {
        $this->sell(5000, '2026-01-10');

        $statement = $this->reports()->customerStatement($this->customer, '2026-06-01', '2026-06-30');

        $this->assertCount(0, $statement['entries']);
        $this->assertEquals(5000.0, $statement['opening_balance']);
        $this->assertEquals(5000.0, $statement['balance'], 'Nothing moved, so nothing changed');
    }

    // ── Supplier statement ───────────────────────────────────────

    public function test_a_supplier_statement_brings_its_balance_forward_too(): void
    {
        $category = Category::query()->where('company_id', $this->company->id)
            ->where('kind', 'expense')->firstOrFail();

        $this->post('/app/expenses', [
            'vendor_id' => $this->vendor->id, 'category_id' => $category->id,
            'date' => '2026-01-10', 'amount' => 4000, 'mode' => 'later',
        ])->assertSessionHasNoErrors();
        Cache::flush();

        $this->post('/app/payments/pay', [
            'vendor_id' => $this->vendor->id, 'category_id' => $category->id,
            'date' => '2026-03-15', 'amount' => 1000, 'method' => 'cash',
        ])->assertSessionHasNoErrors();

        $statement = $this->reports()->supplierStatement($this->vendor->fresh(), '2026-03-01', '2026-03-31');

        $this->assertEquals(4000.0, $statement['opening_balance']);
        $this->assertEquals(3000.0, $statement['balance']);
    }

    // ── Inventory statement ──────────────────────────────────────

    /**
     * A range must never hide stock bought before it — the closing
     * figure counts everything up to the end of the window.
     */
    public function test_stock_bought_before_the_range_still_counts(): void
    {
        $item = $this->item();

        $this->buyStock($item, 100, '2026-01-10');
        $this->sell(250, '2026-03-10', $item, 25);

        $row = collect($this->reports()->inventoryStatement(null, '2026-03-01', '2026-03-31')['items'])
            ->firstWhere('id', $item->id);

        $this->assertEquals(75.0, $row['current_stock'], 'Bought 100 in January, sold 25 in March');
        $this->assertFalse($row['is_negative']);
    }

    public function test_the_range_reports_what_moved_within_it(): void
    {
        $item = $this->item();

        $this->buyStock($item, 100, '2026-01-10');
        $this->buyStock($item, 40, '2026-03-05');
        $this->sell(250, '2026-03-10', $item, 25);

        $row = collect($this->reports()->inventoryStatement(null, '2026-03-01', '2026-03-31')['items'])
            ->firstWhere('id', $item->id);

        $this->assertEquals(100.0, $row['opening_stock'], 'What was on the shelf on 1 March');
        $this->assertEquals(40.0, $row['period_purchased']);
        $this->assertEquals(25.0, $row['period_sold']);
        $this->assertEquals(115.0, $row['current_stock']);
    }

    public function test_the_drill_down_history_starts_from_the_opening_stock(): void
    {
        $item = $this->item();

        $this->buyStock($item, 100, '2026-01-10');
        $this->sell(250, '2026-03-10', $item, 25);

        $history = $this->reports()->inventoryStatement($item->id, '2026-03-01', '2026-03-31')['history'];

        $this->assertCount(1, $history, 'Only March moved');
        $this->assertEquals(75.0, $history[0]['stock_after'], 'Not -25 — January is behind it');
    }

    public function test_with_no_range_the_inventory_report_is_unchanged(): void
    {
        $item = $this->item();

        $this->buyStock($item, 100, '2026-01-10');
        $this->sell(250, '2026-03-10', $item, 25);

        $row = collect($this->reports()->inventoryStatement()['items'])->firstWhere('id', $item->id);

        $this->assertEquals(75.0, $row['current_stock']);
        $this->assertEquals(0.0, $row['opening_stock'], 'No range means no "before" to speak of');
    }

    // ── Exports follow the screen ────────────────────────────────

    public function test_the_statement_export_covers_the_same_range(): void
    {
        $this->sell(5000, '2026-01-10');
        $this->receive(2000, '2026-03-15');

        $this->get(route('app.reports.customer-statement.export', [
            'customer' => $this->customer->id, 'format' => 'excel',
            'from' => '2026-03-01', 'to' => '2026-03-31',
        ]))->assertOk();
    }

    public function test_the_inventory_export_accepts_a_range(): void
    {
        $item = $this->item();
        $this->buyStock($item, 100, '2026-01-10');

        $this->get(route('app.reports.inventory-statement.export', [
            'format' => 'excel', 'from' => '2026-03-01', 'to' => '2026-03-31',
        ]))->assertOk();
    }
}
