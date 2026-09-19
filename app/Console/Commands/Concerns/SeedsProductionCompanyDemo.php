<?php

namespace App\Console\Commands\Concerns;

use App\Models\ProductionOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — SeedsProductionCompanyDemo
//  Location: app/Console/Commands/Concerns/SeedsProductionCompanyDemo.php
//
//  One of three traits SeedThreeDemoCompanies is split across (see
//  that command's own class doc comment for why) — this one builds
//  Company 3, the production company (Al-Ahram Furniture
//  Manufacturing). Every raw-material/finished-good cost figure it
//  produces goes through recordProductionOrder(), which in turn
//  calls the real ProductionOrderService — so if the production
//  -costing logic in MovingAverageCostingService ever changes, this
//  demo data picks it up automatically, with nothing to update here.
// ══════════════════════════════════════════════════════════════════
trait SeedsProductionCompanyDemo
{
    private function seedProductionCompany(): void
    {
        $key = self::COMPANY_NAMES['production'];

        ['company' => $company, 'channels' => $channels, 'sales_channels' => $salesChannels] = $this->bootCompany(
            name: $key,
            businessTypes: ['production'],
            adminName: 'Hassan El-Sayed',
            adminEmail: 'admin@alahram-demo.com',
            employeeDefs: [
                ['name' => 'Rania Farouk', 'email' => 'rania@alahram-demo.com'],
                ['name' => 'Amr Gaber', 'email' => 'amr@alahram-demo.com'],
            ],
            channelNames: ['Bank - QNB Current Account', 'InstaPay', 'Vodafone Cash'],
        );

        $customers = $this->makeCustomers($company->id, [
            'Home Elegance Furniture Store', 'Maadi Interior Design Studio', 'New Cairo Villas Developer',
            'Zamalek Furniture Gallery', 'October City Furniture Mart', 'Nasr City Home Decor',
            'Heliopolis Hotel Group', 'Giza Real Estate Developers', 'Alexandria Furniture Retail',
            'Downtown Office Outfitters', '6th of October Showroom', 'Mansoura Home Furnishings',
        ]);

        $vendors = $this->makeVendors($company->id, [
            'Nile Timber Trading', 'Delta Foam & Upholstery', 'Cairo Hardware Fasteners',
            'Alexandria Wood Supply', 'October Paints & Varnish',
        ]);
        $payrollVendor = $this->makeVendors($company->id, ['Staff Payroll'], 'employee')->first();
        $custodyHolders = $this->makeVendors($company->id, ['Sami Ragab', 'Dina Fathy'], 'employee');

        $this->addCategory($company->id, 'Production Wages', 'expense');
        $this->addCategory($company->id, 'Maintenance', 'expense');

        // Same derivation as the trading catalog above: purchase_qty
        // is sized from actual expected consumption per recipe x how
        // often each material gets restocked, not guessed
        // independently of demand.
        $rawMaterials = $this->makeItems($company->id, [
            ['name' => 'Pine Wood Plank', 'type' => 'raw_material', 'uom' => 'Sqm', 'buy' => [90, 110], 'purchase_qty' => [16.6, 31.2]],
            ['name' => 'Oak Wood Plank', 'type' => 'raw_material', 'uom' => 'Sqm', 'buy' => [220, 260], 'purchase_qty' => [12.3, 23]],
            ['name' => 'MDF Board', 'type' => 'raw_material', 'uom' => 'Sheet', 'buy' => [140, 170], 'purchase_qty' => [17, 31.8]],
            ['name' => 'Plywood Sheet', 'type' => 'raw_material', 'uom' => 'Sheet', 'buy' => [160, 190], 'purchase_qty' => [15.6, 29.2]],
            ['name' => 'Foam Padding', 'type' => 'raw_material', 'uom' => 'Sqm', 'buy' => [60, 80], 'purchase_qty' => [9.7, 18.1]],
            ['name' => 'Upholstery Fabric', 'type' => 'raw_material', 'uom' => 'Meter', 'buy' => [110, 150], 'purchase_qty' => [12.9, 24.1]],
            ['name' => 'Wood Screws', 'type' => 'raw_material', 'uom' => 'Box', 'buy' => [40, 55], 'purchase_qty' => [6.9, 12.9]],
            ['name' => 'Metal Hinges', 'type' => 'raw_material', 'uom' => 'Piece', 'buy' => [8, 14], 'purchase_qty' => [21.7, 40.7]],
            ['name' => 'Wood Glue', 'type' => 'raw_material', 'uom' => 'Liter', 'buy' => [65, 85], 'purchase_qty' => [1.1, 2]],
            ['name' => 'Varnish', 'type' => 'raw_material', 'uom' => 'Liter', 'buy' => [95, 120], 'purchase_qty' => [5.6, 10.5]],
            ['name' => 'Sandpaper', 'type' => 'raw_material', 'uom' => 'Sheet', 'buy' => [5, 9], 'purchase_qty' => [13.1, 24.5]],
            ['name' => 'Metal Legs Set', 'type' => 'raw_material', 'uom' => 'Set', 'buy' => [180, 230], 'purchase_qty' => [8.6, 16.2]],
        ]);
        $rawByName = collect($rawMaterials)->keyBy(fn ($row) => $row['item']->name)->map(fn ($row) => $row['item']);

        // name => [sell range, labor per unit, qty-per-run range, recipe (material name => qty consumed per unit)]
        $productDefs = [
            'Dining Table'        => [[4500, 6500], 250, [3, 8], ['Oak Wood Plank' => 3.0, 'Metal Legs Set' => 1.0, 'Varnish' => 0.4, 'Wood Screws' => 0.3]],
            'Office Desk'         => [[3200, 4500], 200, [3, 8], ['MDF Board' => 2.5, 'Metal Legs Set' => 1.0, 'Varnish' => 0.3, 'Wood Screws' => 0.25]],
            'Bookshelf Unit'      => [[2800, 3800], 180, [3, 10], ['Plywood Sheet' => 3.0, 'Wood Screws' => 0.4, 'Sandpaper' => 2.0, 'Varnish' => 0.25, 'Wood Glue' => 0.2]],
            'Wooden Dining Chair' => [[950, 1400], 80, [6, 20], ['Pine Wood Plank' => 1.2, 'Wood Screws' => 0.15, 'Varnish' => 0.1, 'Sandpaper' => 1.0]],
            'Sofa Frame 3-Seater' => [[6500, 9000], 400, [2, 6], ['Pine Wood Plank' => 4.0, 'Foam Padding' => 6.0, 'Upholstery Fabric' => 8.0, 'Wood Screws' => 0.5]],
            'Bed Frame Queen'     => [[5200, 7200], 280, [2, 6], ['Oak Wood Plank' => 3.5, 'Metal Legs Set' => 1.0, 'Wood Screws' => 0.4, 'Varnish' => 0.35]],
            'Wardrobe 3-Door'     => [[7500, 10500], 350, [2, 5], ['MDF Board' => 5.0, 'Metal Hinges' => 6.0, 'Wood Screws' => 0.6, 'Varnish' => 0.5]],
            'TV Stand Unit'       => [[2200, 3200], 150, [3, 8], ['MDF Board' => 2.0, 'Metal Hinges' => 2.0, 'Wood Screws' => 0.3, 'Varnish' => 0.2, 'Wood Glue' => 0.1]],
            'Coffee Table'        => [[1600, 2300], 130, [3, 10], ['Pine Wood Plank' => 1.5, 'Metal Legs Set' => 1.0, 'Varnish' => 0.2, 'Sandpaper' => 1.0]],
            'Kitchen Cabinet Unit'=> [[3800, 5200], 220, [3, 8], ['Plywood Sheet' => 3.5, 'Metal Hinges' => 4.0, 'Wood Screws' => 0.4, 'Varnish' => 0.3, 'Wood Glue' => 0.15]],
        ];

        $productItems = $this->makeItems($company->id, collect($productDefs)->map(fn ($def, $name) => [
            'name' => $name, 'type' => 'product', 'uom' => 'Piece', 'sell' => $def[0], 'sale_qty' => [1, 3],
        ])->values()->all());
        $productsByName = collect($productItems)->keyBy(fn ($row) => $row['item']->name);
        // Products are only ever made, not bought, so their catalog
        // entry carries no 'buy' range — recordSale only reads 'sell'.
        $productCatalog = $productItems;

        $machineCat = $this->equipmentCategory($company->id, 'Machine');
        $vehicleCat = $this->equipmentCategory($company->id, 'Vehicle');
        $otherEquipCat = $this->equipmentCategory($company->id, 'Other');

        $openingDate = Carbon::parse(self::START_DATE);
        $stock = [];

        $this->openingBalances->submit($company->id, [
            'opening_date' => $openingDate->toDateString(),
            'cash_amount' => 40000, 'bank_amount' => 280000,
            'customers' => [
                ['customer_id' => $customers[0]->id, 'amount' => 12000],
                ['customer_id' => $customers[2]->id, 'amount' => 21000],
            ],
            'suppliers' => [
                ['vendor_id' => $vendors[0]->id, 'amount' => 9500],
            ],
            'inventory' => [
                ['item_id' => $rawByName['Pine Wood Plank']->id, 'qty' => 60, 'unit_price' => 95],
                ['item_id' => $rawByName['MDF Board']->id, 'qty' => 40, 'unit_price' => 150],
            ],
            'equipment' => [
                ['name' => 'Workshop Machinery (Starting)', 'category_id' => $machineCat->id, 'amount' => 220000, 'date' => $openingDate->toDateString()],
            ],
        ]);
        $this->line('  · opening balance posted');

        // Guaranteed initial raw-material stock-up before any
        // production runs are scheduled.
        $this->recordPurchase($company, $key, collect([$vendors[0], $vendors[3]]), $rawMaterials, $channels, $openingDate->copy()->addDays(2), $stock, lineCount: 8, qtyMultiplier: 3.0);
        $this->recordPurchase($company, $key, collect([$vendors[1], $vendors[2], $vendors[4]]), $rawMaterials, $channels, $openingDate->copy()->addDays(4), $stock, lineCount: 8, qtyMultiplier: 3.0);

        $this->recordRecurringSeries($company, $key, $vendors[0], $this->expenseCategory($company->id, 'Rent'), $channels, $openingDate->copy()->addDays(3), 28000, 20, 'Workshop rent');
        $this->recordRecurringSeries($company, $key, $payrollVendor, $this->expenseCategory($company->id, 'Salaries'), $channels, $openingDate->copy()->addDays(25), 40000, 20, 'Admin & sales staff salaries');

        $equipmentPlan = [
            [40, 'Wood Cutting Machine', 180000, $machineCat, 10],
            [200, 'Delivery Truck', 350000, $vehicleCat, 5],
            [350, 'Sanding & Finishing Station', 95000, $machineCat, 10],
            [500, 'Workshop Tools Set', 22000, $otherEquipCat, 5],
        ];
        foreach ($equipmentPlan as [$dayOffset, $name, $amount, $cat, $life]) {
            $this->recordEquipment($company, $key, $vendors->random(), $cat, $channels, $openingDate->copy()->addDays($dayOffset), $name, $amount, $life);
        }

        $totalDays = $this->totalDays();
        $weights = $this->dailyWeights($totalDays, dampenWeekend: true);
        $saleCounts = $this->spreadCounts($totalDays, 450, $weights);
        $purchaseCounts = $this->spreadCounts($totalDays, 118, array_fill(0, $totalDays, 1.0));
        $productionCounts = $this->spreadCounts($totalDays, 190, $weights);
        $expenseCounts = $this->spreadCounts($totalDays, 90, array_fill(0, $totalDays, 1.0));

        $expenseCats = collect(['Maintenance', 'Utilities', 'Office Supplies', 'Other'])
            ->map(fn ($n) => $this->expenseCategory($company->id, $n));
        $spendCats = collect(['Maintenance', 'Other'])
            ->map(fn ($n) => $this->expenseCategory($company->id, $n))->all();
        $laborCategory = $this->expenseCategory($company->id, 'Production Wages');
        $productNames = array_keys($productDefs);

        $bar = $this->output->createProgressBar($totalDays);
        $bar->start();
        $lastLaborMonth = null;

        for ($d = 0; $d < $totalDays; $d++) {
            $date = $this->dateAt($d);

            for ($i = 0; $i < $purchaseCounts[$d]; $i++) {
                $this->recordPurchase($company, $key, $vendors, $rawMaterials, $channels, $date, $stock, lineCount: mt_rand(3, 6));
            }

            for ($i = 0; $i < $productionCounts[$d]; $i++) {
                $productName = $this->pick($productNames);
                [$sellRange, $laborPerUnit, $qtyRange, $recipe] = $productDefs[$productName];
                $recipeSpec = collect($recipe)->map(fn ($qty) => ['qty_per_unit' => $qty])->all();
                $qtyProduced = round($this->qty($qtyRange[0], $qtyRange[1]));
                $this->recordProductionOrder(
                    $company, $key, $productsByName[$productName]['item'], $recipeSpec, $rawByName->all(),
                    $date, $qtyProduced, $laborPerUnit, $stock,
                );
            }

            for ($i = 0; $i < $saleCounts[$d]; $i++) {
                $this->recordSale($company, $key, $customers, $productCatalog, $channels, $salesChannels, $date, $stock, trackStock: true, allowInstallment: true, installmentThreshold: 12000);
            }

            for ($i = 0; $i < $expenseCounts[$d]; $i++) {
                $cat = $expenseCats->random();
                $amount = match ($cat->name) {
                    'Maintenance' => $this->money(500, 4500),
                    'Utilities' => $this->money(1000, 5000),
                    'Office Supplies' => $this->money(150, 1000),
                    default => $this->money(200, 2000),
                };
                $this->recordExpense($company, $key, $vendors->random(), $cat, $channels, $date, $amount);
            }

            // Production-labor payroll, posted once per calendar
            // month on the 28th — reconciles the month's estimated
            // labor (accrued by the production orders above) against
            // what was actually paid. See recordProductionLaborExpense().
            if ($date->day === 28 && $date->format('Y-m') !== $lastLaborMonth) {
                $lastLaborMonth = $date->format('Y-m');
                $estimate = ProductionOrder::totalLaborForMonth($company->id, $date->toDateString());
                if ($estimate > 0) {
                    $actual = round($estimate * (mt_rand(92, 108) / 100), 2);
                    $this->recordProductionLaborExpense($company, $key, $payrollVendor, $laborCategory, $channels, $date, $actual);
                }
            }

            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        for ($i = 0; $i < 12; $i++) {
            $date = $openingDate->copy()->addDays(15 + (int) round($i * ($totalDays - 30) / 12));
            $this->recordCustodyCycle($company, $key, $custodyHolders->random(), $spendCats, $date, $this->money(600, 2800));
        }

        // Small, quick showroom sales — same recordSale() as every
        // other sale in this company, always with a real product and
        // a real sales channel attached. This used to be a fake
        // "cash received, no invoice, no product" receipt
        // (recordStandaloneReceipt) — removed because a sale can
        // never exist without a product now, matching the real app:
        // "Cash Sales" moved under the Sales tab, where a product is
        // required.
        for ($i = 0; $i < 10; $i++) {
            $date = $openingDate->copy()->addDays(22 + (int) round($i * ($totalDays - 44) / 10));
            $this->recordSale($company, $key, $customers, $productCatalog, $channels, $salesChannels, $date, $stock, trackStock: true, allowInstallment: false);
        }
        // Small, quick petty-cash-style expenses — same recordExpense()
        // as every other expense, always with a real category. This
        // used to be a fake "cash paid, no bill, no category" payment
        // (recordStandalonePayment) — removed for the same reason:
        // "cash expenses" moved under the Expense tab, where a
        // category is required.
        for ($i = 0; $i < 15; $i++) {
            $date = $openingDate->copy()->addDays(9 + (int) round($i * ($totalDays - 18) / 15));
            $this->recordExpense($company, $key, null, $this->expenseCategory($company->id, 'Other'), $channels, $date, $this->money(100, 900));
        }

        Auth::logout();
    }
}
