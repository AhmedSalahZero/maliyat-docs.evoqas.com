<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\InventoryPurchase;
use App\Models\Item;
use App\Models\ProductionOrder;
use App\Models\Sale;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Reports\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  Two different guarantees for the Inventory Statement report,
//  mirroring the two things that went wrong with it historically:
//
//  1. It used to run its own separate SQL that didn't know about
//     Production, so its numbers could silently disagree with the
//     rest of the app (production-cycle_EN.md §12). The rewrite reads
//     the same relations Item::currentStock()/averagePurchaseCost()
//     read — this test proves the two stay identical for every item
//     in a mixed purchase/production/sale/consumption scenario, so a
//     future edit to one side alone would fail this test rather than
//     silently drift.
//
//  2. Class of bug IndexQueryEfficiencyTest already guards for the
//     Sales list — a report must not cost more queries just because
//     the catalog has more items in it.
// ══════════════════════════════════════════════════════════════════
class InventoryStatementQueryEfficiencyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Vendor $vendor;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company  = Company::factory()->create(['business_types' => ['trading', 'production']]);
        $this->user     = User::factory()->companyAdmin($this->company)->create();
        $this->vendor   = Vendor::create(['company_id' => $this->company->id, 'name' => 'Supplier']);
        $this->customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Customer']);

        $this->actingAs($this->user);
    }

    /**
     * One purchase line + one sale line for a fresh item — the
     * minimum activity needed for it to show real numbers on the
     * report, built directly against the tables rather than through
     * the HTTP forms since what's under test is the report's SQL,
     * not the create flows (those are covered by
     * AccrualAccountingCycleTest and the app's own request tests).
     */
    private function makeTradingItemWithActivity(string $name): Item
    {
        $item = Item::create([
            'company_id' => $this->company->id, 'name' => $name,
            'type' => 'trading', 'base_unit_name' => 'unit',
        ]);

        $purchase = InventoryPurchase::create([
            'company_id' => $this->company->id, 'vendor_id' => $this->vendor->id,
            'date' => '2026-04-01', 'subtotal' => 100, 'vat_rate' => 0, 'vat_amount' => 0, 'amount' => 100,
        ]);
        $purchase->lines()->create([
            'item_id' => $item->id, 'qty' => 10, 'uom' => 'unit', 'qty_per_uom' => 1,
            'base_unit_name' => 'unit', 'unit_price' => 10, 'line_total' => 100,
        ]);

        $sale = Sale::create([
            'company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'date' => '2026-04-05',
            'subtotal' => 60, 'vat_rate' => 0, 'vat_amount' => 0, 'amount' => 60,
        ]);
        $sale->lines()->create(['item_id' => $item->id, 'qty' => 6, 'unit_price' => 10, 'line_total' => 60]);

        return $item;
    }

    private function countInventoryStatementQueries(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get(route('app.reports.inventory-statement'))->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_the_inventory_statement_costs_the_same_for_one_item_as_for_fifteen(): void
    {
        $this->makeTradingItemWithActivity('Item 1');

        // Prime first — the very first request of a session also
        // resolves auth/company middleware queries that would
        // otherwise be counted as if they were per-item cost.
        $this->countInventoryStatementQueries();

        $one = $this->countInventoryStatementQueries();

        for ($i = 2; $i <= 15; $i++) {
            $this->makeTradingItemWithActivity("Item {$i}");
        }

        $fifteen = $this->countInventoryStatementQueries();

        $this->assertSame(
            $one,
            $fifteen,
            "The inventory statement used {$one} queries for 1 item and {$fifteen} for 15 — it is querying per item."
        );
    }

    /**
     * The report's bulk-computed numbers must equal what
     * Item::currentStock() / Item::averagePurchaseCost() say for the
     * SAME item, one at a time — the canonical, already-trusted
     * definition used by GuardsRawMaterialStock and
     * SaleController::postCogsForLines(). A mismatch here means the
     * report's batch query and the model's per-item formula have
     * drifted apart, which is exactly the failure mode
     * production-cycle_EN.md §12 described.
     */
    public function test_inventory_statement_totals_match_the_item_models_own_methods_in_a_mixed_scenario(): void
    {
        $rawItems = [];
        $productItems = [];

        for ($i = 1; $i <= 3; $i++) {
            $raw = Item::create([
                'company_id' => $this->company->id, 'name' => "Raw {$i}",
                'type' => 'raw_material', 'base_unit_name' => 'kg',
            ]);

            // Two purchases at different prices, like the Flour example.
            foreach ([['2026-04-01', 100, 10], ['2026-04-10', 50, 12]] as [$date, $qty, $price]) {
                $purchase = InventoryPurchase::create([
                    'company_id' => $this->company->id, 'vendor_id' => $this->vendor->id,
                    'date' => $date, 'subtotal' => $qty * $price, 'vat_rate' => 0,
                    'vat_amount' => 0, 'amount' => $qty * $price,
                ]);
                $purchase->lines()->create([
                    'item_id' => $raw->id, 'qty' => $qty, 'uom' => 'kg', 'qty_per_uom' => 1,
                    'base_unit_name' => 'kg', 'unit_price' => $price, 'line_total' => $qty * $price,
                ]);
            }

            $rawItems[] = $raw;

            $product = Item::create([
                'company_id' => $this->company->id, 'name' => "Product {$i}",
                'type' => 'product', 'base_unit_name' => 'unit',
            ]);

            // Produced by consuming some of the raw material.
            $order = ProductionOrder::create([
                'company_id' => $this->company->id, 'item_id' => $product->id,
                'date' => '2026-04-15', 'qty_produced' => 40,
                'material_cost' => 400, 'labor_cost' => 80, 'other_cost_total' => 0,
                'total_cost' => 480, 'unit_cost' => 12,
            ]);
            $order->materialLines()->create([
                'item_id' => $raw->id, 'qty' => 30, 'unit_cost_snapshot' => 10, 'line_total' => 300,
            ]);

            // Partially sold.
            $sale = Sale::create([
                'company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'date' => '2026-04-20',
                'subtotal' => 300, 'vat_rate' => 0, 'vat_amount' => 0, 'amount' => 300,
            ]);
            $sale->lines()->create(['item_id' => $product->id, 'qty' => 25, 'unit_price' => 12, 'line_total' => 300]);

            $productItems[] = $product;
        }

        $reports = app(ReportDataService::class);
        $byId = $reports->inventoryStatement(null, null, '2026-04-30')['items']->keyBy('id');

        foreach (array_merge($rawItems, $productItems) as $item) {
            $row = $byId->get($item->id);

            $this->assertNotNull($row, "Item {$item->name} is missing from the report.");

            $this->assertEqualsWithDelta(
                $item->currentStock('2026-04-30'), $row['current_stock'], 0.001,
                "current_stock for {$item->name} does not match Item::currentStock()."
            );

            $expectedAvg = $item->averagePurchaseCost('2026-04-30');
            if ($expectedAvg === null) {
                $this->assertNull($row['avg_purchase_cost'], "avg_purchase_cost for {$item->name} should be null.");
            } else {
                $this->assertEqualsWithDelta(
                    $expectedAvg, $row['avg_purchase_cost'], 0.001,
                    "avg_purchase_cost for {$item->name} does not match Item::averagePurchaseCost()."
                );
            }
        }
    }
}
