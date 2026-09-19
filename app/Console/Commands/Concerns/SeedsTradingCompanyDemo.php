<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — SeedsTradingCompanyDemo
//  Location: app/Console/Commands/Concerns/SeedsTradingCompanyDemo.php
//
//  One of three traits SeedThreeDemoCompanies is split across (see
//  that command's own class doc comment for why) — this one builds
//  Company 2, the trading company (Nile Star Trading Co.).
// ══════════════════════════════════════════════════════════════════
trait SeedsTradingCompanyDemo
{
    private function seedTradingCompany(): void
    {
        $key = self::COMPANY_NAMES['trading'];

        ['company' => $company, 'channels' => $channels, 'sales_channels' => $salesChannels] = $this->bootCompany(
            name: $key,
            businessTypes: ['trading'],
            adminName: 'Mostafa Nile',
            adminEmail: 'admin@nilestar-demo.com',
            employeeDefs: [
                ['name' => 'Aya Hassan', 'email' => 'aya@nilestar-demo.com'],
                ['name' => 'Karim Adel', 'email' => 'karim@nilestar-demo.com'],
            ],
            channelNames: ['Bank - NBE Current Account', 'InstaPay', 'Vodafone Cash'],
        );

        $customers = $this->makeCustomers($company->id, [
            'Al Waha Contracting', 'Horizon Building Materials Retail', 'Delta Construction Group',
            'Cedar Home Improvement', 'Blue Nile Hardware Store', 'Sahara Builders', 'New Cairo Developments',
            'Zamalek Renovations', 'Maadi Trading Post', 'October City Supplies', 'Nasr City Hardware',
            'Heliopolis Construction Co', '6th of October Builders', 'Shubra Trading House', 'Mansoura Building Supplies',
        ]);

        $vendors = $this->makeVendors($company->id, [
            'Nile Cement Co', 'Eastern Steel Mills', 'Cairo Ceramics Factory',
            'Delta Paints Factory', 'Alexandria Electrical Supplies', 'Giza Plumbing Wholesale',
        ]);
        $payrollVendor = $this->makeVendors($company->id, ['Staff Payroll'], 'employee')->first();
        $custodyHolders = $this->makeVendors($company->id, ['Sayed Abdo', 'Hany Zaki'], 'employee');

        $this->addCategory($company->id, 'Transport', 'expense');
        $this->addCategory($company->id, 'Marketing', 'expense');

        // purchase_qty ranges below are DERIVED, not guessed — sized
        // so total purchase volume tracks total sale volume instead
        // of dwarfing it (see the seeder's design notes: with items
        // picked uniformly at random for both sales and purchases,
        // a batch that's much larger than "sale_qty x how much more
        // often this item sells than gets restocked" silently buys
        // several times more stock than the business could ever
        // sell, which is what drove cash and the P&L deeply negative
        // in an earlier version of this file).
        $items = $this->makeItems($company->id, [
            ['name' => 'Portland Cement 50kg', 'type' => 'trading', 'uom' => 'Bag', 'buy' => [130, 150], 'sell' => [165, 185], 'purchase_qty' => [22.5, 42.2], 'sale_qty' => [5, 60]],
            ['name' => 'Steel Rebar 12mm', 'type' => 'trading', 'uom' => 'Ton', 'buy' => [24000, 26000], 'sell' => [28500, 30500], 'purchase_qty' => [1.56, 2.92], 'sale_qty' => [0.5, 4]],
            ['name' => 'Steel Rebar 16mm', 'type' => 'trading', 'uom' => 'Ton', 'buy' => [24500, 26500], 'sell' => [29000, 31000], 'purchase_qty' => [1.56, 2.92], 'sale_qty' => [0.5, 4]],
            ['name' => 'Ceramic Floor Tile 60x60', 'type' => 'trading', 'uom' => 'Sqm', 'buy' => [90, 110], 'sell' => [150, 172], 'purchase_qty' => [31.2, 58.5], 'sale_qty' => [10, 80]],
            ['name' => 'Ceramic Wall Tile 30x60', 'type' => 'trading', 'uom' => 'Sqm', 'buy' => [70, 85], 'sell' => [118, 138], 'purchase_qty' => [24.2, 45.5], 'sale_qty' => [10, 60]],
            ['name' => 'White Emulsion Paint 20L', 'type' => 'trading', 'uom' => 'Bucket', 'buy' => [900, 1000], 'sell' => [1300, 1450], 'purchase_qty' => [4.5, 8.4], 'sale_qty' => [1, 12]],
            ['name' => 'Exterior Paint 20L', 'type' => 'trading', 'uom' => 'Bucket', 'buy' => [1100, 1250], 'sell' => [1550, 1750], 'purchase_qty' => [3.8, 7.1], 'sale_qty' => [1, 10]],
            ['name' => 'PVC Pipe 4in', 'type' => 'trading', 'uom' => 'Piece', 'buy' => [180, 210], 'sell' => [300, 340], 'purchase_qty' => [11.1, 20.8], 'sale_qty' => [2, 30]],
            ['name' => 'PVC Pipe 2in', 'type' => 'trading', 'uom' => 'Piece', 'buy' => [90, 110], 'sell' => [165, 190], 'purchase_qty' => [11.1, 20.8], 'sale_qty' => [2, 30]],
            ['name' => 'Copper Wire 2.5mm', 'type' => 'trading', 'uom' => 'Roll', 'buy' => [2200, 2500], 'sell' => [3300, 3650], 'purchase_qty' => [3.1, 5.8], 'sale_qty' => [1, 8]],
            ['name' => 'Copper Wire 4mm', 'type' => 'trading', 'uom' => 'Roll', 'buy' => [3400, 3700], 'sell' => [5000, 5400], 'purchase_qty' => [2.4, 4.6], 'sale_qty' => [1, 6]],
            ['name' => 'Electrical Switch Socket', 'type' => 'trading', 'uom' => 'Piece', 'buy' => [35, 45], 'sell' => [90, 115], 'purchase_qty' => [19, 35.7], 'sale_qty' => [5, 50]],
            ['name' => 'LED Panel Light 24W', 'type' => 'trading', 'uom' => 'Piece', 'buy' => [120, 140], 'sell' => [270, 320], 'purchase_qty' => [9.4, 17.5], 'sale_qty' => [2, 25]],
            ['name' => 'Circuit Breaker 32A', 'type' => 'trading', 'uom' => 'Piece', 'buy' => [90, 110], 'sell' => [200, 235], 'purchase_qty' => [7.6, 14.3], 'sale_qty' => [2, 20]],
            ['name' => 'Bathroom Sink Set', 'type' => 'trading', 'uom' => 'Set', 'buy' => [1400, 1600], 'sell' => [2400, 2750], 'purchase_qty' => [2.1, 3.9], 'sale_qty' => [1, 5]],
            ['name' => 'Kitchen Mixer Tap', 'type' => 'trading', 'uom' => 'Piece', 'buy' => [650, 750], 'sell' => [1150, 1350], 'purchase_qty' => [2.4, 4.6], 'sale_qty' => [1, 6]],
            ['name' => 'Wood Glue 1L', 'type' => 'trading', 'uom' => 'Bottle', 'buy' => [60, 75], 'sell' => [145, 175], 'purchase_qty' => [5.9, 11], 'sale_qty' => [2, 15]],
            ['name' => 'Silicone Sealant', 'type' => 'trading', 'uom' => 'Tube', 'buy' => [40, 50], 'sell' => [100, 125], 'purchase_qty' => [7.6, 14.3], 'sale_qty' => [2, 20]],
            ['name' => 'Safety Gloves (Box of 12)', 'type' => 'trading', 'uom' => 'Box', 'buy' => [150, 180], 'sell' => [320, 370], 'purchase_qty' => [3.8, 7.1], 'sale_qty' => [1, 10]],
            ['name' => 'Measuring Tape 5m', 'type' => 'trading', 'uom' => 'Piece', 'buy' => [55, 70], 'sell' => [135, 160], 'purchase_qty' => [3.8, 7.1], 'sale_qty' => [1, 10]],
        ]);
        $itemsByName = collect($items)->keyBy(fn ($row) => $row['item']->name);

        $machineCat = $this->equipmentCategory($company->id, 'Machine');
        $vehicleCat = $this->equipmentCategory($company->id, 'Vehicle');
        $mobileCat = $this->equipmentCategory($company->id, 'Mobile/Computer');
        $otherEquipCat = $this->equipmentCategory($company->id, 'Other');

        $openingDate = Carbon::parse(self::START_DATE);
        $stock = [];

        $this->openingBalances->submit($company->id, [
            'opening_date' => $openingDate->toDateString(),
            'cash_amount' => 50000, 'bank_amount' => 800000,
            'customers' => [
                ['customer_id' => $customers[0]->id, 'amount' => 18500],
                ['customer_id' => $customers[4]->id, 'amount' => 9200],
            ],
            'suppliers' => [
                ['vendor_id' => $vendors[0]->id, 'amount' => 15000],
                ['vendor_id' => $vendors[1]->id, 'amount' => 22000],
            ],
            'inventory' => [
                ['item_id' => $itemsByName['Portland Cement 50kg']['item']->id, 'qty' => 300, 'unit_price' => 140],
                ['item_id' => $itemsByName['Ceramic Floor Tile 60x60']['item']->id, 'qty' => 250, 'unit_price' => 100],
            ],
            'equipment' => [
                ['name' => 'Warehouse Racking (Starting)', 'category_id' => $otherEquipCat->id, 'amount' => 30000, 'date' => $openingDate->toDateString()],
            ],
        ]);
        $this->line('  · opening balance posted');

        // Guaranteed initial stock-up, from two different vendors, so
        // production/sales never starve for stock while the
        // probabilistic weekly restocking below ramps up.
        $this->recordPurchase($company, $key, collect([$vendors[0], $vendors[2]]), $items, $channels, $openingDate->copy()->addDays(2), $stock, lineCount: 10, qtyMultiplier: 2.5);
        $this->recordPurchase($company, $key, collect([$vendors[1], $vendors[4], $vendors[5]]), $items, $channels, $openingDate->copy()->addDays(4), $stock, lineCount: 10, qtyMultiplier: 2.5);

        $this->recordRecurringSeries($company, $key, $vendors[0], $this->expenseCategory($company->id, 'Rent'), $channels, $openingDate->copy()->addDays(3), 18000, 20, 'Warehouse rent');
        $this->recordRecurringSeries($company, $key, $payrollVendor, $this->expenseCategory($company->id, 'Salaries'), $channels, $openingDate->copy()->addDays(25), 50000, 20, 'Staff salaries');

        $equipmentPlan = [
            [30, 'Delivery Van - Isuzu', 420000, $vehicleCat, 5],
            [180, 'Warehouse Forklift', 250000, $machineCat, 10],
            [300, 'Office Computers Set', 32000, $mobileCat, 3],
            [450, 'Extra Warehouse Racking', 28000, $otherEquipCat, 5],
        ];
        foreach ($equipmentPlan as [$dayOffset, $name, $amount, $cat, $life]) {
            $this->recordEquipment($company, $key, $vendors->random(), $cat, $channels, $openingDate->copy()->addDays($dayOffset), $name, $amount, $life);
        }

        $totalDays = $this->totalDays();
        $weights = $this->dailyWeights($totalDays, dampenWeekend: true);
        $saleCounts = $this->spreadCounts($totalDays, 550, $weights);
        $purchaseCounts = $this->spreadCounts($totalDays, 138, array_fill(0, $totalDays, 1.0));
        $expenseCounts = $this->spreadCounts($totalDays, 110, array_fill(0, $totalDays, 1.0));

        $expenseCats = collect(['Transport', 'Marketing', 'Utilities', 'Office Supplies', 'Other'])
            ->map(fn ($n) => $this->expenseCategory($company->id, $n));
        $spendCats = collect(['Transport', 'Other'])
            ->map(fn ($n) => $this->expenseCategory($company->id, $n))->all();

        $bar = $this->output->createProgressBar($totalDays);
        $bar->start();

        for ($d = 0; $d < $totalDays; $d++) {
            $date = $this->dateAt($d);

            for ($i = 0; $i < $purchaseCounts[$d]; $i++) {
                $this->recordPurchase($company, $key, $vendors, $items, $channels, $date, $stock);
            }

            for ($i = 0; $i < $saleCounts[$d]; $i++) {
                $this->recordSale($company, $key, $customers, $items, $channels, $salesChannels, $date, $stock, trackStock: true, allowInstallment: true, installmentThreshold: 15000);
            }

            for ($i = 0; $i < $expenseCounts[$d]; $i++) {
                $cat = $expenseCats->random();
                $amount = match ($cat->name) {
                    'Transport' => $this->money(300, 3500),
                    'Marketing' => $this->money(500, 5000),
                    'Utilities' => $this->money(800, 4000),
                    'Office Supplies' => $this->money(150, 1200),
                    default => $this->money(200, 2500),
                };
                $this->recordExpense($company, $key, $vendors->random(), $cat, $channels, $date, $amount);
            }

            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        for ($i = 0; $i < 18; $i++) {
            $date = $openingDate->copy()->addDays(12 + (int) round($i * ($totalDays - 24) / 18));
            $this->recordCustodyCycle($company, $key, $custodyHolders->random(), $spendCats, $date, $this->money(500, 2500));
        }

        // Small, quick "walk-in" sales — same recordSale() as every
        // other sale in this company, always with a real product and
        // a real sales channel attached. This used to be a fake
        // "cash received, no invoice, no product" receipt
        // (recordStandaloneReceipt) — removed because a sale can
        // never exist without a product now, matching the real app:
        // "Cash Sales" moved under the Sales tab, where a product is
        // required, and the old "log a generic receipt" shortcut in
        // Receive Money is gone.
        for ($i = 0; $i < 15; $i++) {
            $date = $openingDate->copy()->addDays(18 + (int) round($i * ($totalDays - 36) / 15));
            $this->recordSale($company, $key, $customers, $items, $channels, $salesChannels, $date, $stock, trackStock: true, allowInstallment: false);
        }
        // Small, quick petty-cash-style expenses — same recordExpense()
        // as every other expense, always with a real category
        // (vendor stays optional, same rule the real Expense form
        // uses). This used to be a fake "cash paid, no bill, no
        // category" payment (recordStandalonePayment) — removed for
        // the same reason: "cash expenses" moved under the Expense
        // tab, where a category is required.
        for ($i = 0; $i < 22; $i++) {
            $date = $openingDate->copy()->addDays(8 + (int) round($i * ($totalDays - 16) / 22));
            $this->recordExpense($company, $key, null, $this->expenseCategory($company->id, 'Other'), $channels, $date, $this->money(100, 900));
        }

        Auth::logout();
    }
}
