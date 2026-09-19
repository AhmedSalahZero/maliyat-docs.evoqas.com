<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SeedsProductionCompanyDemo;
use App\Console\Commands\Concerns\SeedsServiceCompanyDemo;
use App\Console\Commands\Concerns\SeedsTradingCompanyDemo;
use App\Models\Category;
use App\Models\Company;
use App\Models\Custody;
use App\Models\Customer;
use App\Models\EquipmentPurchase;
use App\Models\Expense;
use App\Models\InventoryPurchase;
use App\Models\Item;
use App\Models\PaymentChannel;
use App\Models\ProductionOrder;
use App\Models\Sale;
use App\Models\SalesChannel;
use App\Models\User;
use App\Models\Vendor;
use App\Services\DepreciationService;
use App\Services\JournalService;
use App\Services\MovingAverageCostingService;
use App\Services\OpeningBalanceService;
use App\Services\PaymentRecorderService;
use App\Services\ProductionOrderService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — SeedThreeDemoCompanies
//  Location: app/Console/Commands/SeedThreeDemoCompanies.php
//
//  `php artisan demo:seed-companies` — builds THREE complete demo
//  companies from scratch, one for each business type, with ~20
//  months (1 Jan 2025 → 30 Aug 2026) of realistic, growing trade:
//
//    • Cairo Business Solutions        — service (8 services)
//    • Nile Star Trading Co.           — trading (20 products)
//    • Al-Ahram Furniture Manufacturing — production (10 products)
//
//  Every figure is posted through the app's own services —
//  JournalService, PaymentRecorderService, ProductionOrderService,
//  OpeningBalanceService, DepreciationService — exactly the way a
//  real user's clicks would post them. Nothing here writes a ledger
//  row directly. That is what guarantees the Trial Balance, P&L,
//  Balance Sheet, Inventory Statement and every statement come out
//  internally consistent.
//
//  Console commands run unauthenticated, so `Auth::login()` on each
//  company's admin for the duration of that company's block is what
//  makes BelongsToCompany's auto-fill and every `auth()->id()` call
//  inside the services behave exactly as they would for a real
//  logged-in user — see App\Support\Concerns\BelongsToCompany.
//
//  ── A gap this command works around ─────────────────────────────
//  RecurringExpenseService::createSeries() creates the Expense rows
//  for a recurring series (rent, salaries, ...) but never posts a
//  journal entry for ANY occurrence — not even the first — and
//  ExpenseController::storeRecurring() doesn't either. Every other
//  document type in this app posts its own "invoice/bill" entry the
//  moment it's created; recurring expenses currently don't, which
//  would make a company's rent and payroll invisible to the P&L and
//  Trial Balance despite real cash moving against them. That looks
//  like an oversight rather than intended behaviour, so rather than
//  reproduce it, recordRecurringSeries() below builds each
//  occurrence directly (still tagging recurring_id/index/count/
//  frequency so the "Recurring plans" summary works) and posts
//  postExpenseInvoice() + a payment for each one, same as a normal
//  one-off Expense. Worth a look from whoever owns that service.
//
//  ── Split across four files (Sep 2026) ──────────────────────────
//  This file used to be ~1,690 lines. The three seedXCompany()
//  methods — one full demo company each, and by far the biggest
//  single chunk of the file — now live in their own trait, one per
//  company, under Concerns/: SeedsServiceCompanyDemo,
//  SeedsTradingCompanyDemo, SeedsProductionCompanyDemo. This class
//  still does everything it always did (same command, same
//  behaviour, same output) — `self::` and `$this->` inside a trait
//  resolve against the class that `use`s it, so those three methods
//  still see COMPANY_NAMES and still call the shared record*/make*/
//  boot* helpers below exactly as before. What stays in THIS file
//  is only what genuinely IS shared across all three companies:
//  the command's own wiring (constructor, handle()), the low-level
//  builders (bootCompany, makeCustomers/makeVendors/makeItems), the
//  transaction recorders (recordSale, recordPurchase, ...), and the
//  final printSummary(). Splitting it any other way would have
//  meant either duplicating those shared helpers three times or
//  passing a dozen dependencies into separate classes for no
//  behavioural benefit.
// ══════════════════════════════════════════════════════════════════
class SeedThreeDemoCompanies extends Command
{
    use SeedsProductionCompanyDemo;
    use SeedsServiceCompanyDemo;
    use SeedsTradingCompanyDemo;

    protected $signature = 'demo:seed-companies
        {--clear : Remove the 3 demo companies this command creates (and everything under them), then stop}
        {--only= : Limit to one company: service|trading|production}
        {--seed=42 : Random seed, so re-running with the same seed reproduces the same data}';

    protected $description = 'Create 3 demo companies (service / trading / production) with ~20 months of realistic transactions exercising every feature';

    private const START_DATE = '2025-01-01';
    private const END_DATE   = '2026-08-30';
    private const VAT_RATE   = 14.0;
    private const PASSWORD   = 'Demo@12345';

    private const COMPANY_NAMES = [
        'service'    => 'Cairo Business Solutions',
        'trading'    => 'Nile Star Trading Co.',
        'production' => 'Al-Ahram Furniture Manufacturing',
    ];

    /** @var array<string, array<int, array{email:string, password:string, role:string}>> */
    private array $credentials = [];

    /** @var array<string, array<string, int>> */
    private array $stats = [];

    public function __construct(
        private readonly JournalService $journal,
        private readonly PaymentRecorderService $paymentRecorder,
        private readonly ProductionOrderService $productionOrders,
        private readonly OpeningBalanceService $openingBalances,
        private readonly DepreciationService $depreciation,
        private readonly MovingAverageCostingService $costing,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        mt_srand((int) $this->option('seed'));

        if ($this->option('clear')) {
            foreach (self::COMPANY_NAMES as $name) {
                $this->clearCompany($name);
            }

            return self::SUCCESS;
        }

        $only = $this->option('only');

        if ($only && ! isset(self::COMPANY_NAMES[$only])) {
            $this->error("--only must be one of: ".implode(', ', array_keys(self::COMPANY_NAMES)));

            return self::FAILURE;
        }

        $types = $only ? [$only] : array_keys(self::COMPANY_NAMES);

        foreach ($types as $type) {
            $name = self::COMPANY_NAMES[$type];

            if (Company::where('name', $name)->exists()) {
                $this->warn("Skipping \"{$name}\" — it already exists. Run with --clear first if you want to rebuild it.");

                continue;
            }

            $this->newLine();
            $this->info("═══ Building: {$name} ═══");

            match ($type) {
                'service'    => $this->seedServiceCompany(),
                'trading'    => $this->seedTradingCompany(),
                'production' => $this->seedProductionCompany(),
            };
        }

        Auth::logout();

        $this->newLine();
        $this->info('Posting depreciation catch-up for every company through '.self::END_DATE.'...');
        $posted = $this->depreciation->catchUpAllCompanies(Carbon::parse(self::END_DATE));
        $this->line("  → {$posted} monthly depreciation ".($posted === 1 ? 'entry' : 'entries').' posted.');

        $this->printSummary();

        return self::SUCCESS;
    }

    /**
     * Remove one demo company entirely. Users don't cascade from the
     * companies FK (ON DELETE SET NULL, so an admin can survive its
     * company being deleted elsewhere) — so they're removed first,
     * while company_id still points at the company we're about to
     * drop. Every other domain table (accounts, customers, vendors,
     * items, sales, expenses, inventory/equipment purchases,
     * production orders, payments, journal entries/lines, categories,
     * payment channels, opening balances) cascades from companies.id
     * ON DELETE CASCADE, so deleting the company row is enough for
     * all of it.
     */
    private function clearCompany(string $name): void
    {
        $company = Company::where('name', $name)->first();

        if (! $company) {
            $this->line("  \"{$name}\" doesn't exist — nothing to remove.");

            return;
        }

        DB::transaction(function () use ($company, $name) {
            $userCount = User::where('company_id', $company->id)->count();
            User::where('company_id', $company->id)->delete();
            $company->delete();
            $this->info("Removed \"{$name}\" (#{$company->id}) — {$userCount} user(s) and everything else under it.");
        });
    }

    // ══════════════════════════════════════════════════════════════
    //  Shared setup helpers
    // ══════════════════════════════════════════════════════════════

    /**
     * @return array{company:Company, admin:User, employees:Collection<int,User>, channels:Collection<int,PaymentChannel>}
     */
    private function bootCompany(
        string $name,
        array $businessTypes,
        string $adminName,
        string $adminEmail,
        array $employeeDefs,
        array $channelNames,
    ): array {
        $company = Company::create([
            'name'           => $name,
            'currency'       => 'EGP',
            'business_types' => $businessTypes,
            'is_active'      => true,
            'trial_ends_at'  => null,
        ]);

        $admin = User::create([
            'name'              => $adminName,
            'email'             => $adminEmail,
            'password'          => self::PASSWORD,
            'role'              => 'company_admin',
            'company_id'        => $company->id,
            'language'          => 'en',
            'is_active'         => true,
        ]);

        // email_verified_at is deliberately left out of User::$fillable
        // (a normal signup form must never be able to mark itself
        // verified — only the real verification-code flow can) —
        // so it has to be set this way, or it's silently dropped by
        // the User::create() call above and stays null.
        $admin->forceFill(['email_verified_at' => '2026-01-01'])->save();

        $company->forceFill(['created_by' => $admin->id])->save();

        $this->journal->seedChartOfAccounts($company);
        Category::seedDefaults($company->id);
        SalesChannel::seedDefaults($company->id);

        // Log in as this company's admin for the rest of its block —
        // see the class doc comment for why this matters (auto-fill
        // of company_id, and every auth()->id() the services use).
        Auth::login($admin);

        $employees = collect($employeeDefs)->map(function (array $e) use ($company, $admin) {
            $employee = User::create([
                'name'              => $e['name'],
                'email'             => $e['email'],
                'password'          => self::PASSWORD,
                'role'              => 'employee',
                'company_id'        => $company->id,
                'created_by'        => $admin->id,
                'language'          => 'en',
                'is_active'         => true,
            ]);

            // Same reason as the admin above — email_verified_at is
            // outside $fillable on purpose, so it has to be set this
            // way rather than passed into create().
            $employee->forceFill(['email_verified_at' => '2026-01-01'])->save();

            return $employee;
        });

        $channels = collect($channelNames)->map(fn ($n) => PaymentChannel::create([
            'company_id' => $company->id,
            'name'       => $n,
        ]));

        // The company's Sales Channel dropdown (Direct/Delivery/Online/
        // WhatsApp — see SalesChannel::seedDefaults() above) — every
        // seeded sale picks one of these for real (see recordSale()),
        // instead of leaving sales_channel_id empty and letting the
        // Dashboard's "Sales by Channel" donut fall back to showing
        // 100% "Direct Sales" for every demo company, which was never
        // a real breakdown, just an empty column.
        $salesChannels = SalesChannel::query()->where('company_id', $company->id)->get();

        $this->credentials[$name] = collect([$admin])->concat($employees)
            ->map(fn (User $u) => ['email' => $u->email, 'password' => self::PASSWORD, 'role' => $u->role])
            ->all();

        $this->stats[$name] = [];

        return ['company' => $company, 'admin' => $admin, 'employees' => $employees, 'channels' => $channels, 'sales_channels' => $salesChannels];
    }

    private function addCategory(int $companyId, string $name, string $kind = 'expense', ?int $usefulLife = null): Category
    {
        return Category::query()->firstOrCreate(
            ['company_id' => $companyId, 'kind' => $kind, 'name' => $name],
            ['default_useful_life_years' => $usefulLife],
        );
    }

    private function equipmentCategory(int $companyId, string $name): Category
    {
        return Category::query()->where('company_id', $companyId)
            ->where('kind', 'equipment')->where('name', $name)->firstOrFail();
    }

    private function expenseCategory(int $companyId, string $name): Category
    {
        return Category::query()->where('company_id', $companyId)
            ->where('kind', 'expense')->where('name', $name)->firstOrFail();
    }

    /**
     * @return Collection<int, Customer>
     */
    private function makeCustomers(int $companyId, array $names): Collection
    {
        return collect($names)->map(fn ($n) => Customer::create(['company_id' => $companyId, 'name' => $n]));
    }

    /**
     * @return Collection<int, Vendor>
     */
    private function makeVendors(int $companyId, array $names, string $type = 'vendor'): Collection
    {
        return collect($names)->map(fn ($n) => Vendor::create(['company_id' => $companyId, 'name' => $n, 'type' => $type]));
    }

    /**
     * @param  array<int, array{name:string, type:string, uom?:string, base_unit_name?:string, buy?:array{0:float,1:float}, sell?:array{0:float,1:float}}>  $defs
     * @return array<int, array{item:Item, def:array}>
     */
    private function makeItems(int $companyId, array $defs): array
    {
        $out = [];

        foreach ($defs as $def) {
            $item = Item::create([
                'company_id'     => $companyId,
                'name'           => $def['name'],
                'type'           => $def['type'],
                'uom'            => $def['uom'] ?? 'Unit',
                'qty_per_uom'    => 1,
                'base_unit_name' => $def['base_unit_name'] ?? ($def['uom'] ?? 'Unit'),
            ]);

            $out[] = ['item' => $item, 'def' => $def];
        }

        return $out;
    }

    // ══════════════════════════════════════════════════════════════
    //  Small randomness / date helpers
    // ══════════════════════════════════════════════════════════════

    private function pick(array $arr)
    {
        return $arr[array_rand($arr)];
    }

    private function money(float $min, float $max, int $roundTo = 1): float
    {
        $val = $min + (mt_rand() / mt_getrandmax()) * ($max - $min);

        if ($roundTo > 1) {
            $val = round($val / $roundTo) * $roundTo;
        }

        return round($val, 2);
    }

    private function qty(float $min, float $max): float
    {
        return round($min + (mt_rand() / mt_getrandmax()) * ($max - $min), 2);
    }

    /**
     * One weight per calendar day between START_DATE and END_DATE:
     * a linear growth ramp (the business does more trade over time)
     * with a Friday/Saturday dampening (the Egyptian weekend) for
     * companies where that matters.
     *
     * @return array<int, float> indexed 0..totalDays-1
     */
    private function dailyWeights(int $totalDays, bool $dampenWeekend = true): array
    {
        $weights = [];
        $start = Carbon::parse(self::START_DATE);

        for ($i = 0; $i < $totalDays; $i++) {
            $w = 0.65 + 0.85 * ($i / max(1, $totalDays - 1)); // 0.65 → 1.5 growth ramp

            if ($dampenWeekend) {
                $dow = $start->copy()->addDays($i)->dayOfWeekIso; // 1=Mon .. 7=Sun
                if ($dow === 5 || $dow === 6) { // Friday, Saturday
                    $w *= 0.45;
                }
            }

            $weights[$i] = $w;
        }

        return $weights;
    }

    /**
     * Spread $target events across $totalDays days, shaped by
     * $weights, using remainder-carry so the total lands almost
     * exactly on $target while keeping the day-to-day shape.
     *
     * @return array<int, int> indexed 0..totalDays-1
     */
    private function spreadCounts(int $totalDays, int $target, array $weights): array
    {
        $sumWeights = array_sum($weights) ?: 1.0;
        $counts = [];
        $carry = 0.0;

        for ($i = 0; $i < $totalDays; $i++) {
            $expected = $target * $weights[$i] / $sumWeights;
            $base = (int) floor($expected);
            $carry += $expected - $base;

            if ($carry >= 1.0) {
                $base++;
                $carry -= 1.0;
            }

            $counts[$i] = $base;
        }

        return $counts;
    }

    private function dateAt(int $dayIndex): Carbon
    {
        return Carbon::parse(self::START_DATE)->addDays($dayIndex);
    }

    private function totalDays(): int
    {
        return Carbon::parse(self::START_DATE)->diffInDays(Carbon::parse(self::END_DATE)) + 1;
    }

    // ══════════════════════════════════════════════════════════════
    //  Payment plan / method helpers — shared by sales, expenses,
    //  purchases and equipment purchases.
    // ══════════════════════════════════════════════════════════════

    /**
     * @param  Collection<int, PaymentChannel>  $channels
     * @return array{0: string, 1: ?int}  [method, payment_channel_id]
     */
    private function methodAndChannel(Collection $channels): array
    {
        $method = $this->pick(['cash', 'cash', 'bank', 'instapay', 'wallet']);

        if ($method === 'cash' || $channels->isEmpty()) {
            return [$method, null];
        }

        $matching = match ($method) {
            'bank'     => $channels->filter(fn ($c) => str_contains($c->name, 'Bank')),
            'instapay' => $channels->filter(fn ($c) => str_contains($c->name, 'InstaPay')),
            'wallet'   => $channels->filter(fn ($c) => str_contains($c->name, 'Vodafone')),
            default    => collect(),
        };

        $chosen = $matching->isNotEmpty() ? $matching->random() : $channels->random();

        return [$method, $chosen->id];
    }

    /**
     * Decide how a document gets paid: now / partial / later /
     * installment — with weighted randomness so the open-invoice and
     * open-bill worklists, the aging, and the installments screen
     * all end up with real data to show.
     *
     * @param  Collection<int, PaymentChannel>  $channels
     * @return array{mode:string, method:?string, channel_id:?int, amount_now:?float, due_date:?string, schedule:array}
     */
    private function choosePaymentPlan(float $total, Carbon $date, Collection $channels, bool $allowInstallment, float $installmentThreshold = 6000): array
    {
        if ($allowInstallment && $total >= $installmentThreshold && mt_rand(1, 100) <= 14) {
            $count = mt_rand(3, 6);
            $schedule = $this->paymentRecorder->buildInstallmentSchedule($total, $date->toDateString(), $count, 30);

            return [
                'mode' => 'installment', 'method' => null, 'channel_id' => null,
                'amount_now' => null, 'due_date' => $schedule[0]['due_date'], 'schedule' => $schedule,
            ];
        }

        $roll = mt_rand(1, 100);

        if ($roll <= 52) {
            [$method, $channel] = $this->methodAndChannel($channels);

            return ['mode' => 'now', 'method' => $method, 'channel_id' => $channel, 'amount_now' => null, 'due_date' => null, 'schedule' => []];
        }

        if ($roll <= 78) {
            [$method, $channel] = $this->methodAndChannel($channels);
            $amountNow = round($total * (mt_rand(30, 70) / 100), 2);
            $due = $date->copy()->addDays(mt_rand(15, 45))->toDateString();

            return ['mode' => 'partial', 'method' => $method, 'channel_id' => $channel, 'amount_now' => $amountNow, 'due_date' => $due, 'schedule' => []];
        }

        $due = $date->copy()->addDays(mt_rand(15, 60))->toDateString();

        return ['mode' => 'later', 'method' => null, 'channel_id' => null, 'amount_now' => null, 'due_date' => $due, 'schedule' => []];
    }

    private function bump(string $company, string $key, int $by = 1): void
    {
        $this->stats[$company][$key] = ($this->stats[$company][$key] ?? 0) + $by;
    }

    /**
     * How many days before END_DATE a balance has to be due before
     * this seeder will simulate it actually getting collected/paid.
     * Anything due more recently than this is left open on purpose —
     * that's the realistic "still within terms" tail that makes the
     * open-invoice/open-bill worklists and aging useful to look at.
     */
    private const COLLECTION_CUTOFF_DAYS = 55;

    /**
     * Close out the part of a document that choosePaymentPlan() left
     * open — mode 'partial' (the remainder), 'later' (the whole
     * amount), or 'installment' (each scheduled installment) — by
     * simulating a real follow-up payment near its due date, unless
     * that due date is too close to END_DATE to plausibly have
     * happened yet. Without this, EVERY 'later'/'partial'/
     * 'installment' document sits open for the rest of the dataset's
     * life, which is not how a real business collects its money and
     * is what was dragging cash and the P&L into deep negative.
     *
     * A small fraction of older balances are deliberately left
     * unpaid anyway (a genuine bad debt / slow payer), so the
     * open-items list isn't purely "recent stuff" once this runs.
     */
    private function settleFollowUps(Model $payable, string $direction, bool $isSale, array $plan, float $total, float $paidSoFar, Collection $channels): void
    {
        $endDate = Carbon::parse(self::END_DATE);
        $cutoff = $endDate->copy()->subDays(self::COLLECTION_CUTOFF_DAYS);

        $pay = function (float $amount, Carbon $dueDate) use ($payable, $direction, $isSale, $channels, $endDate) {
            if ($amount <= 0.01) {
                return;
            }
            // ~8% genuine bad debt / very slow payer — left open even
            // though it's old, so the aging report has real teeth.
            if (mt_rand(1, 100) <= 8) {
                return;
            }

            $payDate = $dueDate->copy()->addDays(mt_rand(-3, 14));
            if ($payDate->lt($dueDate->copy()->subDays(3))) {
                $payDate = $dueDate->copy();
            }
            if ($payDate->gt($endDate)) {
                $payDate = $endDate->copy();
            }
            if ($payDate->lt(Carbon::parse(self::START_DATE))) {
                $payDate = Carbon::parse(self::START_DATE);
            }

            [$method, $channel] = $this->methodAndChannel($channels);

            $payment = $payable->payments()->create([
                'company_id' => $payable->company_id, 'date' => $payDate->toDateString(),
                'amount' => round($amount, 2), 'method' => $method, 'payment_channel_id' => $channel,
                'direction' => $direction,
            ]);

            if ($isSale) {
                $this->journal->postSaleReceipt($payment);
            } else {
                $this->journal->postBillPayment($payment);
            }
        };

        if ($plan['mode'] === 'installment' && ! empty($plan['schedule'])) {
            foreach ($plan['schedule'] as $installment) {
                $dueDate = Carbon::parse($installment['due_date']);
                if ($dueDate->lte($cutoff)) {
                    $pay((float) $installment['amount'], $dueDate);
                }
            }

            return;
        }

        $remaining = round($total - $paidSoFar, 2);
        if ($remaining <= 0.01 || empty($plan['due_date'])) {
            return;
        }

        $dueDate = Carbon::parse($plan['due_date']);
        if ($dueDate->lte($cutoff)) {
            $pay($remaining, $dueDate);
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  Transaction recorders — each mirrors what the matching
    //  controller does, so the ledger/reports end up exactly as if a
    //  real user had clicked through the app.
    // ══════════════════════════════════════════════════════════════

    /**
     * @param  array<int, array{item:Item, def:array}>  $catalog
     * @param  array<int, float>  $stock  item_id => qty on hand, kept in sync here
     */
    private function recordSale(
        Company $company,
        string $companyKey,
        Collection $customers,
        array $catalog,
        Collection $channels,
        Collection $salesChannels,
        Carbon $date,
        array &$stock,
        bool $trackStock,
        bool $allowInstallment,
        float $installmentThreshold = 8000,
    ): ?Sale {
        $customer = $customers->random();
        $lineCount = mt_rand(1, 100) <= 65 ? 1 : (mt_rand(1, 100) <= 80 ? 2 : 3);

        $lines = [];
        $attempts = 0;

        while (count($lines) < $lineCount && $attempts < $lineCount * 4) {
            $attempts++;
            ['item' => $item, 'def' => $def] = $this->pick($catalog);

            $available = $trackStock ? ($stock[$item->id] ?? 0.0) : PHP_FLOAT_MAX;

            if ($trackStock && $available < 1) {
                continue;
            }

            $qtyRange = $def['sale_qty'] ?? [1, 6];
            $qty = min($this->qty($qtyRange[0], $qtyRange[1]), $trackStock ? $available : PHP_FLOAT_MAX);
            $qty = round($qty, 2);

            if ($qty <= 0) {
                continue;
            }

            $sellRange = $def['sell'] ?? [10, 20];
            $price = $this->money($sellRange[0], $sellRange[1], $sellRange[1] > 500 ? 5 : 1);

            $lines[] = ['item_id' => $item->id, 'qty' => $qty, 'unit_price' => $price, 'line_total' => round($qty * $price, 2)];

            if ($trackStock) {
                $stock[$item->id] = round(($stock[$item->id] ?? 0) - $qty, 2);
            }
        }

        if (empty($lines)) {
            return null;
        }

        $subtotal = round(array_sum(array_column($lines, 'line_total')), 2);
        $vatRate = mt_rand(1, 100) <= 90 ? self::VAT_RATE : 0.0;
        $vatAmount = round($subtotal * $vatRate / 100, 2);
        $total = round($subtotal + $vatAmount, 2);

        $plan = $this->choosePaymentPlan($total, $date, $channels, $allowInstallment, $installmentThreshold);

        $sale = Sale::create([
            'company_id'  => $company->id,
            'customer_id' => $customer->id,
            // Every sale picks a real sales channel — see
            // bootCompany()'s sales_channels — instead of leaving
            // this null and letting the Dashboard's channel donut
            // fall back to an all-"Direct Sales" default.
            'sales_channel_id' => $salesChannels->isNotEmpty() ? $salesChannels->random()->id : null,
            'date'        => $date->toDateString(),
            'subtotal'    => $subtotal,
            'vat_rate'    => $vatRate,
            'vat_amount'  => $vatAmount,
            'amount'      => $total,
            'due_date'    => $plan['due_date'],
            'created_by'  => Auth::id(),
        ]);

        foreach ($lines as $line) {
            $sale->lines()->create($line);
        }

        $this->journal->postSaleInvoice($sale);

        // Price every line through the SAME engine a real sale in
        // the app uses — MovingAverageCostingService — instead of
        // the old, legacy Item::averagePurchaseCost() this used to
        // call directly. That old method summed every purchase ever
        // made for the item and never subtracted what had already
        // been sold; see SaleController::recalculateCostsFor()'s doc
        // comment for the full reasoning it was replaced there. This
        // mirrors that exact call (SaleController::store() calls it
        // right after postSaleInvoice(), same as here), so demo
        // Cost of Goods Sold is priced exactly the way a real sale's
        // would be — including the "no purchase history yet, so
        // treat it as free" fallback for an item nobody's stocked,
        // which is why this runs unconditionally rather than only
        // when $trackStock is true: a pure Service-company item with
        // zero purchases correctly prices at zero either way.
        foreach (collect($lines)->pluck('item_id')->unique() as $itemId) {
            $this->costing->onItemMovementChanged($company->id, (int) $itemId, $date->toDateString());
        }

        $payment = $this->paymentRecorder->apply($sale, 'in', $plan['mode'], [
            'date' => $date->toDateString(), 'method' => $plan['method'] ?? 'cash',
            'amount_now' => $plan['amount_now'], 'payment_channel_id' => $plan['channel_id'],
        ], $plan['schedule']);

        if ($payment) {
            $this->journal->postSaleReceipt($payment);
        }

        $this->settleFollowUps($sale, 'in', true, $plan, $total, (float) ($payment?->amount ?? 0), $channels);

        $this->bump($companyKey, 'sales');
        $this->bump($companyKey, 'sales_'.$plan['mode']);

        return $sale;
    }

    /**
     * @param  array<int, array{item:Item, def:array}>  $catalog
     * @param  array<int, float>  $stock
     */
    private function recordPurchase(
        Company $company,
        string $companyKey,
        Collection $vendors,
        array $catalog,
        Collection $channels,
        Carbon $date,
        array &$stock,
        int $lineCount = 0,
        ?float $qtyMultiplier = null,
    ): InventoryPurchase {
        $vendor = $vendors->random();
        $lineCount = $lineCount > 0 ? $lineCount : mt_rand(3, 7);
        $lineCount = min($lineCount, count($catalog));

        $chosen = collect($catalog)->shuffle()->take($lineCount);
        $lines = [];

        foreach ($chosen as ['item' => $item, 'def' => $def]) {
            $qtyRange = $def['purchase_qty'] ?? [10, 40];
            $qty = $this->qty($qtyRange[0], $qtyRange[1]) * ($qtyMultiplier ?? 1.0);
            $qty = round($qty, 2);

            $buyRange = $def['buy'] ?? [5, 10];
            $price = $this->money($buyRange[0], $buyRange[1], $buyRange[1] > 500 ? 5 : 1);

            $lines[] = [
                'item_id' => $item->id, 'qty' => $qty, 'uom' => $def['uom'] ?? 'Unit',
                'qty_per_uom' => 1, 'base_unit_name' => $def['base_unit_name'] ?? ($def['uom'] ?? 'Unit'),
                'unit_price' => $price, 'line_total' => round($qty * $price, 2),
            ];

            $stock[$item->id] = round(($stock[$item->id] ?? 0) + $qty, 2);
        }

        $subtotal = round(array_sum(array_column($lines, 'line_total')), 2);
        $vatAmount = round($subtotal * self::VAT_RATE / 100, 2);
        $total = round($subtotal + $vatAmount, 2);

        $plan = $this->choosePaymentPlan($total, $date, $channels, true, 20000);

        $purchase = InventoryPurchase::create([
            'company_id' => $company->id, 'vendor_id' => $vendor->id, 'date' => $date->toDateString(),
            'subtotal' => $subtotal, 'vat_rate' => self::VAT_RATE, 'vat_amount' => $vatAmount, 'amount' => $total,
            'due_date' => $plan['due_date'], 'created_by' => Auth::id(),
        ]);

        foreach ($lines as $line) {
            $purchase->lines()->create($line);
        }

        $this->journal->postInventoryPurchaseInvoice($purchase);

        // Same reasoning as recordSale()'s costing call: a real
        // purchase (InventoryPurchaseController::store()) reprices
        // every item it touches right after posting the invoice, so
        // this item's moving-average pool has a ledger row for this
        // purchase BEFORE any later sale of it gets priced. Skipping
        // this would leave every sale pricing against a pool that
        // never saw this purchase at all.
        foreach (collect($lines)->pluck('item_id')->unique() as $itemId) {
            $this->costing->onItemMovementChanged($company->id, (int) $itemId, $date->toDateString());
        }

        $payment = $this->paymentRecorder->apply($purchase, 'out', $plan['mode'], [
            'date' => $date->toDateString(), 'method' => $plan['method'] ?? 'cash',
            'amount_now' => $plan['amount_now'], 'payment_channel_id' => $plan['channel_id'],
        ], $plan['schedule']);

        if ($payment) {
            $this->journal->postBillPayment($payment);
        }

        $this->settleFollowUps($purchase, 'out', false, $plan, $total, (float) ($payment?->amount ?? 0), $channels);

        $this->bump($companyKey, 'inventory_purchases');

        return $purchase;
    }

    /**
     * @param  array<string, array{qty_per_unit:float}>  $recipe  raw material name => qty consumed per unit produced
     * @param  array<string, Item>  $rawMaterialsByName
     * @param  array<int, float>  $stock  item_id => qty on hand
     */
    private function recordProductionOrder(
        Company $company,
        string $companyKey,
        Item $product,
        array $recipe,
        array $rawMaterialsByName,
        Carbon $date,
        float $qtyProduced,
        float $laborPerUnit,
        array &$stock,
    ): ?ProductionOrder {
        $materials = [];

        foreach ($recipe as $materialName => $spec) {
            $rawItem = $rawMaterialsByName[$materialName];
            $needed = round($spec['qty_per_unit'] * $qtyProduced, 2);
            $available = $stock[$rawItem->id] ?? 0.0;

            if ($available < $needed) {
                if ($available < $needed * 0.4) {
                    // Not enough of this material at all right now —
                    // skip this run entirely rather than post a run
                    // with an implausible material mix.
                    return null;
                }
                $needed = round($available, 2);
            }

            $materials[] = ['item_id' => $rawItem->id, 'qty' => $needed];
            $stock[$rawItem->id] = round($available - $needed, 2);
        }

        $order = $this->productionOrders->create([
            'item_id'      => $product->id,
            'date'         => $date->toDateString(),
            'qty_produced' => $qtyProduced,
            'materials'    => $materials,
            'labor_cost'   => round($laborPerUnit * $qtyProduced, 2),
        ]);

        $stock[$product->id] = round(($stock[$product->id] ?? 0) + $qtyProduced, 2);

        $this->bump($companyKey, 'production_orders');

        return $order;
    }

    /**
     * $vendor is nullable at the call site (a petty-cash-style
     * expense picks no specific vendor) but the database column is
     * NOT NULL — a real Expense always resolves to Vendor::cashVendor()
     * in that case (see ExpenseController::store()'s "Cash Expense"
     * handling), never a literal null. This mirrors that exact
     * fallback rather than reproducing the schema's constraint by
     * accident.
     */
    private function recordExpense(
        Company $company,
        string $companyKey,
        ?Vendor $vendor,
        Category $category,
        Collection $channels,
        Carbon $date,
        float $amount,
        bool $allowInstallment = false,
    ): Expense {
        $vendor ??= Vendor::cashVendor($company->id);

        $plan = $this->choosePaymentPlan($amount, $date, $channels, $allowInstallment, 15000);

        $expense = Expense::create([
            'company_id' => $company->id, 'vendor_id' => $vendor->id, 'category_id' => $category->id,
            'date' => $date->toDateString(), 'amount' => $amount, 'due_date' => $plan['due_date'],
            'created_by' => Auth::id(),
        ]);

        $this->journal->postExpenseInvoice($expense);

        $payment = $this->paymentRecorder->apply($expense, 'out', $plan['mode'], [
            'date' => $date->toDateString(), 'method' => $plan['method'] ?? 'cash',
            'amount_now' => $plan['amount_now'], 'payment_channel_id' => $plan['channel_id'],
        ], $plan['schedule']);

        if ($payment) {
            $this->journal->postBillPayment($payment);
        }

        $this->settleFollowUps($expense, 'out', false, $plan, $amount, (float) ($payment?->amount ?? 0), $channels);

        $this->bump($companyKey, 'expenses');

        return $expense;
    }

    /**
     * The real payroll expense that reconciles a month's accrued
     * production labor — see JournalService::postProductionLaborExpense()
     * and ExpenseController::postExpenseJournal(). $actualAmount is
     * deliberately allowed to differ a little from what the month's
     * production orders estimated, exactly like a real payroll run
     * would (a bit of overtime, a rounding difference).
     */
    private function recordProductionLaborExpense(
        Company $company,
        string $companyKey,
        Vendor $payrollVendor,
        Category $category,
        Collection $channels,
        Carbon $date,
        float $actualAmount,
    ): Expense {
        $totalForMonth = ProductionOrder::totalLaborForMonth($company->id, $date->toDateString());

        $alreadyClaimed = (float) Expense::query()
            ->where('company_id', $company->id)->where('is_production_labor', true)
            ->whereYear('date', $date->year)->whereMonth('date', $date->month)
            ->sum('production_labor_applied_snapshot');

        $availableToApply = max(0, round($totalForMonth - $alreadyClaimed, 2));

        $plan = $this->choosePaymentPlan($actualAmount, $date, $channels, false);

        $expense = Expense::create([
            'company_id' => $company->id, 'vendor_id' => $payrollVendor->id, 'category_id' => $category->id,
            'date' => $date->toDateString(), 'amount' => $actualAmount, 'due_date' => $plan['due_date'],
            'created_by' => Auth::id(), 'is_production_labor' => true,
            'production_labor_applied_snapshot' => $availableToApply,
        ]);

        $this->journal->postProductionLaborExpense($expense, $availableToApply);

        $payment = $this->paymentRecorder->apply($expense, 'out', $plan['mode'], [
            'date' => $date->toDateString(), 'method' => $plan['method'] ?? 'cash',
            'amount_now' => $plan['amount_now'], 'payment_channel_id' => $plan['channel_id'],
        ], $plan['schedule']);

        if ($payment) {
            $this->journal->postBillPayment($payment);
        }

        $this->bump($companyKey, 'production_labor_expenses');

        return $expense;
    }

    /**
     * A monthly recurring series (rent, salaries) — see the class
     * doc comment for why this posts its own journal entries rather
     * than calling RecurringExpenseService directly. Occurrences
     * dated more than ~35 days before END_DATE are always paid (a
     * real business doesn't let rent sit 6 months unpaid); the most
     * recent one or two are left open on purpose, so the open-bills
     * worklist has something current to show.
     */
    private function recordRecurringSeries(
        Company $company,
        string $companyKey,
        Vendor $vendor,
        Category $category,
        Collection $channels,
        Carbon $firstDate,
        float $amount,
        int $count,
        string $label,
    ): void {
        $recurringId = (string) Str::uuid();
        $endDate = Carbon::parse(self::END_DATE);

        for ($i = 1; $i <= $count; $i++) {
            $occurrenceDate = $firstDate->copy()->addMonthsNoOverflow($i - 1);

            if ($occurrenceDate->gt($endDate)) {
                break;
            }

            $expense = Expense::create([
                'company_id' => $company->id, 'vendor_id' => $vendor->id, 'category_id' => $category->id,
                'date' => $occurrenceDate->toDateString(), 'amount' => $amount,
                'due_date' => $occurrenceDate->copy()->addDays(10)->toDateString(),
                'recurring_id' => $recurringId, 'recurring_index' => $i, 'recurring_count' => $count,
                'recurring_frequency' => 'monthly', 'created_by' => Auth::id(),
            ]);

            $this->journal->postExpenseInvoice($expense);

            // $occurrenceDate is always <= $endDate here (the loop
            // already broke above otherwise), so the plain absolute
            // diff is unambiguous: how many days before the end of
            // the whole dataset this occurrence sits.
            $daysBeforeEnd = $occurrenceDate->diffInDays($endDate);
            $isRecent = $daysBeforeEnd < 35;

            // Recent occurrences: mixed (some paid, some not) so the
            // worklist and aging have current data. Older ones: paid.
            $paid = $isRecent ? mt_rand(1, 100) <= 55 : true;

            if ($paid) {
                [$method, $channel] = $this->methodAndChannel($channels);
                $payDate = $occurrenceDate->copy()->addDays(mt_rand(0, 8));
                if ($payDate->gt($endDate)) {
                    $payDate = $endDate->copy();
                }

                $payment = $expense->payments()->create([
                    'company_id' => $company->id, 'date' => $payDate->toDateString(), 'amount' => $amount,
                    'method' => $method, 'payment_channel_id' => $channel, 'direction' => 'out',
                ]);

                $this->journal->postBillPayment($payment);
            }

            $this->bump($companyKey, 'recurring_expenses');
        }

        $this->line("  · recurring series set up: {$label} ({$count} occurrences)");
    }

    private function recordEquipment(
        Company $company,
        string $companyKey,
        Vendor $vendor,
        Category $category,
        Collection $channels,
        Carbon $date,
        string $name,
        float $amount,
        ?int $usefulLifeYears = null,
    ): EquipmentPurchase {
        $plan = $this->choosePaymentPlan($amount, $date, $channels, true, 20000);

        $purchase = EquipmentPurchase::create([
            'company_id' => $company->id, 'vendor_id' => $vendor->id, 'category_id' => $category->id,
            'name' => $name, 'qty' => 1, 'unit_price' => $amount, 'amount' => $amount,
            'date' => $date->toDateString(), 'due_date' => $plan['due_date'],
            'useful_life_years' => $usefulLifeYears ?? $category->default_useful_life_years ?? 5,
            'created_by' => Auth::id(),
        ]);

        $this->journal->postEquipmentPurchaseInvoice($purchase);

        $payment = $this->paymentRecorder->apply($purchase, 'out', $plan['mode'], [
            'date' => $date->toDateString(), 'method' => $plan['method'] ?? 'cash',
            'amount_now' => $plan['amount_now'], 'payment_channel_id' => $plan['channel_id'],
        ], $plan['schedule']);

        if ($payment) {
            $this->journal->postBillPayment($payment);
        }

        $this->settleFollowUps($purchase, 'out', false, $plan, $amount, (float) ($payment?->amount ?? 0), $channels);

        $this->bump($companyKey, 'equipment');

        return $purchase;
    }

    /**
     * Hand out a custody advance and settle it a few days/weeks
     * later — see Custody::settle() and JournalService::postCustody*().
     *
     * @param  array<int, Category>  $spendCategories  possible categories the holder spent it on
     */
    private function recordCustodyCycle(
        Company $company,
        string $companyKey,
        Vendor $holder,
        array $spendCategories,
        Carbon $givenAt,
        float $amount,
    ): void {
        $method = $this->pick(['cash', 'cash', 'bank']);

        $custody = Custody::create([
            'company_id' => $company->id, 'holder_id' => $holder->id, 'amount' => $amount,
            'method' => $method, 'given_at' => $givenAt->toDateString(), 'created_by' => Auth::id(),
        ]);

        $custody->payments()->create([
            'company_id' => $company->id, 'date' => $givenAt->toDateString(), 'amount' => $amount,
            'method' => $method, 'direction' => 'out',
        ]);

        $this->journal->postCustodyGiven($custody);

        $settlementDate = $givenAt->copy()->addDays(mt_rand(5, 21));
        $endDate = Carbon::parse(self::END_DATE);
        if ($settlementDate->gt($endDate)) {
            $settlementDate = $endDate->copy();
        }

        // Split the amount across 1-3 spend categories; sometimes a
        // little less was spent (leftover comes back), sometimes a
        // little more (the company reimburses the difference).
        $variance = $this->money($amount * 0.85, $amount * 1.1);
        $lineCount = mt_rand(1, min(3, count($spendCategories)));
        $chosen = collect($spendCategories)->shuffle()->take($lineCount);
        $remaining = $variance;
        $lines = [];

        foreach ($chosen as $index => $category) {
            $isLast = $index === $chosen->count() - 1;
            $lineAmount = $isLast ? round($remaining, 2) : round($variance / $lineCount, 2);
            $remaining -= $lineAmount;

            $lines[] = ['description' => $category->name.' expenses', 'category_id' => $category->id, 'amount' => max(0, $lineAmount)];
        }

        $custody->settle($lines, $settlementDate->toDateString());
        $custody->refresh();

        if ($custody->leftover_returned > 0) {
            $custody->payments()->create([
                'company_id' => $company->id, 'date' => $custody->settlement_date, 'amount' => $custody->leftover_returned,
                'method' => $custody->method, 'direction' => 'in',
            ]);
        }

        if ($custody->extra_reimbursed > 0) {
            $custody->payments()->create([
                'company_id' => $company->id, 'date' => $custody->settlement_date, 'amount' => $custody->extra_reimbursed,
                'method' => $custody->method, 'direction' => 'out',
            ]);
        }

        $this->journal->postCustodySettlement($custody);

        $this->bump($companyKey, 'custodies');
    }

    // ══════════════════════════════════════════════════════════════
    //  Summary
    // ══════════════════════════════════════════════════════════════

    private function printSummary(): void
    {
        $this->newLine();
        $this->info('═══ Done ═══');

        foreach ($this->stats as $companyName => $counts) {
            $this->newLine();
            $this->line("<comment>{$companyName}</comment>");
            $rows = collect($counts)->map(fn ($v, $k) => [str_replace('_', ' ', $k), $v])->values()->all();
            $this->table(['What', 'Count'], $rows);
        }

        $this->newLine();
        $this->line('<comment>Login credentials (all users, same password):</comment>');
        $rows = [];
        foreach ($this->credentials as $companyName => $users) {
            foreach ($users as $u) {
                $rows[] = [$companyName, $u['role'], $u['email'], $u['password']];
            }
        }
        $this->table(['Company', 'Role', 'Email', 'Password'], $rows);

        $this->newLine();
        $this->comment('Undo any of this with:  php artisan demo:seed-companies --clear');
    }
}