<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\EquipmentPurchase;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\Vendor;
use App\Services\DepreciationService;
use App\Services\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  Depreciation without cron.
//
//  Depreciation used to run only from the server's scheduler. If the
//  crontab line calling `schedule:run` was missing there was no error
//  and no warning — depreciation simply never posted, and it showed
//  up months later as overstated assets and overstated profit.
//
//  PostDueDepreciation moves the trigger into the app: the first page
//  a company opens on a new day catches it up. These tests pin down
//  the three things that has to get right —
//
//    • it actually posts, from an ordinary page view;
//    • it does so at most once a day, not on every request;
//    • two simultaneous requests cannot post the same month twice.
//
//  That last one is the reason the lock exists. Duplicate entries in
//  a ledger are not a performance problem, they are wrong books.
// ══════════════════════════════════════════════════════════════════
class DepreciationOnRequestTest extends TestCase
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

    private function asset(float $amount, int $monthsAgo, ?Company $company = null): EquipmentPurchase
    {
        $company  = $company ?? $this->company;
        $vendor   = Vendor::create(['name' => 'Supplier', 'company_id' => $company->id]);
        $category = Category::create([
            'name' => 'Vehicles', 'kind' => 'expense', 'company_id' => $company->id,
        ]);

        return EquipmentPurchase::create([
            'company_id'        => $company->id,
            'vendor_id'         => $vendor->id,
            'category_id'       => $category->id,
            'name'              => 'Delivery van',
            'qty'               => 1,
            'unit_price'        => $amount,
            'amount'            => $amount,
            'date'              => now()->subMonths($monthsAgo)->toDateString(),
            'useful_life_years' => 5,
        ]);
    }

    private function depreciationEntries(): int
    {
        return JournalEntry::withoutGlobalScope('company')
            ->where('source_type', EquipmentPurchase::class)
            ->count();
    }

    // ── It posts, from an ordinary page view ─────────────────────

    public function test_opening_a_page_posts_the_depreciation_that_is_due(): void
    {
        $this->asset(60000, 3);

        $this->assertSame(0, $this->depreciationEntries());

        $this->actingAs($this->user)->get(route('app.dashboard'))->assertOk();

        $this->assertSame(
            3,
            $this->depreciationEntries(),
            'Three whole months had closed, so three entries should have been posted.'
        );
    }

    public function test_a_company_that_went_quiet_is_caught_up_the_moment_someone_returns(): void
    {
        // Bought a year ago, nobody logged in since. Under cron this
        // is the case that silently rots.
        $asset = $this->asset(120000, 12);

        $this->actingAs($this->user)->get(route('app.dashboard'))->assertOk();

        $this->assertSame(12, $this->depreciationEntries());

        // 120000 over 5 years = 2000/month, 12 months = 24000.
        $this->assertEqualsWithDelta(
            24000.0,
            (float) $asset->fresh()->accumulated_depreciation,
            0.01
        );
    }

    // ── At most once a day ───────────────────────────────────────

    public function test_the_rest_of_the_days_requests_post_nothing_further(): void
    {
        $this->asset(60000, 3);

        $this->actingAs($this->user)->get(route('app.dashboard'))->assertOk();
        $this->assertSame(3, $this->depreciationEntries());

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->user)->get(route('app.dashboard'))->assertOk();
        }

        $this->assertSame(
            3,
            $this->depreciationEntries(),
            'The day was already checked; further page views must post nothing.'
        );
    }

    public function test_the_first_visit_stamps_the_day(): void
    {
        $this->asset(60000, 3);

        $this->assertNull($this->company->fresh()->depreciation_checked_on);

        $this->actingAs($this->user)->get(route('app.dashboard'))->assertOk();

        $this->assertTrue(
            $this->company->fresh()->depreciation_checked_on->isSameDay(Carbon::today())
        );
    }

    public function test_the_next_day_it_runs_again(): void
    {
        $this->asset(60000, 3);

        $this->actingAs($this->user)->get(route('app.dashboard'))->assertOk();
        $this->assertSame(3, $this->depreciationEntries());

        // Roll forward past a month boundary so there is genuinely
        // something new to post, and let the day guard expire.
        $this->travelTo(now()->addMonthNoOverflow()->endOfMonth()->addDay());

        $this->actingAs($this->user)->get(route('app.dashboard'))->assertOk();

        $this->assertGreaterThan(
            3,
            $this->depreciationEntries(),
            'A new month had closed, so the next day s first visit should post it.'
        );
    }

    // ── Concurrency: the reason the lock exists ──────────────────

    public function test_two_simultaneous_runs_cannot_post_the_same_month_twice(): void
    {
        $asset = $this->asset(60000, 3);

        $service = app(DepreciationService::class);
        $company = $this->company->fresh();

        // Hold the lock the way a request already mid-run would.
        $held = \Illuminate\Support\Facades\Cache::lock("depreciation:company:{$company->id}", 120);
        $this->assertTrue($held->get(), 'Could not take the lock to set the test up.');

        try {
            $result = $service->catchUpCompany($company);

            $this->assertSame(-1, $result, 'A blocked run should report that it did nothing.');
            $this->assertSame(0, $this->depreciationEntries(), 'It must not have posted behind the lock.');
        } finally {
            $held->release();
        }

        // Once the other run finishes, the next caller works normally.
        $this->assertSame(3, $service->catchUpCompany($company->fresh()));
        $this->assertSame(3, $this->depreciationEntries());
        $this->assertEqualsWithDelta(3000.0, (float) $asset->fresh()->accumulated_depreciation, 0.01);
    }

    public function test_the_command_and_a_page_view_together_post_each_month_once(): void
    {
        $this->asset(60000, 4);

        // The command sweeps everything, then a user opens a page.
        \Illuminate\Support\Facades\Artisan::call('depreciation:run');
        $afterCommand = $this->depreciationEntries();

        $this->actingAs($this->user)->get(route('app.dashboard'))->assertOk();

        $this->assertSame(
            $afterCommand,
            $this->depreciationEntries(),
            'The two paths share one lock and one watermark; neither may double-post.'
        );
        $this->assertSame(4, $afterCommand);
    }

    // ── Who it does and does not run for ─────────────────────────

    public function test_it_never_touches_another_companys_assets(): void
    {
        $other = Company::factory()->create();
        app(JournalService::class)->seedChartOfAccounts($other);

        $this->asset(60000, 3);
        $theirs = $this->asset(60000, 3, $other);

        $this->actingAs($this->user)->get(route('app.dashboard'))->assertOk();

        $this->assertSame(
            0.0,
            (float) $theirs->fresh()->accumulated_depreciation,
            'Only the viewer s own company should have been caught up.'
        );
        $this->assertNull($other->fresh()->depreciation_checked_on);
    }

    public function test_a_guest_triggers_nothing(): void
    {
        $this->asset(60000, 3);

        $this->get(route('login'))->assertOk();

        $this->assertSame(0, $this->depreciationEntries());
        $this->assertNull($this->company->fresh()->depreciation_checked_on);
    }

    public function test_a_super_admin_with_no_company_is_handled(): void
    {
        $this->asset(60000, 3);

        $admin = User::factory()->superAdmin()->create();

        // The point is that this does not blow up on a null company.
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();

        $this->assertSame(0, $this->depreciationEntries());
    }

    // ── It must never break the page ─────────────────────────────

    public function test_a_failure_is_logged_and_the_page_still_renders(): void
    {
        $this->asset(60000, 3);

        $this->mock(DepreciationService::class, function ($mock) {
            $mock->shouldReceive('catchUpCompany')
                ->andThrow(new \RuntimeException('ledger unavailable'));
        });

        \Illuminate\Support\Facades\Log::shouldReceive('error')
            ->once()
            ->withArgs(fn ($message) => str_contains($message, 'Depreciation catch-up failed'));

        $this->actingAs($this->user)->get(route('app.dashboard'))->assertOk();

        // And it claimed the day, so it retries tomorrow rather than
        // on every single page load for the rest of today.
        $this->assertTrue(
            $this->company->fresh()->depreciation_checked_on->isSameDay(Carbon::today())
        );
    }
}
