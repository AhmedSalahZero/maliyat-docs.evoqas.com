<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\InventoryPurchase;
use App\Models\Item;
use App\Models\ProductionOrder;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\User;
use App\Models\Vendor;
use App\Services\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  Stock may never be negative on ANY day — not just today.
//
//  The stock checks used to ask "how much do we hold today?". A sale
//  dated 5 Sep therefore went through when the only purchase was
//  dated 10 Sep (the stock is there today), and the costing engine —
//  which correctly rebuilds the item day by day from 5 Sep — found
//  nothing on hand that day and costed the sale at ZERO. Profit was
//  overstated and every later unit carried an inflated average cost.
//
//  Editing a purchase to a later date, cutting its quantity, deleting
//  it, deleting/cutting a production run whose product was sold, or
//  resetting opening stock that was sold, all opened the same hole,
//  and none of them were checked at all. See App\Services\StockTimeline.
// ══════════════════════════════════════════════════════════════════
class BackdatedStockTest extends TestCase
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

        $this->company = Company::factory()->create(['business_types' => ['trading', 'production']]);
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

    // ── Helpers ──────────────────────────────────────────────────

    private function purchasePayload(float $qty, string $on, ?Item $item = null): array
    {
        return [
            'vendor_id' => $this->vendor->id,
            'date'      => $on,
            'lines'     => [['item_id' => ($item ?? $this->item)->id, 'qty' => $qty, 'qty_per_uom' => 1, 'unit_price' => 10]],
            'vat_rate'  => 0,
            'mode'      => 'later',
        ];
    }

    private function buy(float $qty, string $on, ?Item $item = null): InventoryPurchase
    {
        $this->post('/app/inventory-purchases', $this->purchasePayload($qty, $on, $item))->assertSessionHasNoErrors();
        Cache::flush();

        return InventoryPurchase::query()->latest('id')->firstOrFail();
    }

    private function salePayload(float $qty, string $on, ?Item $item = null): array
    {
        return [
            'customer_id' => $this->customer->id,
            'date'        => $on,
            'lines'       => [['item_id' => ($item ?? $this->item)->id, 'qty' => $qty, 'unit_price' => 25]],
            'vat_rate'    => 0,
            'mode'        => 'later',
        ];
    }

    private function sell(float $qty, string $on, ?Item $item = null): \Illuminate\Testing\TestResponse
    {
        $response = $this->post('/app/sales', $this->salePayload($qty, $on, $item));
        Cache::flush();

        return $response;
    }

    // ── Sales ────────────────────────────────────────────────────

    /** The exact case from the audit: bought 10 Sep, sold 5 Sep. */
    public function test_a_sale_dated_before_the_goods_arrived_is_refused(): void
    {
        $this->buy(100, '2026-09-10');

        $this->sell(10, '2026-09-05')->assertSessionHasErrors('lines.0.qty');

        $this->assertSame(0, Sale::count());

        $message = session('errors')->get('lines.0.qty')[0];
        $this->assertStringContainsString('Widget', $message);
        $this->assertStringContainsString('Only 0 ', $message, 'Nothing was on hand on 5 Sep');
        $this->assertStringContainsString('5 Sep 2026', $message, 'The message names the day');
    }

    public function test_the_same_sale_dated_on_or_after_the_purchase_goes_through_and_is_costed(): void
    {
        $this->buy(100, '2026-09-10');

        $this->sell(10, '2026-09-10')->assertSessionHasNoErrors();

        $line = SaleLine::query()->firstOrFail();
        $this->assertEquals(10.0, (float) $line->unit_cost, 'Costed at the purchase price, never zero');
    }

    /**
     * Enough on the day itself, but a LATER sale would be starved:
     * bought 10 on the 1st, sold 10 on the 20th, bought 10 on the
     * 25th. A new sale of 10 on the 5th fits the 5th — and leaves
     * the 20th at -10.
     */
    public function test_a_backdated_sale_that_starves_a_later_sale_is_refused(): void
    {
        $this->buy(10, '2026-09-01');
        $this->sell(10, '2026-09-20')->assertSessionHasNoErrors();
        $this->buy(10, '2026-09-25');

        $this->sell(10, '2026-09-05')->assertSessionHasErrors('lines.0.qty');

        $this->assertSame(1, Sale::count());
    }

    public function test_moving_a_sale_to_before_its_stock_arrived_is_refused(): void
    {
        $this->buy(100, '2026-09-10');
        $this->sell(10, '2026-09-12')->assertSessionHasNoErrors();

        $sale = Sale::query()->firstOrFail();

        $this->put("/app/sales/{$sale->id}", [
            'customer_id' => $this->customer->id,
            'date'        => '2026-09-05',
            'lines'       => [['item_id' => $this->item->id, 'qty' => 10, 'unit_price' => 25]],
            'vat_rate'    => 0,
        ])->assertSessionHasErrors('lines.0.qty');

        $this->assertSame('2026-09-12', $sale->fresh()->date->toDateString());
    }

    public function test_moving_a_sale_later_is_allowed(): void
    {
        $this->buy(100, '2026-09-10');
        $this->sell(10, '2026-09-12')->assertSessionHasNoErrors();

        $sale = Sale::query()->firstOrFail();

        $this->put("/app/sales/{$sale->id}", [
            'customer_id' => $this->customer->id,
            'date'        => '2026-09-15',
            'lines'       => [['item_id' => $this->item->id, 'qty' => 10, 'unit_price' => 25]],
            'vat_rate'    => 0,
        ])->assertSessionHasNoErrors();
    }

    // ── Purchases ────────────────────────────────────────────────

    public function test_moving_a_purchase_after_the_sales_that_used_it_is_refused(): void
    {
        $purchase = $this->buy(100, '2026-09-01');
        $this->sell(30, '2026-09-05')->assertSessionHasNoErrors();

        $payload = $this->purchasePayload(100, '2026-09-10');
        unset($payload['mode']);

        $this->put("/app/inventory-purchases/{$purchase->id}", $payload)->assertSessionHasErrors('lines');

        $this->assertSame('2026-09-01', $purchase->fresh()->date->toDateString());
    }

    public function test_cutting_a_purchase_below_what_was_sold_is_refused(): void
    {
        $purchase = $this->buy(100, '2026-09-01');
        $this->sell(30, '2026-09-05')->assertSessionHasNoErrors();

        $payload = $this->purchasePayload(20, '2026-09-01');
        unset($payload['mode']);

        $this->put("/app/inventory-purchases/{$purchase->id}", $payload)->assertSessionHasErrors('lines');

        $payload = $this->purchasePayload(30, '2026-09-01');
        unset($payload['mode']);

        $this->put("/app/inventory-purchases/{$purchase->id}", $payload)->assertSessionHasNoErrors();
    }

    public function test_re_saving_a_purchase_unchanged_is_allowed(): void
    {
        $purchase = $this->buy(100, '2026-09-01');
        $this->sell(100, '2026-09-05')->assertSessionHasNoErrors();

        $payload = $this->purchasePayload(100, '2026-09-01');
        unset($payload['mode']);

        $this->put("/app/inventory-purchases/{$purchase->id}", $payload)->assertSessionHasNoErrors();
    }

    public function test_deleting_a_purchase_whose_stock_was_sold_is_refused(): void
    {
        $purchase = $this->buy(100, '2026-09-01');
        $this->sell(30, '2026-09-05')->assertSessionHasNoErrors();

        $this->delete("/app/inventory-purchases/{$purchase->id}")->assertSessionHas('error');

        $this->assertNotNull($purchase->fresh(), 'The purchase survives');
    }

    public function test_deleting_a_purchase_is_fine_once_its_sales_are_gone(): void
    {
        $purchase = $this->buy(100, '2026-09-01');
        $this->sell(30, '2026-09-05')->assertSessionHasNoErrors();

        $this->delete('/app/sales/'.Sale::query()->firstOrFail()->id)->assertSessionHasNoErrors();
        $this->delete("/app/inventory-purchases/{$purchase->id}")->assertSessionMissing('error');

        $this->assertNull($purchase->fresh());
    }

    /** A second purchase covering the sale makes the first one removable. */
    public function test_deleting_a_purchase_is_fine_when_other_stock_covers_the_sales(): void
    {
        $first = $this->buy(100, '2026-09-01');
        $this->buy(100, '2026-09-02');
        $this->sell(30, '2026-09-05')->assertSessionHasNoErrors();

        $this->delete("/app/inventory-purchases/{$first->id}")->assertSessionMissing('error');

        $this->assertNull($first->fresh());
    }

    // ── Production ───────────────────────────────────────────────

    /** @return array{0: Item, 1: Item, 2: ProductionOrder} [flour, bread, run] */
    private function makeRun(string $runDate = '2026-09-05', float $qtyProduced = 10): array
    {
        $flour = Item::create(['company_id' => $this->company->id, 'name' => 'Flour',
            'type' => 'raw_material', 'qty_per_uom' => 1, 'base_unit_name' => 'kg']);
        $bread = Item::create(['company_id' => $this->company->id, 'name' => 'Bread',
            'type' => 'product', 'qty_per_uom' => 1, 'base_unit_name' => 'loaf']);

        $this->buy(100, '2026-09-01', $flour);

        $this->post('/app/production-orders', [
            'item_id' => $bread->id, 'date' => $runDate, 'qty_produced' => $qtyProduced,
            'materials' => [['item_id' => $flour->id, 'qty' => 20]],
            'labor_cost' => 0,
        ])->assertSessionHasNoErrors();
        Cache::flush();

        return [$flour, $bread, ProductionOrder::query()->latest('id')->firstOrFail()];
    }

    public function test_using_raw_material_before_it_was_bought_is_refused(): void
    {
        $flour = Item::create(['company_id' => $this->company->id, 'name' => 'Flour',
            'type' => 'raw_material', 'qty_per_uom' => 1, 'base_unit_name' => 'kg']);
        $bread = Item::create(['company_id' => $this->company->id, 'name' => 'Bread',
            'type' => 'product', 'qty_per_uom' => 1, 'base_unit_name' => 'loaf']);

        $this->buy(100, '2026-09-10', $flour);

        $this->post('/app/production-orders', [
            'item_id' => $bread->id, 'date' => '2026-09-05', 'qty_produced' => 10,
            'materials' => [['item_id' => $flour->id, 'qty' => 20]],
            'labor_cost' => 0,
        ])->assertSessionHasErrors('materials.0.qty');

        $this->assertSame(0, ProductionOrder::count());
    }

    public function test_selling_product_before_it_was_made_is_refused(): void
    {
        [, $bread] = $this->makeRun('2026-09-05');

        $this->sell(5, '2026-09-03', $bread)->assertSessionHasErrors('lines.0.qty');
        $this->sell(5, '2026-09-06', $bread)->assertSessionHasNoErrors();
    }

    public function test_cutting_a_run_below_what_was_sold_is_refused(): void
    {
        [$flour, $bread, $run] = $this->makeRun('2026-09-05', 10);
        $this->sell(8, '2026-09-06', $bread)->assertSessionHasNoErrors();

        $this->put("/app/production-orders/{$run->id}", [
            'item_id' => $bread->id, 'date' => '2026-09-05', 'qty_produced' => 5,
            'materials' => [['item_id' => $flour->id, 'qty' => 20]],
            'labor_cost' => 0,
        ])->assertSessionHasErrors('qty_produced');

        $this->assertEquals(10.0, (float) $run->fresh()->qty_produced);
    }

    public function test_deleting_a_run_whose_product_was_sold_is_refused(): void
    {
        [, $bread, $run] = $this->makeRun('2026-09-05', 10);
        $this->sell(8, '2026-09-06', $bread)->assertSessionHasNoErrors();

        $this->delete("/app/production-orders/{$run->id}")->assertSessionHas('error');

        $this->assertNotNull($run->fresh());
    }

    // ── Opening balance ──────────────────────────────────────────

    private function postOpeningStock(float $qty): void
    {
        $this->post('/app/opening-balance', [
            'opening_date' => '2026-09-01',
            'cash_amount'  => null,
            'bank_amount'  => null,
            'customers'    => [['customer_id' => null, 'amount' => null]],
            'suppliers'    => [['vendor_id' => null, 'amount' => null]],
            'inventory'    => [['item_id' => $this->item->id, 'qty' => $qty, 'unit_price' => 10]],
            'equipment'    => [['name' => '', 'category_id' => null, 'amount' => null, 'date' => '2026-09-01']],
        ])->assertSessionHasNoErrors();

        Cache::flush();
    }

    public function test_resetting_opening_stock_that_was_sold_is_refused(): void
    {
        $this->postOpeningStock(40);
        $this->sell(40, '2026-09-05')->assertSessionHasNoErrors();

        $this->delete(route('app.opening-balance.reset'))->assertSessionHas('error');

        $this->assertSame(1, InventoryPurchase::query()->where('is_opening_balance', true)->count());
    }

    public function test_resetting_unsold_opening_stock_is_allowed_and_clears_the_stock_ledger(): void
    {
        $this->postOpeningStock(40);

        $this->delete(route('app.opening-balance.reset'))->assertSessionMissing('error');

        $this->assertSame(0, InventoryPurchase::query()->where('is_opening_balance', true)->count());
        $this->assertSame(
            0,
            \App\Models\InventoryStockLedger::query()->where('item_id', $this->item->id)->count(),
            'The removed opening stock must not linger in the cost ledger'
        );
    }
}
