<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\InventoryPurchase;
use App\Models\InventoryStockLedger;
use App\Models\Item;
use App\Models\ProductionOrder;
use App\Models\Sale;
use App\Models\User;
use App\Models\Vendor;
use App\Services\MovingAverageCostingService;
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
//     rest of the app (production-cycle_EN.md §12). It was later
//     rewritten to independently re-derive an average cost — the
//     same formula the (now-removed) Item::averagePurchaseCost()
//     used — which turned out to be a SECOND way of getting cost
//     wrong: it never subtracted what had already been sold. The
//     report now reads its cost figures (avg_purchase_cost,
//     stock_value) straight from InventoryStockLedger — the same
//     table MovingAverageCostingService maintains and actually
//     prices every sale's Cost of Goods Sold against — so this test
//     proves the report's numbers equal that ledger's own numbers
//     for every item in a mixed purchase/production/sale/consumption
//     scenario, rather than a second, separately-computed guess at
//     them (quantities — current_stock — are unaffected by any of
//     this and are still checked against Item::currentStock(), which
//     was never wrong).
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
     * The report's bulk-computed numbers must equal what the real
     * costing engine says for the SAME item — Item::currentStock()
     * for quantity (the canonical, already-trusted definition used
     * by GuardsRawMaterialStock and SaleController), and
     * InventoryStockLedger's own latest row for cost (the table
     * MovingAverageCostingService maintains and actually prices
     * every sale's Cost of Goods Sold against). A mismatch here
     * means the report's batch query and the real engine have
     * drifted apart, which is exactly the failure mode
     * production-cycle_EN.md §12 described.
     */
    public function test_inventory_statement_totals_match_the_item_models_own_methods_in_a_mixed_scenario(): void
    {
        $rawItems = [];
        $productItems = [];

        foreach (range(1, 3) as $i) {
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

            // Produced by consuming some of the raw material. Costs
            // start as placeholders (0 material_cost, unit_cost_snapshot
            // 0) exactly like a real ProductionOrderService::create()
            // call leaves them — pricing them for real is exactly
            // what the onItemMovementChanged() call below does, the
            // same way ProductionOrderService itself hands off to it.
            $order = ProductionOrder::create([
                'company_id' => $this->company->id, 'item_id' => $product->id,
                'date' => '2026-04-15', 'qty_produced' => 40,
                'material_cost' => 0, 'labor_cost' => 80, 'other_cost_total' => 0,
                'total_cost' => 80, 'unit_cost' => 2,
            ]);
            $order->materialLines()->create([
                'item_id' => $raw->id, 'qty' => 30, 'unit_cost_snapshot' => 0, 'line_total' => 0,
            ]);

            // Partially sold. unit_cost starts null, same as a real
            // SaleController::store() leaves it before recalculateCostsFor().
            $sale = Sale::create([
                'company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'date' => '2026-04-20',
                'subtotal' => 300, 'vat_rate' => 0, 'vat_amount' => 0, 'amount' => 300,
            ]);
            $sale->lines()->create(['item_id' => $product->id, 'qty' => 25, 'unit_price' => 12, 'line_total' => 300]);

            $productItems[] = $product;

            // The one step this test scenario needs that the old
            // version didn't: actually run the costing engine, the
            // same way a real purchase/sale/production order
            // triggers it. Called once, from before every movement,
            // on the RAW item only — recalculateItem() reads inbound
            // purchases directly from the source table (not from
            // prior ledger rows), and pricing the raw item's material
            // line cascades automatically into repricing the
            // production order and then the product item's own pool
            // (see MovingAverageCostingService::repriceProductionOrder()).
            app(MovingAverageCostingService::class)->onItemMovementChanged($this->company->id, $raw->id, '2026-01-01');
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

            $ledgerRow = InventoryStockLedger::query()
                ->where('item_id', $item->id)
                ->where('date', '<=', '2026-04-30')
                ->orderByDesc('date')
                ->first();

            if (! $ledgerRow) {
                $this->assertNull($row['avg_purchase_cost'], "avg_purchase_cost for {$item->name} should be null.");
                $this->assertSame(0.0, $row['stock_value'], "stock_value for {$item->name} should be 0.");

                continue;
            }

            $this->assertEqualsWithDelta(
                (float) $ledgerRow->average_cost, $row['avg_purchase_cost'], 0.001,
                "avg_purchase_cost for {$item->name} does not match its InventoryStockLedger row."
            );
            $this->assertEqualsWithDelta(
                (float) $ledgerRow->ending_value, $row['stock_value'], 0.001,
                "stock_value for {$item->name} does not match its InventoryStockLedger row."
            );
        }
    }
}
