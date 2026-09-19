<?php

namespace App\Console\Commands;

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
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — SeedPizzaRestaurantDemo
//  Location: app/Console/Commands/SeedPizzaRestaurantDemo.php
//
//  `php artisan demo:seed-pizza-restaurant` — builds ONE demo
//  company, an Arabic-named pizza restaurant, business type
//  "production" (dough/cheese/toppings are raw materials; each
//  pizza is a produced product, exactly like the furniture-maker
//  demo company treats a Dining Table). Covers 1 Jan 2025 → 18 Sep
//  2026 with realistic day-to-day trade: raw-material purchases,
//  pizza production runs, walk-in/delivery sales, and the usual
//  expenses/equipment/payroll/custody activity around it.
//
//  Every name a person would actually see in the app — the company,
//  its people, customers, vendors, categories, payment channels,
//  ingredients and the 5 pizzas themselves — is in Arabic. Code
//  comments stay in English, same as the rest of this codebase.
//
//  This is a smaller, single-company sibling of
//  SeedThreeDemoCompanies — same approach (post everything through
//  the app's own services, never write a ledger row directly) but
//  copied into its own file rather than sharing code with that
//  command, so this can be added, tweaked or removed without
//  touching the three-company seeder at all.
// ══════════════════════════════════════════════════════════════════
class SeedPizzaRestaurantDemo extends Command
{
    protected $signature = 'demo:seed-pizza-restaurant
        {--clear : Remove this demo company (and everything under it), then stop}
        {--seed=42 : Random seed, so re-running with the same seed reproduces the same data}';

    protected $description = 'Create an Arabic-named demo pizza restaurant (production company) with ~20 months of realistic transactions';

    private const START_DATE = '2025-01-01';
    private const END_DATE   = '2026-09-18';
    private const VAT_RATE   = 14.0;
    private const PASSWORD   = 'Demo@12345';

    private const COMPANY_NAME = 'بيتزا نابولي';

    /** @var array<int, array{email:string, password:string, role:string}> */
    private array $credentials = [];

    /** @var array<string, int> */
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
            $this->clearCompany();

            return self::SUCCESS;
        }

        if (Company::where('name', self::COMPANY_NAME)->exists()) {
            $this->warn('Skipping "'.self::COMPANY_NAME.'" — it already exists. Run with --clear first if you want to rebuild it.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('═══ Building: '.self::COMPANY_NAME.' ═══');

        $this->seedCompany();

        Auth::logout();

        $this->newLine();
        $this->info('Posting depreciation catch-up through '.self::END_DATE.'...');
        $posted = $this->depreciation->catchUpAllCompanies(Carbon::parse(self::END_DATE));
        $this->line("  → {$posted} monthly depreciation ".($posted === 1 ? 'entry' : 'entries').' posted.');

        $this->printSummary();

        return self::SUCCESS;
    }

    /**
     * See SeedThreeDemoCompanies::clearCompany() for why deleting
     * the company row is enough — every domain table cascades from
     * companies.id ON DELETE CASCADE, users don't so they go first.
     */
    private function clearCompany(): void
    {
        $company = Company::where('name', self::COMPANY_NAME)->first();

        if (! $company) {
            $this->line('"'.self::COMPANY_NAME.'" doesn\'t exist — nothing to remove.');

            return;
        }

        DB::transaction(function () use ($company) {
            $userCount = User::where('company_id', $company->id)->count();
            User::where('company_id', $company->id)->delete();
            $company->delete();
            $this->info('Removed "'.self::COMPANY_NAME."\" (#{$company->id}) — {$userCount} user(s) and everything else under it.");
        });
    }

    // ══════════════════════════════════════════════════════════════
    //  Shared setup helpers — same shape as SeedThreeDemoCompanies'
    //  equivalents, copied rather than shared so this file has no
    //  dependency on that one.
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
            'name'       => $adminName,
            'email'      => $adminEmail,
            'password'   => self::PASSWORD,
            'role'       => 'company_admin',
            'company_id' => $company->id,
            'language'   => 'ar',
            'is_active'  => true,
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

        // Log in as this company's admin for the rest of the run —
        // see SeedThreeDemoCompanies' class doc comment for why this
        // matters (auto-fill of company_id, and every auth()->id()
        // call the services use).
        Auth::login($admin);

        $employees = collect($employeeDefs)->map(function (array $e) use ($company, $admin) {
            $employee = User::create([
                'name'       => $e['name'],
                'email'      => $e['email'],
                'password'   => self::PASSWORD,
                'role'       => 'employee',
                'company_id' => $company->id,
                'created_by' => $admin->id,
                'language'   => 'ar',
                'is_active'  => true,
            ]);

            $employee->forceFill(['email_verified_at' => '2026-01-01'])->save();

            return $employee;
        });

        $channels = collect($channelNames)->map(fn ($n) => PaymentChannel::create([
            'company_id' => $company->id,
            'name'       => $n,
        ]));

        // Same reasoning as SeedThreeDemoCompanies::bootCompany() —
        // every seeded sale picks a real sales channel instead of
        // leaving sales_channel_id empty.
        $salesChannels = SalesChannel::query()->where('company_id', $company->id)->get();

        $this->credentials = collect([$admin])->concat($employees)
            ->map(fn (User $u) => ['email' => $u->email, 'password' => self::PASSWORD, 'role' => $u->role])
            ->all();

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
                'uom'            => $def['uom'] ?? 'وحدة',
                'qty_per_uom'    => 1,
                'base_unit_name' => $def['base_unit_name'] ?? ($def['uom'] ?? 'وحدة'),
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
     * One weight per calendar day: a linear growth ramp (the
     * restaurant gets busier over time as it becomes known) with a
     * Thursday/Friday lift instead of a weekend dampening — Egyptian
     * families eat out most on Thursday and Friday nights, the
     * opposite pattern from an office-hours B2B business.
     *
     * @return array<int, float> indexed 0..totalDays-1
     */
    private function dailyWeights(int $totalDays): array
    {
        $weights = [];
        $start = Carbon::parse(self::START_DATE);

        for ($i = 0; $i < $totalDays; $i++) {
            $w = 0.55 + 1.0 * ($i / max(1, $totalDays - 1)); // 0.55 → 1.55 growth ramp

            $dow = $start->copy()->addDays($i)->dayOfWeekIso; // 1=Mon .. 7=Sun
            if ($dow === 4 || $dow === 5) { // Thursday, Friday — the busy nights
                $w *= 1.5;
            } elseif ($dow === 6) { // Saturday — still good
                $w *= 1.15;
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
    //  Payment plan / method helpers
    // ══════════════════════════════════════════════════════════════

    /**
     * @param  Collection<int, PaymentChannel>  $channels
     * @return array{0: string, 1: ?int}  [method, payment_channel_id]
     */
    private function methodAndChannel(Collection $channels): array
    {
        $method = $this->pick(['cash', 'cash', 'cash', 'bank', 'instapay', 'wallet']);

        if ($method === 'cash' || $channels->isEmpty()) {
            return [$method, null];
        }

        $matching = match ($method) {
            'bank'     => $channels->filter(fn ($c) => str_contains($c->name, 'بنك')),
            'instapay' => $channels->filter(fn ($c) => str_contains($c->name, 'إنستاباي')),
            'wallet'   => $channels->filter(fn ($c) => str_contains($c->name, 'فودافون')),
            default    => collect(),
        };

        $chosen = $matching->isNotEmpty() ? $matching->random() : $channels->random();

        return [$method, $chosen->id];
    }

    /**
     * B2B-style payment plan (now / partial / later / installment) —
     * used for supplier purchases, expenses and equipment, where
     * credit terms are normal. See chooseRetailSalePaymentPlan()
     * below for the very different, mostly-cash pattern used for
     * the pizza sales themselves.
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

    /**
     * A pizza order is nothing like a furniture sale on credit — a
     * walk-in/delivery customer pays for their food the same day,
     * almost always in full. The only realistic exception this demo
     * models is an occasional catering order (a hotel or a company
     * ordering many pizzas for an event), which sometimes gets a
     * short list of "pay on delivery / within a few days" terms —
     * still no long credit and never an installment plan, since no
     * one finances a pizza order over months.
     *
     * @param  Collection<int, PaymentChannel>  $channels
     * @return array{mode:string, method:?string, channel_id:?int, amount_now:?float, due_date:?string, schedule:array}
     */
    private function chooseRetailSalePaymentPlan(float $total, Carbon $date, Collection $channels, bool $isCatering): array
    {
        if ($isCatering && $total >= 800 && mt_rand(1, 100) <= 35) {
            $due = $date->copy()->addDays(mt_rand(3, 10))->toDateString();

            return ['mode' => 'later', 'method' => null, 'channel_id' => null, 'amount_now' => null, 'due_date' => $due, 'schedule' => []];
        }

        [$method, $channel] = $this->methodAndChannel($channels);

        return ['mode' => 'now', 'method' => $method, 'channel_id' => $channel, 'amount_now' => null, 'due_date' => null, 'schedule' => []];
    }

    private function bump(string $key, int $by = 1): void
    {
        $this->stats[$key] = ($this->stats[$key] ?? 0) + $by;
    }

    /**
     * Same 55-day cutoff idea as SeedThreeDemoCompanies — how close
     * to END_DATE a balance can be due and still plausibly have been
     * collected/paid already, so the open-invoice/open-bill
     * worklists keep some genuinely current, unsettled items rather
     * than everything looking closed.
     */
    private const COLLECTION_CUTOFF_DAYS = 55;

    private function settleFollowUps($payable, string $direction, bool $isSale, array $plan, float $total, float $paidSoFar, Collection $channels): void
    {
        $endDate = Carbon::parse(self::END_DATE);
        $cutoff = $endDate->copy()->subDays(self::COLLECTION_CUTOFF_DAYS);

        $pay = function (float $amount, Carbon $dueDate) use ($payable, $direction, $isSale, $channels, $endDate) {
            if ($amount <= 0.01) {
                return;
            }
            if (mt_rand(1, 100) <= 8) {
                return; // genuine bad debt / very slow payer, left open on purpose
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
    //  Transaction recorders
    // ══════════════════════════════════════════════════════════════

    /**
     * @param  array<int, array{item:Item, def:array}>  $catalog  the 5 pizzas
     * @param  array<int, float>  $stock  item_id => qty on hand, kept in sync here
     */
    private function recordSale(
        Company $company,
        Collection $customers,
        Collection $cateringCustomers,
        array $catalog,
        Collection $channels,
        Collection $salesChannels,
        Carbon $date,
        array &$stock,
    ): ?Sale {
        // ~7% of orders are a catering order from a hotel/company
        // rather than an individual walk-in/delivery customer —
        // bigger tickets, occasionally paid a few days later.
        $isCatering = $cateringCustomers->isNotEmpty() && mt_rand(1, 100) <= 7;
        $customer = $isCatering ? $cateringCustomers->random() : $customers->random();

        // Most orders are 1-2 pizzas; a catering order is a real
        // batch for an event.
        $lineCount = $isCatering
            ? mt_rand(2, min(5, count($catalog)))
            : (mt_rand(1, 100) <= 60 ? 1 : (mt_rand(1, 100) <= 85 ? 2 : 3));

        $lines = [];
        $attempts = 0;

        while (count($lines) < $lineCount && $attempts < $lineCount * 4) {
            $attempts++;
            ['item' => $item, 'def' => $def] = $this->pick($catalog);

            $available = $stock[$item->id] ?? 0.0;
            if ($available < 1) {
                continue;
            }

            $qtyRange = $isCatering ? [8, 20] : ($def['sale_qty'] ?? [1, 2]);
            $qty = min($this->qty($qtyRange[0], $qtyRange[1]), $available);
            $qty = round($qty);

            if ($qty <= 0) {
                continue;
            }

            $sellRange = $def['sell'] ?? [100, 200];
            $price = $this->money($sellRange[0], $sellRange[1], 5);

            $lines[] = ['item_id' => $item->id, 'qty' => $qty, 'unit_price' => $price, 'line_total' => round($qty * $price, 2)];
            $stock[$item->id] = round($available - $qty, 2);
        }

        if (empty($lines)) {
            return null;
        }

        $subtotal = round(array_sum(array_column($lines, 'line_total')), 2);
        $vatRate = mt_rand(1, 100) <= 90 ? self::VAT_RATE : 0.0;
        $vatAmount = round($subtotal * $vatRate / 100, 2);
        $total = round($subtotal + $vatAmount, 2);

        $plan = $this->chooseRetailSalePaymentPlan($total, $date, $channels, $isCatering);

        $sale = Sale::create([
            'company_id'  => $company->id,
            'customer_id' => $customer->id,
            // Every sale picks a real sales channel — see this
            // command's bootCompany-equivalent setup — instead of
            // leaving this null and letting the Dashboard's channel
            // donut fall back to an all-"Direct Sales" default.
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

        // Same fix as SeedThreeDemoCompanies::recordSale() — price
        // through MovingAverageCostingService (what SaleController
        // actually uses), not the legacy Item::averagePurchaseCost()
        // this used to call directly. See that method's doc comment
        // for the full reasoning.
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

        $this->bump('sales');
        $this->bump('sales_'.$plan['mode']);
        if ($isCatering) {
            $this->bump('catering_orders');
        }

        return $sale;
    }

    /**
     * @param  array<int, array{item:Item, def:array}>  $catalog  raw ingredients
     * @param  array<int, float>  $stock
     */
    private function recordPurchase(
        Company $company,
        Collection $vendors,
        array $catalog,
        Collection $channels,
        Carbon $date,
        array &$stock,
        int $lineCount = 0,
        ?float $qtyMultiplier = null,
    ): InventoryPurchase {
        $vendor = $vendors->random();
        $lineCount = $lineCount > 0 ? $lineCount : mt_rand(3, 6);
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
                'item_id' => $item->id, 'qty' => $qty, 'uom' => $def['uom'] ?? 'وحدة',
                'qty_per_uom' => 1, 'base_unit_name' => $def['base_unit_name'] ?? ($def['uom'] ?? 'وحدة'),
                'unit_price' => $price, 'line_total' => round($qty * $price, 2),
            ];

            $stock[$item->id] = round(($stock[$item->id] ?? 0) + $qty, 2);
        }

        $subtotal = round(array_sum(array_column($lines, 'line_total')), 2);
        $vatAmount = round($subtotal * self::VAT_RATE / 100, 2);
        $total = round($subtotal + $vatAmount, 2);

        $plan = $this->choosePaymentPlan($total, $date, $channels, true, 15000);

        $purchase = InventoryPurchase::create([
            'company_id' => $company->id, 'vendor_id' => $vendor->id, 'date' => $date->toDateString(),
            'subtotal' => $subtotal, 'vat_rate' => self::VAT_RATE, 'vat_amount' => $vatAmount, 'amount' => $total,
            'due_date' => $plan['due_date'], 'created_by' => Auth::id(),
        ]);

        foreach ($lines as $line) {
            $purchase->lines()->create($line);
        }

        $this->journal->postInventoryPurchaseInvoice($purchase);

        // Same fix as SeedThreeDemoCompanies::recordPurchase() — see
        // that method's doc comment.
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

        $this->bump('inventory_purchases');

        return $purchase;
    }

    /**
     * @param  array<string, array{qty_per_unit:float}>  $recipe  ingredient name => qty consumed per pizza
     * @param  array<string, Item>  $ingredientsByName
     * @param  array<int, float>  $stock  item_id => qty on hand
     */
    private function recordProductionOrder(
        Item $pizza,
        array $recipe,
        array $ingredientsByName,
        Carbon $date,
        float $qtyProduced,
        float $laborPerUnit,
        array &$stock,
    ): ?ProductionOrder {
        $materials = [];

        foreach ($recipe as $ingredientName => $spec) {
            $rawItem = $ingredientsByName[$ingredientName];
            $needed = round($spec['qty_per_unit'] * $qtyProduced, 3);
            $available = $stock[$rawItem->id] ?? 0.0;

            if ($available < $needed) {
                if ($available < $needed * 0.4) {
                    return null; // not enough of this ingredient to plausibly run a batch
                }
                $needed = round($available, 3);
            }

            $materials[] = ['item_id' => $rawItem->id, 'qty' => $needed];
            $stock[$rawItem->id] = round($available - $needed, 3);
        }

        $order = $this->productionOrders->create([
            'item_id'      => $pizza->id,
            'date'         => $date->toDateString(),
            'qty_produced' => $qtyProduced,
            'materials'    => $materials,
            'labor_cost'   => round($laborPerUnit * $qtyProduced, 2),
        ]);

        $stock[$pizza->id] = round(($stock[$pizza->id] ?? 0) + $qtyProduced, 2);

        $this->bump('production_orders');

        return $order;
    }

    /**
     * $vendor is nullable at the call site but the database column
     * is NOT NULL — see SeedThreeDemoCompanies::recordExpense()'s
     * doc comment: a real "Cash Expense" resolves to
     * Vendor::cashVendor(), never a literal null.
     */
    private function recordExpense(
        Company $company,
        ?Vendor $vendor,
        Category $category,
        Collection $channels,
        Carbon $date,
        float $amount,
    ): Expense {
        $vendor ??= Vendor::cashVendor($company->id);

        $plan = $this->choosePaymentPlan($amount, $date, $channels, false, 15000);

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

        $this->bump('expenses');

        return $expense;
    }

    /**
     * The real payroll expense that reconciles a month's accrued
     * production labor — see SeedThreeDemoCompanies' equivalent for
     * the full explanation; identical logic here.
     */
    private function recordProductionLaborExpense(
        Company $company,
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

        $this->bump('production_labor_expenses');

        return $expense;
    }

    private function recordRecurringSeries(
        Company $company,
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

            $daysBeforeEnd = $occurrenceDate->diffInDays($endDate);
            $isRecent = $daysBeforeEnd < 35;
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

            $this->bump('recurring_expenses');
        }

        $this->line("  · recurring series set up: {$label} ({$count} occurrences)");
    }

    private function recordEquipment(
        Company $company,
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

        $this->bump('equipment');

        return $purchase;
    }

    /**
     * @param  array<int, Category>  $spendCategories
     */
    private function recordCustodyCycle(
        Company $company,
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

        $variance = $this->money($amount * 0.85, $amount * 1.1);
        $lineCount = mt_rand(1, min(3, count($spendCategories)));
        $chosen = collect($spendCategories)->shuffle()->take($lineCount);
        $remaining = $variance;
        $lines = [];

        foreach ($chosen as $index => $category) {
            $isLast = $index === $chosen->count() - 1;
            $lineAmount = $isLast ? round($remaining, 2) : round($variance / $lineCount, 2);
            $remaining -= $lineAmount;

            $lines[] = ['description' => 'مصروفات '.$category->name, 'category_id' => $category->id, 'amount' => max(0, $lineAmount)];
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

        $this->bump('custodies');
    }

    // ══════════════════════════════════════════════════════════════
    //  The company itself
    // ══════════════════════════════════════════════════════════════

    private function seedCompany(): void
    {
        ['company' => $company, 'channels' => $channels, 'sales_channels' => $salesChannels] = $this->bootCompany(
            name: self::COMPANY_NAME,
            businessTypes: ['production'],
            adminName: 'كريم عبدالله',
            adminEmail: 'admin@napoli-pizza-demo.com',
            employeeDefs: [
                ['name' => 'منى حسن', 'email' => 'mona@napoli-pizza-demo.com'],
                ['name' => 'طارق سعيد', 'email' => 'tarek@napoli-pizza-demo.com'],
            ],
            channelNames: ['فودافون كاش', 'إنستاباي', 'البنك الأهلي - حساب جاري'],
        );

        // ── Customers: mostly individual walk-in/delivery diners,
        //    plus a couple of recurring catering accounts (a hotel,
        //    a company) — see recordSale()'s $isCatering branch.
        $customers = $this->makeCustomers($company->id, [
            'أحمد سليم', 'ياسمين علي', 'محمد عبد الرحمن', 'نور الهدى إبراهيم',
            'سارة إبراهيم', 'عمر خالد', 'هبة الله محمود', 'كريم فتحي',
            'منة الله أحمد', 'يوسف حسن', 'داليا وجدي', 'مصطفى رضا',
        ]);
        $cateringCustomers = $this->makeCustomers($company->id, [
            'فندق النيل بالاس', 'شركة دلتا للمقاولات',
        ]);

        $vendors = $this->makeVendors($company->id, [
            'مطاحن مصر للدقيق', 'شركة الألبان المصرية', 'سوق الخضار المركزي',
            'استيراد اللحوم الطازجة', 'توريدات الزيوت والتوابل',
        ]);
        $payrollVendor = $this->makeVendors($company->id, ['رواتب الموظفين'], 'employee')->first();
        $custodyHolders = $this->makeVendors($company->id, ['شريف جمال', 'داليا عماد'], 'employee');

        $this->addCategory($company->id, 'أجور الإنتاج', 'expense');
        $this->addCategory($company->id, 'الصيانة', 'expense');

        // ── Raw ingredients ──────────────────────────────────────
        $ingredients = $this->makeItems($company->id, [
            ['name' => 'دقيق بيتزا',        'type' => 'raw_material', 'uom' => 'كيلو', 'buy' => [18, 24],  'purchase_qty' => [60, 110]],
            ['name' => 'خميرة',             'type' => 'raw_material', 'uom' => 'كيلو', 'buy' => [70, 90],  'purchase_qty' => [2, 5]],
            ['name' => 'زيت زيتون',         'type' => 'raw_material', 'uom' => 'لتر',  'buy' => [150, 190],'purchase_qty' => [8, 18]],
            ['name' => 'صلصة طماطم',        'type' => 'raw_material', 'uom' => 'لتر',  'buy' => [35, 48],  'purchase_qty' => [20, 45]],
            ['name' => 'جبنة موتزاريلا',    'type' => 'raw_material', 'uom' => 'كيلو', 'buy' => [180, 230],'purchase_qty' => [25, 55]],
            ['name' => 'جبنة شيدر',         'type' => 'raw_material', 'uom' => 'كيلو', 'buy' => [200, 250],'purchase_qty' => [10, 22]],
            ['name' => 'بيبروني',           'type' => 'raw_material', 'uom' => 'كيلو', 'buy' => [220, 270],'purchase_qty' => [8, 18]],
            ['name' => 'فطر',               'type' => 'raw_material', 'uom' => 'كيلو', 'buy' => [45, 65],  'purchase_qty' => [6, 14]],
            ['name' => 'فلفل ألوان',        'type' => 'raw_material', 'uom' => 'كيلو', 'buy' => [30, 45],  'purchase_qty' => [6, 14]],
            ['name' => 'زيتون أسود',        'type' => 'raw_material', 'uom' => 'كيلو', 'buy' => [90, 120], 'purchase_qty' => [4, 10]],
            ['name' => 'دجاج مشوي مفروم',   'type' => 'raw_material', 'uom' => 'كيلو', 'buy' => [130, 160],'purchase_qty' => [10, 22]],
            ['name' => 'أعشاب وتوابل',      'type' => 'raw_material', 'uom' => 'كيلو', 'buy' => [60, 90],  'purchase_qty' => [2, 5]],
        ]);
        $ingredientsByName = collect($ingredients)->keyBy(fn ($row) => $row['item']->name)->map(fn ($row) => $row['item']);

        // name => [sell range, labor per pizza, qty-per-batch range, recipe (ingredient name => qty consumed per pizza)]
        $base = ['دقيق بيتزا' => 0.22, 'خميرة' => 0.01, 'زيت زيتون' => 0.02, 'صلصة طماطم' => 0.09, 'جبنة موتزاريلا' => 0.14];
        $pizzaDefs = [
            'بيتزا مارجريتا'      => [[110, 150], 12, [10, 25], $base + ['أعشاب وتوابل' => 0.008]],
            'بيتزا بيبروني'       => [[150, 195], 15, [10, 22], $base + ['بيبروني' => 0.07]],
            'بيتزا الخضار'        => [[135, 175], 14, [8, 18],  $base + ['فطر' => 0.05, 'فلفل ألوان' => 0.05, 'زيتون أسود' => 0.03]],
            'بيتزا الدجاج'        => [[160, 210], 16, [8, 18],  $base + ['دجاج مشوي مفروم' => 0.09, 'جبنة شيدر' => 0.04]],
            'بيتزا الأربع أجبان'  => [[170, 220], 15, [6, 15],  $base + ['جبنة شيدر' => 0.08, 'أعشاب وتوابل' => 0.008]],
        ];

        $pizzaItems = $this->makeItems($company->id, collect($pizzaDefs)->map(fn ($def, $name) => [
            'name' => $name, 'type' => 'product', 'uom' => 'قطعة', 'sell' => $def[0], 'sale_qty' => [1, 2],
        ])->values()->all());
        $pizzasByName = collect($pizzaItems)->keyBy(fn ($row) => $row['item']->name);
        $pizzaCatalog = $pizzaItems; // sold only when produced, never bought — recordSale only reads 'sell'

        // Category::seedDefaults() creates these with an English
        // canonical `name` (Machine/Vehicle/Other/Rent/Salaries/...)
        // plus a `name_ar` translation — the UI already shows
        // name_ar automatically for any user whose language is 'ar'
        // (every user this command creates), so looking these up by
        // their real English name still ends up fully Arabic on
        // screen. See Category::seedDefaults() and ComboSelect.vue.
        $ovenCat = $this->equipmentCategory($company->id, 'Machine');
        $vehicleCat = $this->equipmentCategory($company->id, 'Vehicle');
        $otherEquipCat = $this->equipmentCategory($company->id, 'Other');

        $openingDate = Carbon::parse(self::START_DATE);
        $stock = [];

        $this->openingBalances->submit($company->id, [
            'opening_date' => $openingDate->toDateString(),
            'cash_amount' => 15000, 'bank_amount' => 60000,
            'customers' => [
                ['customer_id' => $cateringCustomers[0]->id, 'amount' => 3200],
            ],
            'suppliers' => [
                ['vendor_id' => $vendors[0]->id, 'amount' => 4200],
            ],
            'inventory' => [
                ['item_id' => $ingredientsByName['دقيق بيتزا']->id, 'qty' => 80, 'unit_price' => 20],
                ['item_id' => $ingredientsByName['جبنة موتزاريلا']->id, 'qty' => 30, 'unit_price' => 200],
            ],
            'equipment' => [
                ['name' => 'فرن بيتزا كهربائي (افتتاح)', 'category_id' => $ovenCat->id, 'amount' => 85000, 'date' => $openingDate->toDateString()],
            ],
        ]);
        $this->line('  · opening balance posted');

        // Guaranteed initial ingredient stock-up before any dough
        // goes in the oven.
        $this->recordPurchase($company, collect([$vendors[0], $vendors[1]]), $ingredients, $channels, $openingDate->copy()->addDays(1), $stock, lineCount: 8, qtyMultiplier: 3.0);
        $this->recordPurchase($company, collect([$vendors[2], $vendors[3], $vendors[4]]), $ingredients, $channels, $openingDate->copy()->addDays(2), $stock, lineCount: 8, qtyMultiplier: 3.0);

        $this->recordRecurringSeries($company, $vendors[0], $this->expenseCategory($company->id, 'Rent'), $channels, $openingDate->copy()->addDays(3), 18000, 20, 'إيجار المحل');
        $this->recordRecurringSeries($company, $payrollVendor, $this->expenseCategory($company->id, 'Salaries'), $channels, $openingDate->copy()->addDays(25), 22000, 20, 'رواتب الكاشير والتوصيل');

        $equipmentPlan = [
            [45, 'فرن بيتزا كهربائي إضافي', 85000, $ovenCat, 10],
            [150, 'دراجة توصيل', 45000, $vehicleCat, 5],
            [260, 'ثلاجة تبريد كبيرة', 35000, $otherEquipCat, 8],
            [400, 'عربة توصيل (تروسيكل)', 60000, $vehicleCat, 6],
        ];
        foreach ($equipmentPlan as [$dayOffset, $name, $amount, $cat, $life]) {
            $this->recordEquipment($company, $vendors->random(), $cat, $channels, $openingDate->copy()->addDays($dayOffset), $name, $amount, $life);
        }

        $totalDays = $this->totalDays();
        $weights = $this->dailyWeights($totalDays);
        // A pizza place trades far more often, in far smaller
        // tickets, than a furniture workshop — target ~2 sales/day
        // on average, growing over time via the same weight shape.
        $saleCounts = $this->spreadCounts($totalDays, 1250, $weights);
        $purchaseCounts = $this->spreadCounts($totalDays, 150, array_fill(0, $totalDays, 1.0));
        // Production has to comfortably outpace total pizzas sold
        // (sales draw from real stock) — 800 runs x ~14 pizzas/run
        // average gives a healthy buffer over estimated demand, so
        // recordSale() isn't frequently skipped for lack of stock.
        $productionCounts = $this->spreadCounts($totalDays, 800, $weights);
        $expenseCounts = $this->spreadCounts($totalDays, 130, array_fill(0, $totalDays, 1.0));

        // 'الصيانة' is the custom Arabic category created above;
        // the other three are real seeded categories looked up by
        // their actual English name (see the equipment-category note
        // above for why that still displays in Arabic).
        $expenseCats = collect([
            $this->expenseCategory($company->id, 'الصيانة'),
            $this->expenseCategory($company->id, 'Utilities'),
            $this->expenseCategory($company->id, 'Office Supplies'),
            $this->expenseCategory($company->id, 'Other'),
        ]);
        $spendCats = [
            $this->expenseCategory($company->id, 'الصيانة'),
            $this->expenseCategory($company->id, 'Other'),
        ];
        $laborCategory = $this->expenseCategory($company->id, 'أجور الإنتاج');
        $pizzaNames = array_keys($pizzaDefs);

        $bar = $this->output->createProgressBar($totalDays);
        $bar->start();
        $lastLaborMonth = null;

        for ($d = 0; $d < $totalDays; $d++) {
            $date = $this->dateAt($d);

            for ($i = 0; $i < $purchaseCounts[$d]; $i++) {
                $this->recordPurchase($company, $vendors, $ingredients, $channels, $date, $stock, lineCount: mt_rand(3, 6));
            }

            for ($i = 0; $i < $productionCounts[$d]; $i++) {
                $pizzaName = $this->pick($pizzaNames);
                [$sellRange, $laborPerUnit, $qtyRange, $recipe] = $pizzaDefs[$pizzaName];
                $recipeSpec = collect($recipe)->map(fn ($qty) => ['qty_per_unit' => $qty])->all();
                $qtyProduced = round($this->qty($qtyRange[0], $qtyRange[1]));
                $this->recordProductionOrder(
                    $pizzasByName[$pizzaName]['item'], $recipeSpec, $ingredientsByName->all(),
                    $date, $qtyProduced, $laborPerUnit, $stock,
                );
            }

            for ($i = 0; $i < $saleCounts[$d]; $i++) {
                $this->recordSale($company, $customers, $cateringCustomers, $pizzaCatalog, $channels, $salesChannels, $date, $stock);
            }

            for ($i = 0; $i < $expenseCounts[$d]; $i++) {
                $cat = $expenseCats->random();
                $amount = match ($cat->name) {
                    'الصيانة' => $this->money(300, 3000),
                    'Utilities' => $this->money(800, 4500),
                    'Office Supplies' => $this->money(100, 700),
                    default => $this->money(150, 1500),
                };
                $this->recordExpense($company, $vendors->random(), $cat, $channels, $date, $amount);
            }

            // Production-labor payroll, posted once per calendar
            // month on the 28th — see recordProductionLaborExpense().
            if ($date->day === 28 && $date->format('Y-m') !== $lastLaborMonth) {
                $lastLaborMonth = $date->format('Y-m');
                $estimate = ProductionOrder::totalLaborForMonth($company->id, $date->toDateString());
                if ($estimate > 0) {
                    $actual = round($estimate * (mt_rand(92, 108) / 100), 2);
                    $this->recordProductionLaborExpense($company, $payrollVendor, $laborCategory, $channels, $date, $actual);
                }
            }

            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        for ($i = 0; $i < 12; $i++) {
            $date = $openingDate->copy()->addDays(15 + (int) round($i * ($totalDays - 30) / 12));
            $this->recordCustodyCycle($company, $custodyHolders->random(), $spendCats, $date, $this->money(400, 1800));
        }

        // Small, quick walk-in orders — same recordSale() as every
        // other sale, always with a real product (a pizza) and a
        // real sales channel attached. This used to be a fake "cash
        // received, no invoice, no product" receipt
        // (recordStandaloneReceipt) — removed because a sale can
        // never exist without a product now, matching the real app:
        // "Cash Sales" moved under the Sales tab, where a product is
        // required.
        for ($i = 0; $i < 10; $i++) {
            $date = $openingDate->copy()->addDays(22 + (int) round($i * ($totalDays - 44) / 10));
            $this->recordSale($company, $customers, $cateringCustomers, $pizzaCatalog, $channels, $salesChannels, $date, $stock);
        }
        // Small, quick petty-cash-style expenses — same recordExpense()
        // as every other expense, always with a real category. This
        // used to be a fake "cash paid, no bill, no category" payment
        // (recordStandalonePayment) — removed for the same reason:
        // "cash expenses" moved under the Expense tab, where a
        // category is required.
        for ($i = 0; $i < 15; $i++) {
            $date = $openingDate->copy()->addDays(9 + (int) round($i * ($totalDays - 18) / 15));
            $this->recordExpense($company, null, $this->expenseCategory($company->id, 'Other'), $channels, $date, $this->money(50, 500));
        }

        Auth::logout();
    }

    // ══════════════════════════════════════════════════════════════
    //  Summary
    // ══════════════════════════════════════════════════════════════

    private function printSummary(): void
    {
        $this->newLine();
        $this->info('═══ Done ═══');

        $this->newLine();
        $this->line('<comment>'.self::COMPANY_NAME.'</comment>');
        $rows = collect($this->stats)->map(fn ($v, $k) => [str_replace('_', ' ', $k), $v])->values()->all();
        $this->table(['What', 'Count'], $rows);

        $this->newLine();
        $this->line('<comment>Login credentials (all users, same password):</comment>');
        $rows = collect($this->credentials)
            ->map(fn ($u) => [self::COMPANY_NAME, $u['role'], $u['email'], $u['password']])
            ->all();
        $this->table(['Company', 'Role', 'Email', 'Password'], $rows);

        $this->newLine();
        $this->comment('Undo this with:  php artisan demo:seed-pizza-restaurant --clear');
    }
}
