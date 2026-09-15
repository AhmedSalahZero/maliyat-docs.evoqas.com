<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\InventoryPurchase;
use App\Models\Item;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use App\Services\JournalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — SeedDemoData
//
//  `php artisan demo:seed` — fills a company with enough realistic
//  trade to actually exercise the screens:
//
//    • Sales list      → paginates at 20, so 100 sales is 5 pages.
//    • Receive / Pay   → the open-invoice worklist caps at 50, so the
//                        mix below deliberately leaves MORE than 50
//                        unpaid. That is what makes the "has_more"
//                        notice and the search box appear.
//    • Dashboard       → items and customers are given deliberately
//                        different shapes so the value / quantity /
//                        frequency panels disagree, which is the only
//                        way to tell they are three real measures.
//
//  Everything it writes is owned by the fixed name lists below, so
//  `--clear` can remove exactly what it created and nothing else.
//  Journal entries are posted through the real JournalService, so the
//  ledger and the reports stay consistent with the documents.
// ══════════════════════════════════════════════════════════════════
class SeedDemoData extends Command
{
    protected $signature = 'demo:seed
        {--sales=100 : How many sales to create}
        {--company= : Company id (defaults to the first one)}
        {--clear : Remove previously seeded demo data and stop}';

    protected $description = 'Fill a company with demo trade for testing pagination, the payment worklist and the dashboard';

    /** Everything the command owns, so --clear is exact. */
    private const CUSTOMERS = [
        'Northwind Trading', 'Gulf Star Supplies', 'Al Waha Markets',
        'Delta Contracting', 'Horizon Retail', 'Cedar & Co',
        'Blue Nile Stores', 'Sahara Logistics',
    ];

    private const ITEMS = [
        'Portland Cement 50kg', 'Steel Rebar 12mm', 'Ceramic Tile 60x60',
        'White Paint 20L', 'PVC Pipe 4in', 'Copper Wire 2.5mm',
    ];

    private const VENDORS = ['Nile Cement Co', 'Eastern Steel', 'Cairo Paints'];

    public function __construct(private readonly JournalService $journal)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $company = $this->option('company')
            ? Company::find((int) $this->option('company'))
            : Company::query()->orderBy('id')->first();

        if (! $company) {
            $this->error('No company found. Register one first.');

            return self::FAILURE;
        }

        $this->info("Company: #{$company->id} — {$company->name}");

        if ($this->option('clear')) {
            return $this->clear($company);
        }

        $user = User::where('company_id', $company->id)->orderBy('id')->first();

        // The command runs unauthenticated from the CLI, where the
        // BelongsToCompany global scope is inert — so every company_id
        // below is passed explicitly rather than inferred.
        $target = max(1, (int) $this->option('sales'));

        $this->seed($company, $user, $target);

        return self::SUCCESS;
    }

    private function seed(Company $company, ?User $user, int $target): void
    {
        $this->journal->seedChartOfAccounts($company);
        Category::seedDefaults($company->id);

        $customers = collect(self::CUSTOMERS)->map(fn ($name) => Customer::firstOrCreate(
            ['company_id' => $company->id, 'name' => $name],
        ));

        $items = collect(self::ITEMS)->map(fn ($name) => Item::firstOrCreate(
            ['company_id' => $company->id, 'name' => $name],
        ));

        $vendors = collect(self::VENDORS)->map(fn ($name) => \App\Models\Vendor::firstOrCreate(
            ['company_id' => $company->id, 'name' => $name],
        ));

        $category = Category::where('company_id', $company->id)->first();

        $bar = $this->output->createProgressBar($target);
        $bar->start();

        $unpaid = $partial = $paid = 0;

        DB::transaction(function () use ($company, $user, $items, $customers, $target, $bar, &$unpaid, &$partial, &$paid) {
            for ($i = 0; $i < $target; $i++) {
                // Customer shape is intentionally skewed: the first
                // customer places few, large orders; the second places
                // many small ones. That is what makes the dashboard's
                // three panels point at different names.
                $customer = match (true) {
                    $i % 17 === 0 => $customers[0],
                    $i % 3  === 0 => $customers[1],
                    default       => $customers[$i % $customers->count()],
                };

                $item     = $items[$i % $items->count()];
                $qty      = $customer->is($customers[0]) ? rand(80, 200) : rand(1, 12);
                $price    = [45, 320, 85, 640, 120, 210][$i % 6];
                $subtotal = round($qty * $price, 2);
                $vatRate  = $i % 4 === 0 ? 14 : 0;
                $vat      = round($subtotal * $vatRate / 100, 2);
                $total    = round($subtotal + $vat, 2);

                // Spread across ~90 days so the month / quarter / year
                // selector has something different to show in each.
                $date = now()->subDays(rand(0, 89))->toDateString();

                $sale = Sale::create([
                    'company_id'  => $company->id,
                    'customer_id' => $customer->id,
                    'date'        => $date,
                    'due_date'    => now()->subDays(rand(0, 89))->addDays(30)->toDateString(),
                    'subtotal'    => $subtotal,
                    'vat_rate'    => $vatRate,
                    'vat_amount'  => $vat,
                    'amount'      => $total,
                    'created_by'  => $user?->id,
                ]);

                $sale->lines()->create([
                    'item_id'    => $item->id,
                    'qty'        => $qty,
                    'unit_price' => $price,
                    'line_total' => $subtotal,
                ]);

                $this->journal->postSaleInvoice($sale);

                // ~60% left open on purpose — the worklist caps at 50,
                // so this is what forces the "showing the most urgent"
                // notice to appear and makes the search box necessary.
                $roll = $i % 10;

                if ($roll < 6) {
                    $unpaid++;
                } elseif ($roll < 8) {
                    $this->pay($sale, round($total * 0.4, 2), $date, 'in');
                    $partial++;
                } else {
                    $this->pay($sale, $total, $date, 'in');
                    $paid++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->seedBills($company, $user, $vendors, $items, $category);

        $this->table(
            ['What', 'Count'],
            [
                ['Sales created',        $target],
                ['  · fully unpaid',     $unpaid],
                ['  · partly paid',      $partial],
                ['  · settled',          $paid],
                ['Open invoices now',    $unpaid + $partial],
                ['Customers',            $customers->count()],
                ['Items',                $items->count()],
            ]
        );

        $this->newLine();
        $this->line('  Sales list paginates at 20 → '.(int) ceil($target / 20).' pages.');
        $this->line('  Receive/Pay caps at 50 → '.(($unpaid + $partial) > 50
            ? '"more items" notice WILL show, search box is needed.'
            : 'under the cap, no overflow notice.'));
        $this->newLine();
        $this->comment('  Undo with:  php artisan demo:seed --clear');
    }

    private function seedBills(Company $company, ?User $user, $vendors, $items, ?Category $category): void
    {
        foreach ($vendors as $index => $vendor) {
            $expense = Expense::create([
                'company_id'  => $company->id,
                'vendor_id'   => $vendor->id,
                'category_id' => $category?->id,
                'date'        => now()->subDays(rand(5, 60))->toDateString(),
                'due_date'    => now()->addDays(rand(1, 20))->toDateString(),
                'amount'      => [4500, 2800, 1950][$index],
                'created_by'  => $user?->id,
            ]);
            $this->journal->postExpenseInvoice($expense);

            $purchase = InventoryPurchase::create([
                'company_id' => $company->id,
                'vendor_id'  => $vendor->id,
                'date'       => now()->subDays(rand(5, 60))->toDateString(),
                'due_date'   => now()->addDays(rand(1, 20))->toDateString(),
                'subtotal'   => 12000, 'vat_rate' => 0, 'vat_amount' => 0, 'amount' => 12000,
                'created_by' => $user?->id,
            ]);
            $purchase->lines()->create([
                'item_id'        => $items[$index]->id,
                'qty'            => 200,
                'uom'            => 'unit',
                'qty_per_uom'    => 1,
                'base_unit_name' => 'unit',
                'unit_price'     => 60,
                'line_total'     => 12000,
            ]);
            $this->journal->postInventoryPurchaseInvoice($purchase);
        }
    }

    private function pay(Sale $sale, float $amount, string $date, string $direction): void
    {
        $payment = $sale->payments()->create([
            'company_id' => $sale->company_id,
            'date'       => $date,
            'amount'     => $amount,
            'direction'  => $direction,
            'method'     => ['cash', 'bank', 'instapay'][rand(0, 2)],
        ]);

        $this->journal->postSaleReceipt($payment);
    }

    /**
     * Remove exactly what this command created — matched by the fixed
     * name lists above, so nothing a real user entered is touched.
     */
    private function clear(Company $company): int
    {
        $customerIds = Customer::where('company_id', $company->id)
            ->whereIn('name', self::CUSTOMERS)->pluck('id');

        $vendorIds = \App\Models\Vendor::where('company_id', $company->id)
            ->whereIn('name', self::VENDORS)->pluck('id');

        $itemIds = Item::where('company_id', $company->id)
            ->whereIn('name', self::ITEMS)->pluck('id');

        $removed = DB::transaction(function () use ($company, $customerIds, $vendorIds, $itemIds) {
            $saleIds = Sale::where('company_id', $company->id)
                ->whereIn('customer_id', $customerIds)->pluck('id');

            $expenseIds = Expense::where('company_id', $company->id)
                ->whereIn('vendor_id', $vendorIds)->pluck('id');

            $purchaseIds = InventoryPurchase::where('company_id', $company->id)
                ->whereIn('vendor_id', $vendorIds)->pluck('id');

            // Ledger entries first — they reference the documents.
            foreach ([[Sale::class, $saleIds], [Expense::class, $expenseIds], [InventoryPurchase::class, $purchaseIds]] as [$type, $ids]) {
                $entryIds = DB::table('journal_entries')
                    ->where('source_type', $type)->whereIn('source_id', $ids)->pluck('id');
                DB::table('journal_lines')->whereIn('journal_entry_id', $entryIds)->delete();
                DB::table('journal_entries')->whereIn('id', $entryIds)->delete();
            }

            $paymentIds = Payment::where('company_id', $company->id)
                ->where(fn ($q) => $q
                    ->where(fn ($s) => $s->where('payable_type', Sale::class)->whereIn('payable_id', $saleIds))
                    ->orWhere(fn ($s) => $s->where('payable_type', Expense::class)->whereIn('payable_id', $expenseIds))
                    ->orWhere(fn ($s) => $s->where('payable_type', InventoryPurchase::class)->whereIn('payable_id', $purchaseIds)))
                ->pluck('id');

            $entryIds = DB::table('journal_entries')
                ->where('source_type', Payment::class)->whereIn('source_id', $paymentIds)->pluck('id');
            DB::table('journal_lines')->whereIn('journal_entry_id', $entryIds)->delete();
            DB::table('journal_entries')->whereIn('id', $entryIds)->delete();

            Payment::whereIn('id', $paymentIds)->delete();

            DB::table('sale_lines')->whereIn('sale_id', $saleIds)->delete();
            DB::table('inventory_purchase_lines')->whereIn('inventory_purchase_id', $purchaseIds)->delete();

            $sales = Sale::whereIn('id', $saleIds)->delete();
            Expense::whereIn('id', $expenseIds)->delete();
            InventoryPurchase::whereIn('id', $purchaseIds)->delete();

            Customer::whereIn('id', $customerIds)->delete();
            \App\Models\Vendor::whereIn('id', $vendorIds)->delete();
            Item::whereIn('id', $itemIds)->delete();

            return $sales;
        });

        $this->info("Removed {$removed} demo sales and everything attached to them.");

        return self::SUCCESS;
    }
}
