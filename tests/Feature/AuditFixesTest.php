<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\EquipmentPurchase;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\ProductionOrder;
use App\Models\User;
use App\Services\DepreciationService;
use App\Services\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  The ten findings from the cross-functional audit, pinned.
//
//  Grouped by the finding they close so a failure names the thing it
//  broke rather than a symptom:
//
//    H-1  Arabic validation messages regressed to English
//    H-2  Depreciation could post the same month twice
//    M-1  The Production feature gate was frontend-only
//    M-2  Changing a password left other sessions alive
//    M-3  Two orphaned report components
//    M-4  A stale copy of bootstrap/app.php in the middleware folder
//    M-5  An InPractice leftover enum
//    M-7  Production orders could be deleted but not corrected
//    L-1  An icon button with no accessible name
//    L-2  Two form requests with an inconsistent authorize()
//
//  H-1 and M-3/M-4/M-5 are the ones most likely to come back: they
//  are deletions and a language file, and nothing about losing
//  either produces an error. H-1 has already regressed once.
// ══════════════════════════════════════════════════════════════════
class AuditFixesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('register');

        $this->company = Company::factory()->create();
        $this->admin   = User::factory()->companyAdmin($this->company)->create();

        app(JournalService::class)->seedChartOfAccounts($this->company);
        Category::seedDefaults($this->company->id);
    }

    // ══════════════════════════════════════════════════════════
    //  H-1 · Arabic validation
    // ══════════════════════════════════════════════════════════

    public function test_h1_arabic_validation_strings_exist(): void
    {
        $this->assertFileExists(
            lang_path('ar/validation.php'),
            'Every validation failure falls back to English without this'
        );
    }

    public function test_h1_an_arabic_user_is_refused_in_arabic(): void
    {
        app()->setLocale('ar');

        $this->post('/register', [
            'name' => 'أحمد صلاح', 'company_name' => 'إيفوكاس', 'email' => 'a@example.test',
            'currency' => 'EGP', 'language' => 'ar',
            'password' => 'abc1', 'password_confirmation' => 'abc1', '_hp' => '',
        ])->assertSessionHasErrors('password');

        $this->assertMatchesRegularExpression(
            '/\p{Arabic}/u',
            session('errors')->get('password')[0],
            'An Arabic customer is shown an English message on an Arabic form'
        );
    }

    /**
     * Without translated field names a message reads half-Arabic:
     * "حقل company_name مطلوب".
     */
    public function test_h1_field_names_are_translated_in_both_languages(): void
    {
        $ar = require lang_path('ar/validation.php');
        $en = require lang_path('en/validation.php');

        $this->assertNotEmpty($ar['attributes'] ?? []);
        $this->assertSame(
            array_keys($ar['attributes']),
            array_keys($en['attributes']),
            'The two field-name lists have drifted apart'
        );

        foreach (['name', 'company_name', 'email', 'password'] as $field) {
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $ar['attributes'][$field]);
        }
    }

    public function test_h1_an_english_user_sees_a_readable_field_name(): void
    {
        app()->setLocale('en');

        $this->post('/register', [
            'name' => 'Ahmed Salah', 'company_name' => '', 'email' => 'a@example.test',
            'currency' => 'EGP', 'language' => 'en',
            'password' => 'Password123!', 'password_confirmation' => 'Password123!', '_hp' => '',
        ])->assertSessionHasErrors('company_name');

        $this->assertStringNotContainsString(
            'company_name',
            session('errors')->get('company_name')[0],
            'The message names a database column rather than a field'
        );
    }

    // ══════════════════════════════════════════════════════════
    //  H-2 · Depreciation
    // ══════════════════════════════════════════════════════════

    private function asset(float $amount = 12000, int $years = 1): EquipmentPurchase
    {
        $this->actingAs($this->admin);

        return EquipmentPurchase::create([
            'company_id'        => $this->company->id,
            'vendor_id'         => \App\Models\Vendor::create(['company_id' => $this->company->id, 'name' => 'V'])->id,
            'category_id'       => Category::query()->where('company_id', $this->company->id)
                                        ->where('kind', 'equipment')->firstOrFail()->id,
            'name'              => 'Van',
            'date'              => '2026-01-31',
            'qty'               => 1,
            'unit_price'        => $amount,
            'amount'            => $amount,
            'useful_life_years' => $years,
        ]);
    }

    public function test_h2_the_post_and_the_watermark_commit_together(): void
    {
        $source = file_get_contents(app_path('Services/DepreciationService.php'));

        $this->assertMatchesRegularExpression(
            '/DB::transaction\(function \(\) use \(\$purchase, \$amount, \$postingDate\)/',
            $source,
            'The ledger entry and last_depreciated_through are still two separate writes — '
            .'a failure between them re-posts the same month on the next run'
        );
    }

    public function test_h2_running_the_catch_up_twice_posts_each_month_once(): void
    {
        $asset = $this->asset(12000, 1);
        $service = app(DepreciationService::class);

        $service->catchUpAsset($asset, Carbon::parse('2026-04-30'));
        $first = JournalEntry::query()->where('memo', 'Depreciation')->count();

        Cache::flush();
        $service->catchUpAsset($asset->fresh(), Carbon::parse('2026-04-30'));

        $this->assertSame(
            $first,
            JournalEntry::query()->where('memo', 'Depreciation')->count(),
            'The same months were posted a second time'
        );
    }

    public function test_h2_each_month_is_posted_on_its_own_period_end(): void
    {
        $asset = $this->asset(12000, 1);

        // Run on 1 May, so April has fully closed. A month is only
        // posted once it is over — running ON 30 April correctly
        // leaves April out.
        app(DepreciationService::class)->catchUpAsset($asset, Carbon::parse('2026-05-01'));

        $dates = JournalEntry::query()->where('memo', 'Depreciation')
            ->get()->map(fn ($e) => $e->date->toDateString())->sort()->values()->all();

        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'], $dates);
        $this->assertSame(count($dates), count(array_unique($dates)), 'A month was posted twice');
    }

    // ══════════════════════════════════════════════════════════
    //  M-1 · Production gate
    // ══════════════════════════════════════════════════════════

    private function memberOf(array $businessTypes): User
    {
        $company = Company::factory()->create(['business_types' => $businessTypes]);
        $user    = User::factory()->companyAdmin($company)->create();

        app(JournalService::class)->seedChartOfAccounts($company);
        Category::seedDefaults($company->id);

        return $user;
    }

    public function test_m1_a_company_without_production_cannot_open_the_screen(): void
    {
        $this->actingAs($this->memberOf(['trading']))
            ->get('/app/production-orders')
            ->assertNotFound();
    }

    public function test_m1_a_company_without_production_cannot_post_to_it(): void
    {
        $this->actingAs($this->memberOf(['trading']))
            ->post('/app/production-orders', [])
            ->assertNotFound();

        $this->assertSame(0, ProductionOrder::count());
    }

    public function test_m1_a_production_company_reaches_it_normally(): void
    {
        $this->actingAs($this->memberOf(['trading', 'production']))
            ->get('/app/production-orders')
            ->assertOk();
    }

    /**
     * 404 rather than 403: telling somebody a screen exists but is
     * not theirs is more than they need to know about a feature that
     * is not part of their account.
     */
    public function test_m1_the_gate_does_not_confirm_the_screen_exists(): void
    {
        $this->actingAs($this->memberOf(['service']))
            ->get('/app/production-orders')
            ->assertStatus(404);
    }

    // ══════════════════════════════════════════════════════════
    //  M-2 · Password change ends other sessions
    // ══════════════════════════════════════════════════════════

    public function test_m2_changing_a_password_invalidates_other_sessions(): void
    {
        $user = User::factory()->companyAdmin($this->company)->create([
            'password'          => 'Password123!',
            'email_verified_at' => now(),
        ]);

        $before = $user->password;

        // assertRedirect as well as assertSessionHasNoErrors: the
        // latter only inspects the validation bag, so it passes on a
        // 500. The first version of this fix called
        // logoutOtherDevices() before saving the new password, which
        // throws — and this test reported success until the status
        // was checked too.
        $this->actingAs($user)->patch('/app/profile/password', [
            'current_password'      => 'Password123!',
            'password'              => 'New-password456',
            'password_confirmation' => 'New-password456',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $after = $user->fresh()->password;

        $this->assertNotSame($before, $after);
        $this->assertTrue(Hash::check('New-password456', $after), 'The new password does not work');
        $this->assertFalse(Hash::check('Password123!', $after), 'The old password still works');
    }

    /**
     * logoutOtherDevices() only does anything because
     * AuthenticateSession is registered — that middleware is what
     * compares a session's marker against the user's current hash on
     * every request. Without it the call re-hashes a marker nothing
     * reads, and the fix is decorative.
     */
    public function test_m2_session_authentication_is_actually_enforced(): void
    {
        $bootstrap = file_get_contents(base_path('bootstrap/app.php'));
        $routes    = file_get_contents(base_path('routes/web.php'));

        $this->assertStringContainsString('AuthenticateSession', $bootstrap);
        $this->assertStringContainsString(
            "'auth.session'",
            $routes,
            'logoutOtherDevices() has no effect unless AuthenticateSession guards the route group'
        );
    }

    public function test_m2_it_calls_the_thing_that_actually_ends_them(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/App/ProfileController.php'));

        $this->assertStringContainsString(
            'Auth::logoutOtherDevices',
            $source,
            'Other sessions stay valid after a password change — the one action a '
            .'user takes when they think they are compromised'
        );
        $this->assertStringContainsString('session()->regenerate()', $source);
    }

    public function test_m2_the_wrong_current_password_is_refused(): void
    {
        $user = User::factory()->companyAdmin($this->company)->create([
            'password' => 'Password123!', 'email_verified_at' => now(),
        ]);

        $this->actingAs($user)->patch('/app/profile/password', [
            'current_password'      => 'not-it',
            'password'              => 'New-password456',
            'password_confirmation' => 'New-password456',
        ])->assertSessionHasErrors('current_password');
    }

    // ══════════════════════════════════════════════════════════
    //  M-3 / M-4 / M-5 · Dead files
    // ══════════════════════════════════════════════════════════

    /**
     * @return array<string, array{0: string}>
     */
    public static function deadFiles(): array
    {
        return [
            'M-3 orphaned Journal report'       => ['resources/js/Pages/App/Reports/Journal.vue'],
            'M-3 orphaned TrialBalance report'  => ['resources/js/Pages/App/Reports/TrialBalance.vue'],
            'M-4 stale bootstrap copy'          => ['app/Http/Middleware/app.php'],
            'M-5 InPractice leftover enum'      => ['app/Enums/CaseDifficulty.php'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('deadFiles')]
    public function test_m345_the_dead_file_stays_deleted(string $path): void
    {
        $this->assertFileDoesNotExist(
            base_path($path),
            "{$path} is back. It has no references, so nothing will fail — it will just "
            .'mislead whoever opens it next.'
        );
    }

    /**
     * The two report components were replaced by one screen. Their
     * old URLs still have to land somewhere real.
     */
    public function test_m3_the_old_report_urls_still_work(): void
    {
        $this->actingAs($this->admin);

        foreach (['/app/reports/trial-balance', '/app/reports/journal'] as $url) {
            $location = $this->get($url)->assertRedirect()->headers->get('Location');
            $this->get($location)->assertOk();
        }
    }

    // ══════════════════════════════════════════════════════════
    //  M-7 · Production orders can be corrected
    // ══════════════════════════════════════════════════════════

    private function productionCompany(): User
    {
        $user = $this->memberOf(['trading', 'production']);
        $this->actingAs($user);

        return $user;
    }

    private function makeRun(User $user, float $qty = 10, float $labor = 100): ProductionOrder
    {
        $company = $user->company;

        $flour = Item::create(['company_id' => $company->id, 'name' => 'Flour',
            'type' => 'raw_material', 'qty_per_uom' => 1, 'base_unit_name' => 'kg']);
        $bread = Item::create(['company_id' => $company->id, 'name' => 'Bread',
            'type' => 'product', 'qty_per_uom' => 1, 'base_unit_name' => 'loaf']);

        $this->post('/app/inventory-purchases', [
            'vendor_id' => \App\Models\Vendor::create(['company_id' => $company->id, 'name' => 'Mill'])->id,
            'date'      => '2026-03-01',
            'lines'     => [['item_id' => $flour->id, 'qty' => 100, 'qty_per_uom' => 1, 'unit_price' => 5]],
            'vat_rate'  => 0, 'mode' => 'later',
        ])->assertSessionHasNoErrors();
        Cache::flush();

        $this->post('/app/production-orders', [
            'item_id' => $bread->id, 'date' => '2026-03-05', 'qty_produced' => $qty,
            'materials' => [['item_id' => $flour->id, 'qty' => 20]],
            'labor_cost' => $labor,
        ])->assertSessionHasNoErrors();
        Cache::flush();

        return ProductionOrder::query()->latest('id')->firstOrFail();
    }

    public function test_m7_a_run_can_be_corrected(): void
    {
        $user = $this->productionCompany();
        $run  = $this->makeRun($user);

        $this->put("/app/production-orders/{$run->id}", [
            'item_id' => $run->item_id, 'date' => '2026-03-05', 'qty_produced' => 12,
            'materials' => [['item_id' => $run->materialLines->first()->item_id, 'qty' => 25]],
            'labor_cost' => 150,
        ])->assertSessionHasNoErrors();

        $run->refresh();

        $this->assertEquals(12.0, (float) $run->qty_produced);
        $this->assertEquals(150.0, (float) $run->labor_cost);
        $this->assertEquals(125.0, (float) $run->material_cost, '25kg of flour at 5');
        $this->assertEquals(275.0, (float) $run->total_cost);
        $this->assertSame(1, ProductionOrder::count(), 'Correcting must not create a second run');
    }

    /**
     * An employee could not fix their own typo, because the only way
     * to change a run was to delete it — and deleting is admin-only.
     */
    public function test_m7_an_employee_can_correct_a_run_but_not_delete_one(): void
    {
        $user     = $this->productionCompany();
        $run      = $this->makeRun($user);
        $employee = User::factory()->employee($user->company)->create();

        $this->actingAs($employee)->put("/app/production-orders/{$run->id}", [
            'item_id' => $run->item_id, 'date' => '2026-03-05', 'qty_produced' => 11,
            'materials' => [['item_id' => $run->materialLines->first()->item_id, 'qty' => 20]],
            'labor_cost' => 100,
        ])->assertSessionHasNoErrors();

        $this->assertEquals(11.0, (float) $run->fresh()->qty_produced);

        $this->actingAs($employee)
            ->delete("/app/production-orders/{$run->id}")
            ->assertForbidden();
    }

    public function test_m7_correcting_a_run_reverses_rather_than_edits_the_ledger(): void
    {
        $user = $this->productionCompany();
        $run  = $this->makeRun($user);

        $this->put("/app/production-orders/{$run->id}", [
            'item_id' => $run->item_id, 'date' => '2026-03-05', 'qty_produced' => 12,
            'materials' => [['item_id' => $run->materialLines->first()->item_id, 'qty' => 25]],
            'labor_cost' => 150,
        ])->assertSessionHasNoErrors();

        $this->assertGreaterThan(
            0,
            JournalEntry::query()->whereNotNull('reverses_id')->count(),
            'The original entry was edited instead of reversed'
        );

        JournalEntry::query()->get()->each(
            fn (JournalEntry $e) => $this->assertTrue($e->isBalanced(), "Entry #{$e->id} does not balance")
        );
    }

    /**
     * Re-saving a run unchanged must not be refused for consuming
     * material it had already consumed — the point of the
     * $excluding argument that had been unused since it was written.
     */
    public function test_m7_re_saving_a_run_unchanged_is_allowed(): void
    {
        $user = $this->productionCompany();
        $run  = $this->makeRun($user);
        $flourId = $run->materialLines->first()->item_id;

        // 100kg bought, 20 consumed. Asking for all 100 is only
        // possible if this run's own 20 are released first.
        $this->put("/app/production-orders/{$run->id}", [
            'item_id' => $run->item_id, 'date' => '2026-03-05', 'qty_produced' => 10,
            'materials' => [['item_id' => $flourId, 'qty' => 100]],
            'labor_cost' => 100,
        ])->assertSessionHasNoErrors();
    }

    public function test_m7_a_correction_still_cannot_overuse_stock(): void
    {
        $user = $this->productionCompany();
        $run  = $this->makeRun($user);

        $this->put("/app/production-orders/{$run->id}", [
            'item_id' => $run->item_id, 'date' => '2026-03-05', 'qty_produced' => 10,
            'materials' => [['item_id' => $run->materialLines->first()->item_id, 'qty' => 500]],
            'labor_cost' => 100,
        ])->assertSessionHasErrors('materials.0.qty');
    }

    public function test_m7_another_companys_run_is_not_reachable(): void
    {
        $user = $this->productionCompany();
        $run  = $this->makeRun($user);

        $this->actingAs($this->memberOf(['trading', 'production']))
            ->put("/app/production-orders/{$run->id}", [
                'item_id' => $run->item_id, 'date' => '2026-03-05', 'qty_produced' => 99,
                'materials' => [['item_id' => $run->materialLines->first()->item_id, 'qty' => 1]],
            ])->assertNotFound();
    }

    // ══════════════════════════════════════════════════════════
    //  L-1 / L-2 · Accessibility and consistency
    // ══════════════════════════════════════════════════════════

    /**
     * @return array<string, array{0: string}>
     */
    public static function screensWithIconControls(): array
    {
        return [
            'register'   => ['resources/js/Pages/Auth/Register.vue'],
            'login'      => ['resources/js/Pages/Auth/Login.vue'],
            'auth shell' => ['resources/js/Components/Auth/IpLoginShell.vue'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('screensWithIconControls')]
    public function test_l1_every_icon_control_has_an_accessible_name(string $page): void
    {
        $source = file_get_contents(base_path($page));

        preg_match_all('/<button\b[^>]*ctrl-btn[^>]*>/s', $source, $matches);

        $this->assertNotEmpty($matches[0], "No icon controls found in {$page}");

        foreach ($matches[0] as $button) {
            $this->assertStringContainsString(
                'aria-label',
                $button,
                "An icon control in {$page} is announced as an unnamed button"
            );
            $this->assertStringContainsString(
                'type="button"',
                $button,
                "An icon control in {$page} has no type, so it defaults to submit"
            );
        }
    }

    /**
     * Every sibling request scopes on company_id. Two did not — the
     * tenancy came from route-model binding either way, so this is
     * consistency rather than exposure, and consistency is what stops
     * the next one being written the loose way.
     */
    public function test_l2_every_app_form_request_authorizes_the_same_way(): void
    {
        $loose = [];

        foreach (glob(app_path('Http/Requests/App/*.php')) as $file) {
            $source = file_get_contents($file);

            if (preg_match('/function authorize\(\).*?\{(.*?)\n    \}/s', $source, $m)
                && str_contains($m[1], 'return (bool) $this->user();')) {
                $loose[] = basename($file);
            }
        }

        $this->assertSame([], $loose, 'These authorize() without a company check: '.implode(', ', $loose));
    }

    /**
     * Two writes to the same row, unwrapped, while every other
     * multi-write path in this app is inside a transaction. The
     * practical risk was always small — same row, so a partial edit
     * would have to fail between two statements touching it. What it
     * cost was the rule: an unwrapped path reads like a decision to
     * the next person, and the next multi-write they add copies it.
     */
    public function test_l4_updating_an_employee_is_one_transaction(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/App/UserController.php'));

        preg_match('/function update\(.*?\n    \}/s', $source, $m);

        $this->assertNotEmpty($m, 'UserController::update() not found');
        $this->assertStringContainsString(
            'DB::transaction',
            $m[0],
            'update() still writes the details and the password outside a transaction'
        );
    }

    /**
     * And the wrapping did not change what the screen does: a name,
     * an email and a password all applied in one edit.
     */
    public function test_l4_an_employee_edit_still_applies_every_field(): void
    {
        $company  = Company::factory()->create();
        $admin    = User::factory()->companyAdmin($company)->create();
        $employee = User::factory()->employee($company)->create([
            'name'  => 'Old Name',
            'email' => 'old@example.com',
        ]);

        $this->actingAs($admin)
            ->patch(route('app.team.update', $employee->id), [
                'name'                  => 'New Name',
                'email'                 => 'new@example.com',
                'password'              => 'Str0ng!Passw0rd',
                'password_confirmation' => 'Str0ng!Passw0rd',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $employee->refresh();

        $this->assertSame('New Name', $employee->name);
        $this->assertSame('new@example.com', $employee->email);
        $this->assertTrue(
            Hash::check('Str0ng!Passw0rd', $employee->password),
            'The password half of the edit did not land'
        );
    }

    // ─────────────────────────────────────────────────────────────
    //  C-1 · Working super-admin credentials committed to the repo
    // ─────────────────────────────────────────────────────────────

    private function seedSuperAdmin(): void
    {
        (new \Database\Seeders\SuperAdminSeeder)->run();
    }

    /**
     * The whole point of the finding. It was `updateOrCreate`, so
     * every re-seed wrote the password again — an owner who changed
     * it had it silently put back to the published one, and nothing
     * told them.
     */
    public function test_c1_reseeding_never_resets_an_existing_password(): void
    {
        config([
            'app.super_admin_email' => 'admin@example.test',
            'app.default_password'  => 'Seeded123!pass',
        ]);

        $this->seedSuperAdmin();

        $admin = User::where('email', 'admin@example.test')->firstOrFail();
        $admin->update(['password' => 'TheOwnersOwn1!']);

        // Same seeder, same configured password, run again.
        $this->seedSuperAdmin();

        $admin->refresh();

        $this->assertTrue(
            Hash::check('TheOwnersOwn1!', $admin->password),
            'Re-seeding overwrote the password the owner had set'
        );
        $this->assertFalse(
            Hash::check('Seeded123!pass', $admin->password),
            'Re-seeding put the configured password back'
        );
        $this->assertSame(
            1,
            User::where('email', 'admin@example.test')->count(),
            'Re-seeding duplicated the account instead of finding it'
        );
    }

    /**
     * It still has to actually work the first time.
     */
    public function test_c1_the_first_seed_creates_a_usable_super_admin(): void
    {
        config([
            'app.super_admin_email' => 'admin@example.test',
            'app.default_password'  => 'Seeded123!pass',
        ]);

        $this->seedSuperAdmin();

        $admin = User::where('email', 'admin@example.test')->firstOrFail();

        $this->assertTrue(Hash::check('Seeded123!pass', $admin->password));
        $this->assertSame(\App\Enums\UserRole::SuperAdmin->value, $admin->role);
        $this->assertNull($admin->company_id, 'A super_admin must not be scoped to a company');
        $this->assertTrue((bool) $admin->is_active);
        $this->assertNotNull($admin->email_verified_at);
    }

    /**
     * No DEFAULT_PASSWORD used to mean null straight into a hashed
     * cast — a super_admin row nobody could sign in as, created
     * without a word. It has to fail where someone will see it.
     */
    public function test_c1_seeding_without_a_configured_password_fails_loudly(): void
    {
        config([
            'app.super_admin_email' => 'admin@example.test',
            'app.default_password'  => null,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/DEFAULT_PASSWORD/');

        $this->seedSuperAdmin();
    }

    /**
     * And it may not hold a password this app would reject from an
     * ordinary employee.
     */
    public function test_c1_a_weak_configured_password_is_refused(): void
    {
        config([
            'app.super_admin_email' => 'admin@example.test',
            'app.default_password'  => 'abc',
        ]);

        $this->expectException(\RuntimeException::class);

        $this->seedSuperAdmin();
    }

    /**
     * Nothing is created by a refused run — no half-seeded account
     * left behind for someone to find later and wonder about.
     */
    public function test_c1_a_refused_run_leaves_no_account(): void
    {
        config([
            'app.super_admin_email' => 'admin@example.test',
            'app.default_password'  => '',
        ]);

        try {
            $this->seedSuperAdmin();
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertDatabaseMissing('users', ['email' => 'admin@example.test']);
    }

    /**
     * A regression guard on the literal itself, and on printing any
     * password to a console where deploy logs and CI output keep it.
     */
    public function test_c1_the_seeder_carries_no_credential_and_prints_none(): void
    {
        $source = file_get_contents(base_path('database/seeders/SuperAdminSeeder.php'));

        // Strip the header comment, which quotes the old literal
        // deliberately to explain what was wrong.
        $code = substr($source, strpos($source, 'class SuperAdminSeeder'));

        $this->assertStringNotContainsString('ChangeMe@2026!', $code);

        preg_match_all('/->(?:info|warn|line|error)\((.*?)\);/s', $code, $matches);

        foreach ($matches[1] as $printed) {
            $this->assertStringNotContainsString(
                '$password',
                $printed,
                'The seeder prints the password to the console'
            );
        }

        $this->assertStringNotContainsString(
            'User::updateOrCreate',
            $code,
            'updateOrCreate is back — it resets the password on every re-seed'
        );
    }

    /**
     * The template has to tell the next person the key exists and is
     * required, without shipping a value.
     */
    public function test_c1_the_env_template_requires_the_password_without_supplying_one(): void
    {
        $template = file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression(
            '/^DEFAULT_PASSWORD=\s*$/m',
            $template,
            '.env.example either omits DEFAULT_PASSWORD or ships a value for it'
        );
        $this->assertStringNotContainsString('ChangeMe@2026!', $template);
    }
}
