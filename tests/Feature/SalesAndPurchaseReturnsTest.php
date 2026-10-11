<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\InventoryPurchase;
use App\Models\Item;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\SaleReturn;
use App\Models\User;
use App\Models\Vendor;
use App\Services\JournalService;
use App\Services\Reports\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

// Sales quantity return and inventory-purchase quantity return.
// Goods come back at the ORIGINAL sale cost / go out at the ORIGINAL
// purchase price, dated on the return date.
class SalesAndPurchaseReturnsTest extends TestCase
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

        $this->company = Company::factory()->create(['business_types' => ['trading']]);
        $this->admin   = User::factory()->companyAdmin($this->company)->create();

        app(JournalService::class)->seedChartOfAccounts($this->company);
        Category::seedDefaults($this->company->id);

        $this->actingAs($this->admin);

        $this->customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Acme']);
        $this->vendor   = Vendor::create(['company_id' => $this->company->id, 'name' => 'Supplies Co']);
        $this->item     = Item::create([
            'company_id' => $this->company->id, 'name' => 'Widget',
            'qty_per_uom' => 1, 'base_unit_name' => 'pc',
        ]);
    }

    private function buy(float $qty, float $price, string $on): InventoryPurchase
    {
        $this->post('/app/inventory-purchases', [
            'vendor_id' => $this->vendor->id, 'date' => $on, 'vat_rate' => 0, 'mode' => 'later',
            'lines' => [['item_id' => $this->item->id, 'qty' => $qty, 'qty_per_uom' => 1, 'unit_price' => $price]],
        ])->assertSessionHasNoErrors();
        Cache::flush();

        return InventoryPurchase::query()->latest('id')->firstOrFail();
    }

    private function sell(float $qty, string $on): Sale
    {
        $this->post('/app/sales', [
            'customer_id' => $this->customer->id, 'date' => $on, 'vat_rate' => 0, 'mode' => 'later',
            'lines' => [['item_id' => $this->item->id, 'qty' => $qty, 'unit_price' => 25]],
        ])->assertSessionHasNoErrors();
        Cache::flush();

        return Sale::query()->latest('id')->firstOrFail();
    }

    private function returnSale(Sale $sale, float $qty, string $on, array $extra = []): \Illuminate\Testing\TestResponse
    {
        $r = $this->post("/app/sales/{$sale->id}/returns", array_merge([
            'date'  => $on,
            'lines' => [['sale_line_id' => $sale->lines()->first()->id, 'qty' => $qty]],
        ], $extra));
        Cache::flush();

        return $r;
    }

    private function returnPurchase(InventoryPurchase $p, float $qty, string $on, array $extra = []): \Illuminate\Testing\TestResponse
    {
        $r = $this->post("/app/inventory-purchases/{$p->id}/returns", array_merge([
            'date'  => $on,
            'lines' => [['purchase_line_id' => $p->lines()->first()->id, 'qty' => $qty]],
        ], $extra));
        Cache::flush();

        return $r;
    }

    private function stockValue(): float
    {
        $acct = \App\Models\Account::query()->where('company_id', $this->company->id)->where('code', \App\Models\Account::INVENTORY_ASSET)->first();

        return $acct
            ? round(\App\Models\JournalLine::query()->where('account_id', $acct->id)->get()
                ->sum(fn ($l) => (float) $l->debit - (float) $l->credit), 2)
            : 0.0;
    }

    public function test_a_return_a_month_later_comes_back_at_the_original_sale_cost(): void
    {
        $this->buy(100, 10, '2026-09-01');
        $sale = $this->sell(10, '2026-09-05');          // costed at 10
        $this->buy(10, 50, '2026-09-20');               // average climbs to ~14

        $this->returnSale($sale, 4, '2026-10-01', ['refund_method' => 'credit'])->assertSessionHasNoErrors();

        $line = \App\Models\SaleReturnLine::query()->firstOrFail();
        $this->assertEquals(10.0, (float) $line->unit_cost, 'Original cost, not today\'s average');
        $this->assertEquals(104.0, $this->item->fresh()->currentStock());
        $this->assertEquals(1440.0, $this->stockValue());
    }

    public function test_unpaid_sale_return_reduces_the_balance_without_a_refund(): void
    {
        $this->buy(100, 10, '2026-09-01');
        $sale = $this->sell(10, '2026-09-05');           // 250 owed

        $this->returnSale($sale, 4, '2026-09-10')->assertSessionHasNoErrors();

        $ret = SaleReturn::query()->firstOrFail();
        $this->assertEquals(100.0, (float) $ret->amount);
        $this->assertEquals(100.0, (float) $ret->applied_amount);
        $this->assertEquals(0.0, (float) $ret->refund_amount);
        $this->assertEquals(150.0, $sale->fresh()->balance());
    }

    public function test_cannot_return_more_than_was_sold(): void
    {
        $this->buy(100, 10, '2026-09-01');
        $sale = $this->sell(10, '2026-09-05');

        $this->returnSale($sale, 11, '2026-09-10')->assertSessionHasErrors();
        $this->assertSame(0, SaleReturn::count());

        $this->returnSale($sale, 6, '2026-09-10')->assertSessionHasNoErrors();
        $this->returnSale($sale, 5, '2026-09-11')->assertSessionHasErrors();
        $this->assertSame(1, SaleReturn::count());
    }

    public function test_return_dated_before_the_sale_is_refused(): void
    {
        $this->buy(100, 10, '2026-09-01');
        $sale = $this->sell(10, '2026-09-05');

        $this->returnSale($sale, 1, '2026-09-04')->assertSessionHasErrors('date');
    }

    public function test_sale_cannot_be_edited_or_deleted_while_it_has_returns(): void
    {
        $this->buy(100, 10, '2026-09-01');
        $sale = $this->sell(10, '2026-09-05');
        $this->returnSale($sale, 1, '2026-09-10')->assertSessionHasNoErrors();

        $this->delete("/app/sales/{$sale->id}");
        $this->assertNotNull(Sale::find($sale->id));
    }

    public function test_deleting_a_sale_return_reverses_everything(): void
    {
        $this->buy(100, 10, '2026-09-01');
        $sale = $this->sell(10, '2026-09-05');
        $before = $this->stockValue();

        $this->returnSale($sale, 4, '2026-09-10')->assertSessionHasNoErrors();
        $this->assertNotEquals($before, $this->stockValue());

        $this->delete('/app/sale-returns/'.SaleReturn::firstOrFail()->id);
        Cache::flush();

        $this->assertSame(0, SaleReturn::count());
        $this->assertEquals($before, $this->stockValue());
        $this->assertEquals(250.0, $sale->fresh()->balance());
        $this->assertEquals(90.0, $this->item->fresh()->currentStock());
    }

    public function test_purchase_return_removes_goods_at_the_original_price(): void
    {
        $p = $this->buy(10, 10, '2026-09-01');
        $this->buy(10, 20, '2026-09-10');

        $this->returnPurchase($p, 10, '2026-09-20', ['refund_method' => 'credit'])->assertSessionHasNoErrors();

        $this->assertEquals(10.0, $this->item->fresh()->currentStock());
        $this->assertEquals(200.0, $this->stockValue());
        $this->assertEquals(0.0, (float) $p->fresh()->balance(), 'Return of the full 100 settles the bill');
    }

    public function test_purchase_return_of_goods_already_sold_is_refused(): void
    {
        $p = $this->buy(10, 10, '2026-09-01');
        $this->sell(10, '2026-09-05');

        $this->returnPurchase($p, 5, '2026-09-10')->assertSessionHasErrors();
        $this->assertSame(0, PurchaseReturn::count());
    }

    public function test_purchase_cannot_be_deleted_while_it_has_returns(): void
    {
        $p = $this->buy(10, 10, '2026-09-01');
        $this->returnPurchase($p, 2, '2026-09-10')->assertSessionHasNoErrors();

        $this->delete("/app/inventory-purchases/{$p->id}");
        $this->assertNotNull(InventoryPurchase::find($p->id));
    }

    public function test_trial_balance_still_balances_after_returns(): void
    {
        $p = $this->buy(100, 10, '2026-09-01');
        $sale = $this->sell(10, '2026-09-05');
        $this->returnSale($sale, 4, '2026-09-10')->assertSessionHasNoErrors();
        $this->returnPurchase($p, 5, '2026-09-12')->assertSessionHasNoErrors();

        $tb = app(ReportDataService::class)->trialBalance('2026-09-01', '2026-09-30');
        $this->assertTrue($tb['is_balanced']);
    }

    public function test_another_company_cannot_return_against_my_sale(): void
    {
        $this->buy(100, 10, '2026-09-01');
        $sale = $this->sell(10, '2026-09-05');

        $other = Company::factory()->create();
        $this->actingAs(User::factory()->companyAdmin($other)->create());

        $this->returnSale($sale, 1, '2026-09-10')->assertStatus(404);
        $this->assertSame(0, SaleReturn::count());
    }
}
