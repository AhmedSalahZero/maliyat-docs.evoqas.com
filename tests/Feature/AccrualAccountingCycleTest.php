<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Sale;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Reports\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  Runs the exact bakery scenario from production-cycle_EN.md end to
//  end — through the real HTTP routes, exactly as a user would use
//  the app — then asserts the Trial Balance, the (accrual) Profit &
//  Loss report, and the Inventory Statement report all agree with
//  the figures the doc claims and with each other.
//
//  This exists because the three fixes made together (accrual P&L,
//  the Inventory Statement rewrite reading Item's own stock methods,
//  and the underlying ledger itself) had NO test protecting them —
//  a future edit could silently reintroduce a cash-basis P&L, or let
//  the Inventory Statement drift back out of step with the ledger,
//  and nothing would fail. This test is that guard.
// ══════════════════════════════════════════════════════════════════
class AccrualAccountingCycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_bakery_scenario_ties_the_ledger_the_pl_and_the_inventory_report_together(): void
    {
        $company = Company::factory()->create(['business_types' => ['trading', 'production']]);
        $admin   = User::factory()->companyAdmin($company)->create();

        $flourVendor  = Vendor::create(['company_id' => $company->id, 'name' => 'Flour Supplier']);
        $payrollVendor = Vendor::create(['company_id' => $company->id, 'name' => 'Bakery Staff', 'type' => 'employee']);
        $customer     = Customer::create(['company_id' => $company->id, 'name' => 'Corner Cafe']);
        $payrollCategory = Category::create(['company_id' => $company->id, 'name' => 'Payroll', 'kind' => 'expense']);

        $flour = Item::create(['company_id' => $company->id, 'name' => 'Flour', 'type' => 'raw_material', 'base_unit_name' => 'kg']);
        $bread = Item::create(['company_id' => $company->id, 'name' => 'Bread', 'type' => 'product', 'base_unit_name' => 'loaf']);

        $this->actingAs($admin);

        // ── 2 April — first flour purchase: 10 sacks @ 250, 25kg/sack ──
        $this->post(route('app.inventory-purchases.store'), [
            'vendor_id' => $flourVendor->id,
            'date'      => '2026-04-02',
            'lines'     => [[
                'item_id' => $flour->id, 'qty' => 10, 'unit_price' => 250,
                'qty_per_uom' => 25, 'uom' => 'Sack', 'base_unit_name' => 'kg',
            ]],
            'vat_rate' => 14,
            'mode'     => 'later',
        ])->assertSessionHasNoErrors()->assertRedirect();

        // ── 9 April — second flour purchase: 5 sacks @ 280 ──
        $this->post(route('app.inventory-purchases.store'), [
            'vendor_id' => $flourVendor->id,
            'date'      => '2026-04-09',
            'lines'     => [[
                'item_id' => $flour->id, 'qty' => 5, 'unit_price' => 280,
                'qty_per_uom' => 25, 'uom' => 'Sack', 'base_unit_name' => 'kg',
            ]],
            'vat_rate' => 14,
            'mode'     => 'later',
        ])->assertSessionHasNoErrors()->assertRedirect();

        // ── 12 April — production run: 300 loaves, 150kg flour, labor 400 ──
        $this->post(route('app.production-orders.store'), [
            'item_id'      => $bread->id,
            'date'         => '2026-04-12',
            'qty_produced' => 300,
            'materials'    => [['item_id' => $flour->id, 'qty' => 150]],
            'labor_cost'   => 400,
        ])->assertSessionHasNoErrors()->assertRedirect();

        // ── 20 April — sale: 200 loaves @ 12, on credit ──
        $this->post(route('app.sales.store'), [
            'customer_id' => $customer->id,
            'date'        => '2026-04-20',
            'lines'       => [['item_id' => $bread->id, 'qty' => 200, 'unit_price' => 12]],
            'vat_rate'    => 14,
            'mode'        => 'later',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $sale = Sale::sole();

        // ── 25 April — 1,500 collected in cash against that invoice ──
        $this->post(route('app.payments.receive'), [
            'sale_id' => $sale->id,
            'date'    => '2026-04-25',
            'amount'  => 1500,
            'method'  => 'cash',
        ])->assertSessionHasNoErrors()->assertRedirect();

        // ── 28 April — real payroll: 450, ticked "Production Labor" ──
        $this->post(route('app.expenses.store'), [
            'vendor_id'   => $payrollVendor->id,
            'category_id' => $payrollCategory->id,
            'date'        => '2026-04-28',
            'amount'      => 450,
            'is_production_labor' => true,
            'mode'        => 'later',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $reports = app(ReportDataService::class);

        // ── The Trial Balance (§10 of the doc) ──────────────────────
        // from = well before any of this scenario's postings, so
        // Opening Balance is zero for every account and Closing
        // Balance equals what the old single-date trial balance used
        // to call debit_balance/credit_balance.
        $trialBalance = $reports->trialBalance('2026-01-01', '2026-04-30');

        $this->assertTrue($trialBalance['is_balanced'], 'The trial balance does not balance.');
        $this->assertEqualsWithDelta(7632.00, $trialBalance['total_closing_debit'], 0.01);
        $this->assertEqualsWithDelta(7632.00, $trialBalance['total_closing_credit'], 0.01);

        $byCode = collect($trialBalance['rows'])->keyBy('code');
        $this->assertEqualsWithDelta(2993.33, $byCode['1200']['closing_debit'], 0.01, 'Inventory account balance is wrong.');
        $this->assertEqualsWithDelta(1356.67, $byCode['5000']['closing_debit'], 0.01, 'Cost of Goods Sold account balance is wrong.');
        $this->assertEqualsWithDelta(0.0, $byCode['2200']['closing_debit'], 0.01, 'Production Labor Accrued should net to zero once wages are settled.');
        $this->assertEqualsWithDelta(0.0, $byCode['2200']['closing_credit'], 0.01);

        // ── The Profit & Loss report — must be ACCRUAL, and must tie
        //    to the Trial Balance above, not to what was collected ──
        $pl = $reports->profitAndLoss('2026-04-01', '2026-04-30');

        $this->assertEqualsWithDelta(2400.00, $pl['revenue'], 0.01);
        $this->assertEqualsWithDelta(1356.67, $pl['cost_of_goods_sold'], 0.01);
        $this->assertEqualsWithDelta(1043.33, $pl['gross_profit'], 0.01);
        $this->assertEqualsWithDelta(0.0, $pl['operating_expenses'], 0.01);
        $this->assertEqualsWithDelta(1043.33, $pl['net_profit'], 0.01);

        // Revenue must NOT equal the 1,500 actually collected — that
        // would mean the report regressed back to cash basis.
        $this->assertGreaterThan(
            0.01,
            abs($pl['revenue'] - 1500.00),
            'Revenue equals the amount collected in cash — the P&L report has regressed to cash basis.'
        );

        // ── The Inventory Statement — must tie to Inventory (1200) ──
        $inventory = $reports->inventoryStatement(null, null, '2026-04-30');

        $this->assertEqualsWithDelta(2993.33, $inventory['total_stock_value'], 0.01);

        $byName = collect($inventory['items'])->keyBy('name');

        $this->assertEqualsWithDelta(225.0, $byName['Flour']['current_stock'], 0.01);
        $this->assertEqualsWithDelta(10.40, $byName['Flour']['avg_purchase_cost'], 0.01);
        $this->assertEqualsWithDelta(2340.00, $byName['Flour']['stock_value'], 0.01);
        $this->assertFalse($byName['Flour']['is_negative']);

        $this->assertEqualsWithDelta(100.0, $byName['Bread']['current_stock'], 0.01);
        $this->assertEqualsWithDelta(6.5333, $byName['Bread']['avg_purchase_cost'], 0.001);
        $this->assertEqualsWithDelta(653.33, $byName['Bread']['stock_value'], 0.01);

        // These are exactly the two symptoms production-cycle_EN.md
        // §12.3 documented as the bug: Bread showing negative stock,
        // and its average cost showing blank.
        $this->assertFalse($byName['Bread']['is_negative'], 'Bread should not show negative stock — it was produced, not only sold.');
        $this->assertFalse($byName['Bread']['is_out_of_stock']);
        $this->assertNotNull($byName['Bread']['avg_purchase_cost'], 'Bread\'s average cost should not be blank — it was produced with a real cost.');

        // ── The drill-down history must show production activity too ──
        $breadHistory = $reports->inventoryStatement($byName['Bread']['id'], null, '2026-04-30')['history'];
        $this->assertTrue($breadHistory->contains(fn ($row) => $row['type'] === 'produced' && $row['qty'] === 300.0));
        $this->assertTrue($breadHistory->contains(fn ($row) => $row['type'] === 'sale' && $row['qty'] === -200.0));

        $flourHistory = $reports->inventoryStatement($byName['Flour']['id'], null, '2026-04-30')['history'];
        $this->assertTrue($flourHistory->contains(fn ($row) => $row['type'] === 'consumed' && $row['qty'] === -150.0));
    }
}
