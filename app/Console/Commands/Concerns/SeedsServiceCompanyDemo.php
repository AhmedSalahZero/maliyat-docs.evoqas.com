<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — SeedsServiceCompanyDemo
//  Location: app/Console/Commands/Concerns/SeedsServiceCompanyDemo.php
//
//  One of three traits SeedThreeDemoCompanies is split across (see
//  that command's own class doc comment for why) — this one builds
//  Company 1, the service company (Cairo Business Solutions). Every
//  method here is private and calls the shared record*/make*/boot*
//  helpers that stay on the command class itself — Auth::login(),
//  Carbon and Auth are the only things this file needs to import on
//  its own.
// ══════════════════════════════════════════════════════════════════
trait SeedsServiceCompanyDemo
{
    private function seedServiceCompany(): void
    {
        $key = self::COMPANY_NAMES['service'];

        ['company' => $company, 'channels' => $channels, 'sales_channels' => $salesChannels] = $this->bootCompany(
            name: $key,
            businessTypes: ['service'],
            adminName: 'Youssef Mansour',
            adminEmail: 'admin@cairobiz-demo.com',
            employeeDefs: [
                ['name' => 'Nourhan Adel', 'email' => 'nourhan@cairobiz-demo.com'],
                ['name' => 'Tarek Fahmy', 'email' => 'tarek@cairobiz-demo.com'],
            ],
            channelNames: ['Bank - CIB Current Account', 'InstaPay', 'Vodafone Cash'],
        );

        $customers = $this->makeCustomers($company->id, [
            'Nile Retail Group', 'Zamalek Boutique Hotel', 'Maadi Medical Center', 'October Logistics Co',
            'Heliopolis Law Firm', 'Cairo Fashion House', 'New Cairo Real Estate', 'Nasr City Pharmacy Chain',
            'Downtown Café Chain', 'Alexandria Import Export', 'Giza Tech Startup', 'Smart Home Solutions Ltd',
        ]);

        $vendors = $this->makeVendors($company->id, [
            'CloudHost Egypt', 'Office Plus Supplies', 'Business Center Maadi', 'Freelance Design Studio',
        ]);
        $payrollVendor = $this->makeVendors($company->id, ['Staff Payroll'], 'employee')->first();
        $custodyHolders = $this->makeVendors($company->id, ['Ahmed Salah', 'Mona Ibrahim'], 'employee');

        $items = $this->makeItems($company->id, [
            ['name' => 'IT Support Retainer (Monthly)', 'type' => 'trading', 'uom' => 'Month', 'sell' => [3000, 6000]],
            ['name' => 'Bookkeeping & Accounting Package', 'type' => 'trading', 'uom' => 'Month', 'sell' => [2500, 5000]],
            ['name' => 'Digital Marketing Package', 'type' => 'trading', 'uom' => 'Month', 'sell' => [4000, 9000]],
            ['name' => 'Website Design & Development', 'type' => 'trading', 'uom' => 'Project', 'sell' => [8000, 25000], 'sale_qty' => [1, 1]],
            ['name' => 'HR Consulting Package', 'type' => 'trading', 'uom' => 'Month', 'sell' => [3500, 7000]],
            ['name' => 'Legal Advisory Retainer', 'type' => 'trading', 'uom' => 'Month', 'sell' => [4000, 8000]],
            ['name' => 'Corporate Training Workshop', 'type' => 'trading', 'uom' => 'Session', 'sell' => [6000, 15000], 'sale_qty' => [1, 1]],
            ['name' => 'Cloud Hosting & Maintenance', 'type' => 'trading', 'uom' => 'Month', 'sell' => [1500, 3500]],
        ]);
        // No purchases are ever posted against these items, so
        // MovingAverageCostingService has no pool to price them from
        // and the sale lines simply cost 0 — exactly how a pure
        // service naturally behaves.
        foreach ($items as &$row) {
            $row['def']['sale_qty'] = $row['def']['sale_qty'] ?? [1, 3];
        }
        unset($row);

        $furnitureCat = $this->equipmentCategory($company->id, 'Furniture');
        $mobileCat = $this->equipmentCategory($company->id, 'Mobile/Computer');
        $vehicleCat = $this->equipmentCategory($company->id, 'Vehicle');
        $otherEquipCat = $this->equipmentCategory($company->id, 'Other');

        $openingDate = Carbon::parse(self::START_DATE);
        $this->openingBalances->submit($company->id, [
            'opening_date' => $openingDate->toDateString(),
            'cash_amount' => 25000, 'bank_amount' => 180000,
            'customers' => [
                ['customer_id' => $customers[0]->id, 'amount' => 6500],
                ['customer_id' => $customers[3]->id, 'amount' => 3200],
            ],
            'suppliers' => [
                ['vendor_id' => $vendors[1]->id, 'amount' => 2400],
            ],
            'equipment' => [
                ['name' => 'Starting Office Furniture', 'category_id' => $furnitureCat->id, 'amount' => 38000, 'date' => $openingDate->toDateString()],
            ],
        ]);
        $this->line('  · opening balance posted');

        // Salaries/rent below were far too light for a firm delivering
        // ~460 billable engagements a year across 8 service lines —
        // with no cost of goods sold at all (correct for a pure
        // service business) and only these two recurring lines as
        // real overhead, net profit came out near 84% of revenue,
        // which no real services firm sustains once you account for
        // the delivery staff actually doing the work. A growing
        // service book needs a growing team: this now starts with a
        // smaller core team and adds a second payroll tranche partway
        // through the run as the business scales, instead of one flat
        // salary line for the whole ~20 months.
        $this->recordRecurringSeries($company, $key, $vendors[2], $this->expenseCategory($company->id, 'Rent'), $channels, $openingDate->copy()->addDays(4), 22000, 20, 'Office rent — Business Center Maadi');
        $this->recordRecurringSeries($company, $key, $payrollVendor, $this->expenseCategory($company->id, 'Salaries'), $channels, $openingDate->copy()->addDays(25), 75000, 20, 'Staff salaries');
        $this->recordRecurringSeries($company, $key, $payrollVendor, $this->expenseCategory($company->id, 'Salaries'), $channels, $openingDate->copy()->addDays(300), 45000, 12, 'Additional delivery staff (team growth)');

        $equipmentPlan = [
            [45, 'Office Laptops Set (5x)', 45000, $mobileCat, 3],
            [270, 'Company Car - Sedan', 380000, $vehicleCat, 5],
            [420, 'Photocopier & Printer', 18000, $otherEquipCat, 5],
        ];
        foreach ($equipmentPlan as [$dayOffset, $name, $amount, $cat, $life]) {
            $this->recordEquipment($company, $key, $vendors[1], $cat, $channels, $openingDate->copy()->addDays($dayOffset), $name, $amount, $life);
        }

        $totalDays = $this->totalDays();
        $weights = $this->dailyWeights($totalDays, dampenWeekend: true);
        $saleCounts = $this->spreadCounts($totalDays, 460, $weights);
        $expenseCounts = $this->spreadCounts($totalDays, 90, array_fill(0, $totalDays, 1.0));

        $expenseCats = collect(['Software', 'Travel', 'Consulting', 'Office Supplies', 'Meals', 'Other'])
            ->map(fn ($n) => $this->expenseCategory($company->id, $n));
        $spendCats = collect(['Travel', 'Meals', 'Office Supplies', 'Other'])
            ->map(fn ($n) => $this->expenseCategory($company->id, $n))->all();

        $bar = $this->output->createProgressBar($totalDays);
        $bar->start();
        $stockUnused = [];

        for ($d = 0; $d < $totalDays; $d++) {
            $date = $this->dateAt($d);

            for ($i = 0; $i < $saleCounts[$d]; $i++) {
                $this->recordSale($company, $key, $customers, $items, $channels, $salesChannels, $date, $stockUnused, trackStock: false, allowInstallment: true, installmentThreshold: 9000);
            }

            for ($i = 0; $i < $expenseCounts[$d]; $i++) {
                $cat = $expenseCats->random();
                $amount = match ($cat->name) {
                    'Software' => $this->money(300, 2500),
                    'Travel' => $this->money(500, 4000),
                    // Outsourced delivery capacity (subcontracted
                    // developers/designers/trainers for overflow work)
                    // — a service firm's closest equivalent to a
                    // trading company's cost of goods, so it's sized
                    // to actually matter against revenue rather than
                    // read as an occasional minor expense.
                    'Consulting' => $this->money(3000, 16000),
                    'Office Supplies' => $this->money(200, 1500),
                    'Meals' => $this->money(150, 900),
                    default => $this->money(200, 2000),
                };
                $vendor = $cat->name === 'Consulting' ? $vendors[3] : $vendors->random();
                $this->recordExpense($company, $key, $vendor, $cat, $channels, $date, $amount);
            }

            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        // Custodies — spread roughly every 6 weeks.
        for ($i = 0; $i < 14; $i++) {
            $date = $openingDate->copy()->addDays(15 + (int) round($i * ($totalDays - 30) / 14));
            $this->recordCustodyCycle($company, $key, $custodyHolders->random(), $spendCats, $date, $this->money(800, 3000));
        }

        // Small, quick service sales — same recordSale() as every
        // other sale in this company, always with a real product
        // (a service package, here) and a real sales channel
        // attached. This used to be a fake "cash received, no
        // invoice, no product" receipt (recordStandaloneReceipt) —
        // removed because a sale can never exist without a product
        // now, matching the real app: "Cash Sales" moved under the
        // Sales tab, where a product is required.
        for ($i = 0; $i < 12; $i++) {
            $date = $openingDate->copy()->addDays(20 + (int) round($i * ($totalDays - 40) / 12));
            $this->recordSale($company, $key, $customers, $items, $channels, $salesChannels, $date, $stockUnused, trackStock: false, allowInstallment: false);
        }
        // Small, quick petty-cash-style expenses — same recordExpense()
        // as every other expense, always with a real category. This
        // used to be a fake "cash paid, no bill, no category" payment
        // (recordStandalonePayment) — removed for the same reason:
        // "cash expenses" moved under the Expense tab, where a
        // category is required.
        for ($i = 0; $i < 18; $i++) {
            $date = $openingDate->copy()->addDays(10 + (int) round($i * ($totalDays - 20) / 18));
            $this->recordExpense($company, $key, null, $this->expenseCategory($company->id, 'Other'), $channels, $date, $this->money(100, 800));
        }

        Auth::logout();
    }
}
