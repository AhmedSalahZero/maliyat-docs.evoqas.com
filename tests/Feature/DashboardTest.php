<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\InventoryPurchase;
use App\Models\Item;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  The dashboard's numbers.
//
//  Three things are being protected:
//
//  • The three measures really are different measures. A customer
//    who places one big order and a customer who places many small
//    ones must each lead on their own axis — that divergence is the
//    entire reason the panels show value, quantity and frequency
//    side by side instead of one ranked list.
//
//  • Gross profit stays off the page. It was asked for explicitly.
//
//  • The page costs a flat number of queries. The version this
//    replaced called Sale::all()->filter(...) plus balance() per
//    row, which is the same unbounded pattern that made the
//    Receive/Pay screen unusable.
// ══════════════════════════════════════════════════════════════════
class DashboardTest extends TestCase
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

    private function sell(Customer $customer, Item $item, float $qty, float $unitPrice): Sale
    {
        $total = $qty * $unitPrice;

        $sale = Sale::create([
            'company_id' => $this->company->id, 'customer_id' => $customer->id,
            'date' => now()->toDateString(), 'subtotal' => $total, 'vat_rate' => 0,
            'vat_amount' => 0, 'amount' => $total, 'created_by' => $this->user->id,
        ]);

        $sale->lines()->create([
            'item_id' => $item->id, 'qty' => $qty,
            'unit_price' => $unitPrice, 'line_total' => $total,
        ]);

        return $sale;
    }

    private function customer(string $name): Customer
    {
        return Customer::create(['name' => $name, 'company_id' => $this->company->id]);
    }

    private function item(string $name): Item
    {
        return Item::create(['name' => $name, 'company_id' => $this->company->id]);
    }

    private function dashboard(array $query = []): \Inertia\Testing\AssertableInertia
    {
        $page = null;

        $this->actingAs($this->user)
            ->get(route('app.dashboard', $query))
            ->assertOk()
            ->assertInertia(function ($inertia) use (&$page) {
                $page = $inertia;
            });

        return $page;
    }

    // ── Sales / SKU donut / Channel donut must all agree ──────────
    //
    // (Sep 2026 bug: the Channel donut summed sales.amount, which is
    // VAT-INCLUSIVE — amount = subtotal + vat_amount — while Revenue
    // and the SKU donut are both net-of-VAT. It also never excluded
    // is_opening_balance sales, which are posted to Accounts
    // Receivable, never to Sales Revenue. Both made the Channel
    // donut's total disagree with Revenue and with the SKU donut.)

    public function test_channel_donut_excludes_vat_and_ties_to_sku_donut(): void
    {
        $customer = $this->customer('Ahmed');
        $item     = $this->item('Widget');

        // A single VAT-bearing sale: 1,000 net + 14% VAT = 1,140 gross.
        Sale::create([
            'company_id' => $this->company->id, 'customer_id' => $customer->id,
            'date' => now()->toDateString(), 'subtotal' => 1000, 'vat_rate' => 14,
            'vat_amount' => 140, 'amount' => 1140, 'created_by' => $this->user->id,
        ])->lines()->create([
            'item_id' => $item->id, 'qty' => 1, 'unit_price' => 1000, 'line_total' => 1000,
        ]);

        $props = $this->dashboard()->toArray()['props'];

        $channelTotal = collect($props['sales_by_channel'])->sum('value');
        $skuTotal     = collect($props['sku_sales'])->sum('value');

        // Net of VAT (1,000), never the gross invoice total (1,140) —
        // and the two donuts must agree with each other.
        $this->assertEquals(1000.0, $channelTotal);
        $this->assertEquals(1000.0, $skuTotal);
        $this->assertEquals($skuTotal, $channelTotal);
    }

    public function test_channel_donut_excludes_opening_balance_sales(): void
    {
        $customer = $this->customer('Legacy Customer');

        // What the customer already owed on day one — posted to
        // Accounts Receivable only, never to Sales Revenue.
        Sale::create([
            'company_id' => $this->company->id, 'customer_id' => $customer->id,
            'date' => now()->toDateString(), 'subtotal' => 5000, 'vat_rate' => 0,
            'vat_amount' => 0, 'amount' => 5000, 'is_opening_balance' => true,
        ]);

        $props = $this->dashboard()->toArray()['props'];

        $this->assertSame(0.0, collect($props['sales_by_channel'])->sum('value'));
        $this->assertEmpty($props['sku_sales']);
    }



    public function test_value_quantity_and_frequency_each_find_their_own_leader(): void
    {
        $bulk   = $this->customer('Bulk Buyer');    // one huge order
        $repeat = $this->customer('Repeat Buyer');  // many small orders

        $cement = $this->item('Cement');
        $nails  = $this->item('Nails');

        // 100 units at 50 → one sale worth 5,000
        $this->sell($bulk, $cement, 100, 50);

        // 4 sales of 300 units at 1 → 1,200 total, but 400 units and
        // four separate transactions.
        for ($i = 0; $i < 4; $i++) {
            $this->sell($repeat, $nails, 100, 1);
        }

        $props = $this->dashboard()->toArray()['props'];

        $customers = collect($props['top_customers']);
        $items     = collect($props['sku_sales']);

        // Value → the bulk buyer / cement.
        $this->assertSame('Bulk Buyer', $customers->sortByDesc('value')->first()['name']);
        $this->assertSame('Cement', $items->sortByDesc('value')->first()['name']);

        // Quantity → the repeat buyer / nails (400 units vs 100).
        $this->assertSame('Repeat Buyer', $customers->sortByDesc('volume')->first()['name']);
        $this->assertSame('Nails', $items->sortByDesc('volume')->first()['name']);

        // Frequency → the repeat buyer again (4 sales vs 1).
        $this->assertSame('Repeat Buyer', $customers->sortByDesc('transactions')->first()['name']);
        $this->assertSame(4, $customers->firstWhere('name', 'Repeat Buyer')['transactions']);
        $this->assertSame(1, $customers->firstWhere('name', 'Bulk Buyer')['transactions']);
    }

    public function test_the_figures_themselves_are_right(): void
    {
        $customer = $this->customer('Acme');
        $item     = $this->item('Cement');

        $this->sell($customer, $item, 100, 50);   // 5,000
        $this->sell($customer, $item, 20, 50);    // 1,000

        $props = $this->dashboard()->toArray()['props'];

        $sku = collect($props['sku_sales'])->firstWhere('name', 'Cement');
        $this->assertEqualsWithDelta(120.0, $sku['volume'], 0.001);
        $this->assertEqualsWithDelta(6000.0, $sku['value'], 0.001);
        $this->assertSame(2, $sku['transactions']);

        $acme = collect($props['top_customers'])->firstWhere('name', 'Acme');
        $this->assertEqualsWithDelta(120.0, $acme['volume'], 0.001);
        $this->assertEqualsWithDelta(6000.0, $acme['value'], 0.001, 'Joining lines must not multiply the invoice total.');
        $this->assertSame(2, $acme['transactions']);
    }

    public function test_supplier_spend_spans_every_purchase_table(): void
    {
        $vendor   = Vendor::create(['name' => 'Supplier A', 'company_id' => $this->company->id]);
        $category = \App\Models\Category::create([
            'name' => 'Rent', 'kind' => 'expense', 'company_id' => $this->company->id,
        ]);

        Expense::create([
            'company_id' => $this->company->id, 'vendor_id' => $vendor->id,
            'category_id' => $category->id, 'date' => now()->toDateString(), 'amount' => 900,
        ]);

        $purchase = InventoryPurchase::create([
            'company_id' => $this->company->id, 'vendor_id' => $vendor->id,
            'date' => now()->toDateString(), 'subtotal' => 1200, 'vat_rate' => 0,
            'vat_amount' => 0, 'amount' => 1200,
        ]);
        $purchase->lines()->create([
            'item_id' => $this->item('Cement')->id, 'qty' => 40, 'uom' => 'unit',
            'qty_per_uom' => 1, 'base_unit_name' => 'unit', 'unit_price' => 30, 'line_total' => 1200,
        ]);

        $supplier = collect($this->dashboard()->toArray()['props']['top_suppliers'])
            ->firstWhere('name', 'Supplier A');

        $this->assertEqualsWithDelta(2100.0, $supplier['value'], 0.001, 'Expense + inventory purchase.');
        $this->assertSame(2, $supplier['transactions']);
        // Volume counts stock units only — an expense has no unit count.
        $this->assertEqualsWithDelta(40.0, $supplier['volume'], 0.001);
    }

    // ── Scoping and periods ──────────────────────────────────────

    public function test_another_companys_trade_never_appears(): void
    {
        $this->sell($this->customer('Mine'), $this->item('Cement'), 10, 10);

        $other         = Company::factory()->create();
        $otherCustomer = Customer::create(['name' => 'Theirs', 'company_id' => $other->id]);
        $otherItem     = Item::create(['name' => 'Their Item', 'company_id' => $other->id]);
        $theirSale     = Sale::create([
            'company_id' => $other->id, 'customer_id' => $otherCustomer->id,
            'date' => now()->toDateString(), 'subtotal' => 9999, 'vat_rate' => 0,
            'vat_amount' => 0, 'amount' => 9999,
        ]);
        $theirSale->lines()->create([
            'item_id' => $otherItem->id, 'qty' => 5, 'unit_price' => 2000, 'line_total' => 9999,
        ]);

        $props = $this->dashboard()->toArray()['props'];

        $this->assertSame(['Mine'], collect($props['top_customers'])->pluck('name')->all());
        $this->assertSame(['Cement'], collect($props['sku_sales'])->pluck('name')->all());
    }

    /**
     * QA audit (Sep 2026), Finding 3: DashboardController opts out of
     * the automatic BelongsToCompany global scope for performance and
     * filters every raw query by hand instead. That's verified correct
     * as of this test, but nothing previously caught a REGRESSION —
     * a future edit that adds a new figure and forgets its company_id
     * filter would leak silently. This test is the guard rail: it
     * exercises every figure the dashboard returns (not just the two
     * covered by test_another_companys_trade_never_appears above)
     * against a second company with real, large, easy-to-notice
     * numbers, so any future leak fails a test immediately instead of
     * surfacing as a live customer seeing another company's money.
     */
    public function test_no_figure_on_the_dashboard_ever_includes_another_companys_money(): void
    {
        // My own company: one small, known sale, one small open
        // invoice, one small unpaid bill.
        $myCustomer = $this->customer('Mine');
        $myItem     = $this->item('Cement');
        $mySale     = $this->sell($myCustomer, $myItem, 1, 100); // 100, fully unpaid → open

        $myVendor   = Vendor::create(['name' => 'My Vendor', 'company_id' => $this->company->id]);
        $myCategory = \App\Models\Category::create(['name' => 'Rent', 'kind' => 'expense', 'company_id' => $this->company->id]);
        Expense::create([
            'company_id' => $this->company->id, 'vendor_id' => $myVendor->id, 'category_id' => $myCategory->id,
            'date' => now()->toDateString(), 'amount' => 50, // unpaid → open bill
        ]);

        // A second, unrelated company with LARGE, easy-to-spot
        // numbers in every single category the dashboard reports on.
        $other         = Company::factory()->create();
        $otherCustomer = Customer::create(['name' => 'Theirs', 'company_id' => $other->id]);
        $otherItem     = Item::create(['name' => 'Their Item', 'company_id' => $other->id]);
        $otherVendor   = Vendor::create(['name' => 'Their Vendor', 'company_id' => $other->id]);
        $otherCategory = \App\Models\Category::create(['name' => 'Rent', 'kind' => 'expense', 'company_id' => $other->id]);

        $theirSale = Sale::create([
            'company_id' => $other->id, 'customer_id' => $otherCustomer->id,
            'date' => now()->toDateString(), 'subtotal' => 999999, 'vat_rate' => 0,
            'vat_amount' => 0, 'amount' => 999999,
        ]);
        $theirSale->lines()->create([
            'item_id' => $otherItem->id, 'qty' => 999, 'unit_price' => 1000, 'line_total' => 999999,
        ]);

        Expense::create([
            'company_id' => $other->id, 'vendor_id' => $otherVendor->id, 'category_id' => $otherCategory->id,
            'date' => now()->toDateString(), 'amount' => 888888,
        ]);

        InventoryPurchase::create([
            'company_id' => $other->id, 'vendor_id' => $otherVendor->id,
            'date' => now()->toDateString(), 'subtotal' => 777777, 'vat_rate' => 0,
            'vat_amount' => 0, 'amount' => 777777,
        ])->lines()->create([
            'item_id' => $otherItem->id, 'qty' => 100, 'uom' => 'unit',
            'qty_per_uom' => 1, 'base_unit_name' => 'unit', 'unit_price' => 7777.77, 'line_total' => 777777,
        ]);

        Payment::create([
            'company_id' => $other->id, 'date' => now()->toDateString(),
            'amount' => 555555, 'direction' => 'in', 'method' => 'cash',
        ]);

        $props = $this->dashboard()->toArray()['props'];

        // Every money figure must stay at MY scale, never leap to
        // their six/seven-figure amounts.
        $this->assertLessThan(1000, $props['total_cash_in']);
        $this->assertLessThan(1000, $props['total_cash_out']);
        $this->assertLessThan(1000, $props['cash_balance']);

        // Exactly my one open invoice / one open bill — not theirs too.
        $this->assertSame(1, $props['open_invoices_count']);
        $this->assertSame(1, $props['open_bills_count']);

        // Their vendor must never appear in my top suppliers list.
        $this->assertFalse(
            collect($props['top_suppliers'])->pluck('name')->contains('Their Vendor'),
            "Another company's vendor leaked into top_suppliers."
        );

        // Their customer/item must never appear in my lists either.
        $this->assertFalse(collect($props['top_customers'])->pluck('name')->contains('Theirs'));
        $this->assertFalse(collect($props['sku_sales'])->pluck('name')->contains('Their Item'));
    }

    public function test_sales_outside_the_period_are_excluded(): void
    {
        $customer = $this->customer('Acme');
        $item     = $this->item('Cement');

        $this->sell($customer, $item, 10, 10);

        // Backdate one sale well outside this month.
        $old = $this->sell($customer, $item, 999, 999);
        $old->forceFill(['date' => now()->subMonths(6)->toDateString()])->save();

        $sku = collect($this->dashboard()->toArray()['props']['sku_sales'])->firstWhere('name', 'Cement');

        $this->assertEqualsWithDelta(10.0, $sku['volume'], 0.001, 'A six-month-old sale leaked into "this month".');
    }

    public function test_the_period_can_be_widened(): void
    {
        $customer = $this->customer('Acme');
        $item     = $this->item('Cement');

        $old = $this->sell($customer, $item, 50, 10);
        $old->forceFill(['date' => now()->startOfYear()->addDay()->toDateString()])->save();

        $monthly = collect($this->dashboard()->toArray()['props']['sku_sales']);
        $yearly  = collect($this->dashboard(['period' => 'year'])->toArray()['props']['sku_sales']);

        // The sale is dated the second of January, so it is outside
        // this month by construction and inside this year — which is
        // exactly the difference the selector has to make.
        $this->assertCount(0, $monthly, 'A January sale must not count towards this month.');
        $this->assertCount(1, $yearly, 'A January sale must count towards this year.');
        $this->assertEqualsWithDelta(50.0, $yearly->first()['volume'], 0.001);
    }

    public function test_an_unknown_period_falls_back_to_the_month(): void
    {
        $this->assertSame('month', $this->dashboard(['period' => 'nonsense'])->toArray()['props']['period']);
    }

    // ── What must NOT be there ───────────────────────────────────

    public function test_gross_profit_is_not_on_the_dashboard(): void
    {
        $props = $this->dashboard()->toArray()['props'];

        foreach (array_keys($props) as $key) {
            $this->assertStringNotContainsStringIgnoringCase(
                'gross',
                $key,
                'Gross profit was deliberately removed from the dashboard.'
            );
            $this->assertStringNotContainsStringIgnoringCase('margin', $key);
        }
    }

    // ── Cost ─────────────────────────────────────────────────────

    public function test_the_query_count_does_not_grow_with_the_data(): void
    {
        $customer = $this->customer('Acme');
        $item     = $this->item('Cement');

        $this->sell($customer, $item, 1, 1);
        $this->actingAs($this->user)->get(route('app.dashboard'))->assertOk();  // prime

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->user)->get(route('app.dashboard'))->assertOk();
        $small = count(DB::getQueryLog());
        DB::disableQueryLog();

        for ($i = 0; $i < 40; $i++) {
            $this->sell($customer, $item, 1, 1);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->user)->get(route('app.dashboard'))->assertOk();
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $small,
            $large,
            "The dashboard cost {$small} queries with 1 sale and {$large} with 41 — it is scaling with the table."
        );
    }
}
