<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Company;
use App\Models\EquipmentPurchase;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\Vendor;
use App\Services\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  Monthly depreciation of equipment and vehicles.
//
//  The command that posts it was written and correct, but was never
//  scheduled — routes/console.php held only the stock `inspire`
//  example. So depreciation only ever ran if somebody typed it by
//  hand, which nobody does: assets stayed on the books at their
//  purchase price forever, overstating both assets and profit.
//
//  It's scheduled daily rather than monthly on purpose: the command
//  works out every whole month elapsed since each asset's last
//  posting, so a missed day is caught up rather than lost.
// ══════════════════════════════════════════════════════════════════
class DepreciationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->user    = User::factory()->companyAdmin($this->company)->create();

        app(JournalService::class)->seedChartOfAccounts($this->company);
    }

    private function asset(float $amount, int $monthsAgo, int $usefulLifeYears = 5): EquipmentPurchase
    {
        $vendor   = Vendor::create(['name' => 'Supplier', 'company_id' => $this->company->id]);
        $category = Category::create([
            'name' => 'Vehicles', 'kind' => 'expense', 'company_id' => $this->company->id,
        ]);

        return EquipmentPurchase::create([
            'company_id'        => $this->company->id,
            'vendor_id'         => $vendor->id,
            'category_id'       => $category->id,
            'name'              => 'Delivery van',
            'qty'               => 1,
            'unit_price'        => $amount,
            'amount'            => $amount,
            'date'              => now()->subMonths($monthsAgo)->toDateString(),
            'useful_life_years' => $usefulLifeYears,
        ]);
    }

    /**
     * FIXED (QA audit, Sep 2026): this test originally asserted
     * depreciation:run was on the cron schedule. It deliberately
     * isn't — see the comment in routes/console.php — depreciation
     * is now caught up by PostDueDepreciation, a middleware that
     * runs on every web request instead of relying on a server
     * crontab nobody may have configured. This test was never
     * updated to match, so it failed against correct, intended
     * behavior.
     *
     * Replaced with a behavioral test: rather than inspecting how
     * the middleware is wired up (an implementation detail that can
     * change), this simply visits a real page as the pattern
     * PostDueDepreciation itself documents — "the first page it
     * opens each day" — and confirms depreciation actually posted
     * as a side effect, the same way a real customer would trigger
     * it just by using the app.
     */
    public function test_visiting_a_page_catches_up_depreciation_without_a_crontab(): void
    {
        $asset = $this->asset(12000, 3);

        $this->actingAs($this->user)->get(route('app.dashboard'));

        $asset->refresh();

        $this->assertEqualsWithDelta(600.0, (float) $asset->accumulated_depreciation, 0.01);
        $this->assertSame(3, JournalEntry::where('source_type', EquipmentPurchase::class)->count());
    }

    public function test_it_catches_up_every_elapsed_month(): void
    {
        // 12,000 over 5 years is 200 a month. Six months have passed.
        $asset = $this->asset(12000, 6);

        $this->assertEqualsWithDelta(200.0, $asset->monthlyDepreciationAmount(), 0.001);

        Artisan::call('depreciation:run');

        $asset->refresh();

        $this->assertEqualsWithDelta(1200.0, (float) $asset->accumulated_depreciation, 0.01);
        $this->assertSame(6, JournalEntry::where('source_type', EquipmentPurchase::class)->count());
    }

    public function test_running_it_again_posts_nothing_new(): void
    {
        $this->asset(12000, 6);

        Artisan::call('depreciation:run');
        $afterFirst = JournalEntry::count();

        Artisan::call('depreciation:run');

        $this->assertSame(
            $afterFirst,
            JournalEntry::count(),
            'A second run in the same month double-posted — the command is not idempotent.'
        );
    }

    public function test_it_posts_to_the_expense_and_contra_asset_accounts(): void
    {
        $this->asset(12000, 1);

        Artisan::call('depreciation:run');

        $lines = JournalEntry::with('lines.account')
            ->where('source_type', EquipmentPurchase::class)
            ->get()
            ->flatMap->lines;

        $debited  = $lines->firstWhere(fn ($line) => (float) $line->debit > 0);
        $credited = $lines->firstWhere(fn ($line) => (float) $line->credit > 0);

        $this->assertSame(Account::DEPRECIATION_EXPENSE, $debited->account->code);
        $this->assertSame(Account::ACCUMULATED_DEPRECIATION, $credited->account->code);

        // The original cost stays visible on the asset account —
        // depreciation goes to the contra account, not against it.
        $this->assertEqualsWithDelta(12000.0, (float) EquipmentPurchase::sole()->amount, 0.001);
    }

    public function test_an_asset_never_depreciates_past_its_own_cost(): void
    {
        // 1,200 over 1 year is 100 a month; 24 months have passed, so
        // it should stop at 1,200 rather than reach 2,400.
        $asset = $this->asset(1200, 24, usefulLifeYears: 1);

        Artisan::call('depreciation:run');

        $asset->refresh();

        $this->assertEqualsWithDelta(1200.0, (float) $asset->accumulated_depreciation, 0.01);
        $this->assertLessThanOrEqual(
            (float) $asset->amount,
            (float) $asset->accumulated_depreciation,
            'The asset depreciated below zero book value.'
        );
    }

    /**
     * QA audit (Sep 2026), Finding 4: this command runs
     * unauthenticated, so the BelongsToCompany global scope has
     * nothing to key off — DepreciationService::catchUpAllCompanies()
     * has to loop companies and pass each one's id through by hand
     * (see its own withoutGlobalScope() call). That's correct today;
     * this test is the regression guard so a future edit can't
     * silently post company A's depreciation against company B's
     * chart of accounts (or vice versa) without a test failing.
     */
    public function test_two_companies_depreciation_never_crosses_over(): void
    {
        $mine = $this->asset(12000, 3); // 12,000 / 5yr = 200/mo × 3 = 600

        $other      = Company::factory()->create();
        $otherAdmin = User::factory()->companyAdmin($other)->create();
        app(JournalService::class)->seedChartOfAccounts($other);

        $otherVendor   = Vendor::create(['name' => 'Their Supplier', 'company_id' => $other->id]);
        $otherCategory = Category::create(['name' => 'Vehicles', 'kind' => 'expense', 'company_id' => $other->id]);
        $theirs = EquipmentPurchase::create([
            'company_id'        => $other->id,
            'vendor_id'         => $otherVendor->id,
            'category_id'       => $otherCategory->id,
            'name'              => 'Their van',
            'qty'               => 1,
            'unit_price'        => 60000, // deliberately a very different amount
            'amount'            => 60000,
            'date'              => now()->subMonths(3)->toDateString(),
            'useful_life_years' => 5,
        ]);

        auth()->logout();
        Artisan::call('depreciation:run');

        $mine->refresh();
        $theirs->refresh();

        $this->assertEqualsWithDelta(600.0, (float) $mine->accumulated_depreciation, 0.01);
        $this->assertEqualsWithDelta(3000.0, (float) $theirs->accumulated_depreciation, 0.01);

        // Every journal entry this run produced must belong to the
        // same company as the asset it was posted for — never
        // swapped, and never all attributed to one company.
        $entries = JournalEntry::where('source_type', EquipmentPurchase::class)->get();

        $this->assertSame(6, $entries->count(), '3 months × 2 companies.');
        $this->assertSame(3, $entries->where('source_id', $mine->id)->count());
        $this->assertSame(3, $entries->where('source_id', $theirs->id)->count());

        foreach ($entries as $entry) {
            $expectedCompanyId = $entry->source_id === $mine->id ? $this->company->id : $other->id;
            $this->assertSame(
                $expectedCompanyId,
                $entry->company_id,
                "A depreciation entry for asset #{$entry->source_id} was posted under the wrong company."
            );
        }
    }

    public function test_a_brand_new_asset_has_nothing_to_post_yet(): void
    {
        $this->asset(12000, 0);

        Artisan::call('depreciation:run');

        $this->assertSame(0, JournalEntry::where('source_type', EquipmentPurchase::class)->count());
    }

    public function test_it_runs_unauthenticated_the_way_the_scheduler_does(): void
    {
        $this->asset(12000, 3);

        auth()->logout();
        $this->assertGuest();

        Artisan::call('depreciation:run');

        // The tenant scope keys off the logged-in user, so a console
        // run must still reach every company's assets.
        $this->assertSame(3, JournalEntry::where('source_type', EquipmentPurchase::class)->count());
    }
}
