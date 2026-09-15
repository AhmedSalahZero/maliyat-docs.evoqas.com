<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Sale;
use App\Models\User;
use App\Models\Vendor;
use App\Services\JournalService;
use App\Services\Reports\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  A sale cannot take more of an item out of stock than exists.
//
//  Nothing checked it. Selling 500 of something never purchased left
//  the Inventory Statement reading -500 — and that report even has
//  an `is_negative` flag to badge the row, so the state was expected
//  and displayed but never prevented while somebody could still fix
//  it.
//
//  It corrupts the accounts too. Cost of Goods Sold is priced from
//  an item's weighted-average purchase cost, and an item with no
//  purchases has no average — so those sales posted revenue with no
//  cost against it and overstated profit by the whole sale.
//
//  Free-text lines carry no item and are untouched: a service cannot
//  run out.
// ══════════════════════════════════════════════════════════════════
class StockLevelTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Customer $customer;

    private Vendor $vendor;

    private Item $item;

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
        $this->item     = Item::create([
            'company_id'     => $this->company->id,
            'name'           => 'Widget',
            'qty_per_uom'    => 1,
            'base_unit_name' => 'pc',
        ]);
    }

    /** Buy $qty of the item so there is something to sell. */
    private function stockUp(float $qty, string $on = '2026-09-01'): void
    {
        $this->post('/app/inventory-purchases', [
            'vendor_id' => $this->vendor->id,
            'date'      => $on,
            'lines'     => [['item_id' => $this->item->id, 'qty' => $qty, 'qty_per_uom' => 1, 'unit_price' => 10]],
            'vat_rate'  => 0,
            'mode'      => 'later',
        ])->assertSessionHasNoErrors();

        Cache::flush(); // the duplicate guard is not what these tests are about
    }

    private function sell(float $qty, string $on = '2026-09-05'): \Illuminate\Testing\TestResponse
    {
        $response = $this->post('/app/sales', [
            'customer_id' => $this->customer->id,
            'date'        => $on,
            'lines'       => [['item_id' => $this->item->id, 'qty' => $qty, 'unit_price' => 25]],
            'vat_rate'    => 0,
            'mode'        => 'later',
        ]);

        Cache::flush();

        return $response;
    }

    private function stockNow(): float
    {
        return (float) collect(app(ReportDataService::class)->inventoryStatement()['items'])
            ->firstWhere('id', $this->item->id)['current_stock'];
    }

    // ── Refused ──────────────────────────────────────────────────

    public function test_selling_an_item_never_purchased_is_refused(): void
    {
        $this->sell(500)->assertSessionHasErrors('lines.0.qty');

        $this->assertSame(0, Sale::count());
        $this->assertEquals(0.0, $this->stockNow());
    }

    public function test_selling_more_than_is_held_is_refused(): void
    {
        $this->stockUp(100);

        $this->sell(101)->assertSessionHasErrors('lines.0.qty');

        $this->assertSame(0, Sale::count());
    }

    /**
     * Two lines of the same item on one invoice have to be judged
     * together — each is under the stock on its own.
     */
    public function test_two_lines_of_the_same_item_are_judged_together(): void
    {
        $this->stockUp(100);

        $this->post('/app/sales', [
            'customer_id' => $this->customer->id,
            'date'        => '2026-09-05',
            'lines'       => [
                ['item_id' => $this->item->id, 'qty' => 60, 'unit_price' => 25],
                ['item_id' => $this->item->id, 'qty' => 60, 'unit_price' => 25],
            ],
            'vat_rate' => 0,
            'mode'     => 'later',
        ])->assertSessionHasErrors();

        $this->assertSame(0, Sale::count());
    }

    public function test_a_second_sale_can_only_take_what_the_first_left(): void
    {
        $this->stockUp(100);

        $this->sell(80)->assertSessionHasNoErrors();
        $this->sell(30, '2026-09-06')->assertSessionHasErrors('lines.0.qty');
        $this->sell(20, '2026-09-07')->assertSessionHasNoErrors();

        $this->assertEquals(0.0, $this->stockNow());
    }

    public function test_the_message_names_the_item_and_what_is_available(): void
    {
        $this->stockUp(7);

        $this->sell(10);

        $errors = session('errors')->get('lines.0.qty');

        $this->assertStringContainsString('Widget', $errors[0]);
        $this->assertStringContainsString('7', $errors[0]);
    }

    // ── Allowed ──────────────────────────────────────────────────

    public function test_selling_what_is_in_stock_goes_through(): void
    {
        $this->stockUp(100);

        $this->sell(100)->assertSessionHasNoErrors();

        $this->assertSame(1, Sale::count());
        $this->assertEquals(0.0, $this->stockNow());
    }

    public function test_a_free_text_line_with_no_item_is_untouched(): void
    {
        $this->post('/app/sales', [
            'customer_id' => $this->customer->id,
            'date'        => '2026-09-05',
            'lines'       => [['item_id' => null, 'qty' => 9999, 'unit_price' => 25]],
            'vat_rate'    => 0,
            'mode'        => 'later',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Sale::count());
    }

    public function test_opening_stock_counts_as_available(): void
    {
        app(\App\Services\OpeningBalanceService::class);

        $this->post('/app/opening-balance', [
            'opening_date' => '2026-09-01',
            'cash_amount'  => null,
            'bank_amount'  => null,
            'customers'    => [['customer_id' => null, 'amount' => null]],
            'suppliers'    => [['vendor_id' => null, 'amount' => null]],
            'inventory'    => [['item_id' => $this->item->id, 'qty' => 40, 'unit_price' => 10]],
            'equipment'    => [['name' => '', 'category_id' => null, 'amount' => null, 'date' => '2026-09-01']],
        ])->assertSessionHasNoErrors();

        Cache::flush();

        $this->sell(40)->assertSessionHasNoErrors();
        $this->assertSame(1, Sale::query()->where('is_opening_balance', false)->count());
    }

    // ── Editing ──────────────────────────────────────────────────

    /**
     * An edit must release the stock the sale already holds, or
     * re-saving it unchanged would be refused for consuming stock it
     * had already consumed.
     */
    public function test_re_saving_a_sale_unchanged_is_allowed(): void
    {
        $this->stockUp(100);
        $this->sell(100)->assertSessionHasNoErrors();

        $sale = Sale::query()->firstOrFail();

        $this->put("/app/sales/{$sale->id}", [
            'customer_id' => $this->customer->id,
            'date'        => '2026-09-05',
            'lines'       => [['item_id' => $this->item->id, 'qty' => 100, 'unit_price' => 30]],
            'vat_rate'    => 0,
        ])->assertSessionHasNoErrors();

        $this->assertEquals(3000.0, (float) $sale->fresh()->amount);
    }

    public function test_reducing_a_sale_frees_stock_up(): void
    {
        $this->stockUp(100);
        $this->sell(100)->assertSessionHasNoErrors();

        $sale = Sale::query()->firstOrFail();

        $this->put("/app/sales/{$sale->id}", [
            'customer_id' => $this->customer->id,
            'date'        => '2026-09-05',
            'lines'       => [['item_id' => $this->item->id, 'qty' => 60, 'unit_price' => 25]],
            'vat_rate'    => 0,
        ])->assertSessionHasNoErrors();

        $this->assertEquals(40.0, $this->stockNow());
    }

    public function test_an_edit_cannot_push_an_item_negative_either(): void
    {
        $this->stockUp(100);
        $this->sell(50)->assertSessionHasNoErrors();

        $sale = Sale::query()->firstOrFail();

        $this->put("/app/sales/{$sale->id}", [
            'customer_id' => $this->customer->id,
            'date'        => '2026-09-05',
            'lines'       => [['item_id' => $this->item->id, 'qty' => 130, 'unit_price' => 25]],
            'vat_rate'    => 0,
        ])->assertSessionHasErrors('lines.0.qty');

        $this->assertEquals(50.0, $this->stockNow(), 'The sale is unchanged');
    }

    public function test_deleting_a_sale_returns_its_stock(): void
    {
        $this->stockUp(100);
        $this->sell(100)->assertSessionHasNoErrors();

        $this->delete('/app/sales/'.Sale::query()->firstOrFail()->id)->assertSessionHasNoErrors();

        $this->assertEquals(100.0, $this->stockNow());
    }
}
