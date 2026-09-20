<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use App\Services\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  The cross-tenant report from production, pinned.
//
//  A customer reported signing in as one company's admin and landing
//  in another company's books, while employee accounts behaved
//  normally. Two separate defects were found; neither was the
//  seeder, and neither was the tenant scope's happy path — that was
//  verified correct and is re-asserted here so it stays that way.
//
//    1. The service worker cached every navigation into one
//       'inertia-pages' cache for 24 hours. Each page carries its
//       whole Inertia payload inline, and the Cache Storage API keys
//       on the URL alone, so /app/dashboard was a single entry
//       shared by every account used on that device — replayed to
//       whoever signed in next whenever the network hiccuped.
//       Covered here by asserting the build config and the
//       sign-out purge; the browser half cannot be exercised in PHP.
//
//    2. An ORPHANED account — a company_admin or employee whose
//       company row is gone, which users.company_id's nullOnDelete
//       produces — could sign in, and BelongsToCompany's scope was
//       switched OFF rather than narrowed for a null company_id, so
//       it read every company on the platform.
// ══════════════════════════════════════════════════════════════════
class TenantIsolationAndCachingTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Str0ng!Pass#2026';

    /** @return array{0: Company, 1: User} */
    private function companyWithAdmin(string $name, string $email): array
    {
        $company = Company::factory()->create(['name' => $name]);

        app(JournalService::class)->seedChartOfAccounts($company);
        Category::seedDefaults($company->id);

        $admin = User::factory()->create([
            'email'             => $email,
            'password'          => self::PASSWORD,
            'role'              => UserRole::CompanyAdmin->value,
            'company_id'        => $company->id,
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);

        Customer::create(['company_id' => $company->id, 'name' => "CUSTOMER-OF-{$name}"]);

        return [$company, $admin];
    }

    // ══════════════════════════════════════════════════════════
    //  The happy path — verified correct, kept that way
    // ══════════════════════════════════════════════════════════

    public function test_each_company_admin_signs_in_to_their_own_company(): void
    {
        [$industry, $industryAdmin] = $this->companyWithAdmin('Industry', 'admin@industry.test');
        [$services, $servicesAdmin] = $this->companyWithAdmin('Services', 'admin@services.test');

        foreach ([[$industryAdmin, $industry], [$servicesAdmin, $services]] as [$admin, $expected]) {
            Auth::logout();
            session()->flush();

            $this->post('/login', ['email' => $admin->email, 'password' => self::PASSWORD])
                ->assertSessionHasNoErrors();

            $this->assertSame($expected->id, Auth::user()->company_id);

            $this->assertSame(
                ["CUSTOMER-OF-{$expected->name}"],
                Customer::query()->pluck('name')->all(),
                'A signed-in admin must see their own company and nothing else'
            );
        }
    }

    // ══════════════════════════════════════════════════════════
    //  1 · The service worker must not cache signed-in pages
    // ══════════════════════════════════════════════════════════

    /**
     * Asserted against the build config rather than a browser,
     * because this is where the defect lived: one runtimeCaching
     * rule. If someone adds navigation caching back, this fails.
     */
    public function test_the_service_worker_does_not_cache_navigations(): void
    {
        // Comments are stripped first. The file explains at length
        // why navigation caching was removed, and that explanation
        // necessarily names the thing it is warning about — matching
        // on raw text would fail on the warning rather than on the
        // behaviour.
        $config = preg_replace(
            ['#/\*.*?\*/#s', '#^\s*//.*$#m'],
            '',
            file_get_contents(base_path('vite.config.js'))
        );

        $this->assertStringNotContainsString(
            "request.mode === 'navigate'",
            $config,
            'Navigation caching is back in vite.config.js. Every page carries its '
            .'whole Inertia payload inline, so caching one by URL hands one company '
            .'its neighbour\'s books.'
        );

        $this->assertStringNotContainsString(
            'inertia-pages',
            $config,
            'The inertia-pages cache is back — that is the cross-tenant leak.'
        );
    }

    /**
     * And the compiled worker that actually ships must be clean too:
     * vite.config.js is only the source, and public/sw.js is what a
     * browser runs. A stale committed worker would keep caching
     * pages no matter what the config says.
     */
    public function test_the_compiled_service_worker_does_not_cache_navigations(): void
    {
        $sw = public_path('sw.js');

        $this->assertFileExists($sw, 'public/sw.js is missing — run npm run build');

        $this->assertStringNotContainsString(
            'inertia-pages',
            file_get_contents($sw),
            'The SHIPPED service worker still caches navigations. Run npm run build and commit it.'
        );
    }

    /**
     * Removing the rule stops new poisoning but does nothing for a
     * device already holding a poisoned cache — a browser keeps what
     * it has until something deletes it. The sign-out purge is what
     * gets existing users out of the hole, so it has to stay wired
     * into BOTH logout buttons.
     */
    public function test_both_logout_paths_purge_browser_caches(): void
    {
        $this->assertFileExists(resource_path('js/composables/usePrivateCache.js'));

        foreach ([
            'js/Components/App/MenuSheet.vue',
            'js/Layouts/AdminLayout.vue',
        ] as $file) {
            $source = file_get_contents(resource_path($file));

            $this->assertStringContainsString('purgeSessionArtifacts', $source, "{$file} no longer purges on logout");
            $this->assertMatchesRegularExpression(
                '/await\s+purgeSessionArtifacts\(\)\s*;[\s\S]{0,200}?router\.post\(\s*route\(\s*[\'"]logout[\'"]/',
                $source,
                "{$file} must purge BEFORE posting to /logout — the response redirects and tears the page down"
            );
        }
    }

    /** A signed-in response must never be storable by anything in the path. */
    public function test_signed_in_responses_are_marked_no_store(): void
    {
        [, $admin] = $this->companyWithAdmin('Industry', 'admin@industry.test');

        $response = $this->actingAs($admin)->get('/app/dashboard');

        $response->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    /** The login page is the same for everybody and stays cacheable. */
    public function test_guest_responses_are_left_cacheable(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $this->assertStringNotContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    // ══════════════════════════════════════════════════════════
    //  2 · An orphaned account is not a tenant-wide key
    // ══════════════════════════════════════════════════════════

    /**
     * Deleting the company row is done in raw SQL on purpose: that is
     * exactly what nullOnDelete reacts to, and it is the shape every
     * unsupported delete path leaves behind (an older build, a manual
     * SQL delete, a restored backup).
     */
    private function orphan(User $user): User
    {
        DB::table('companies')->where('id', $user->company_id)->delete();

        $fresh = $user->fresh();

        // Guard the guard: if the schema ever stops nulling this, the
        // scenario under test no longer exists and the test would
        // pass for the wrong reason.
        $this->assertNull($fresh->company_id, 'nullOnDelete did not orphan the user — scenario invalid');

        return $fresh;
    }

    public function test_an_orphaned_admin_cannot_sign_in(): void
    {
        [, $servicesAdmin] = $this->companyWithAdmin('Services', 'admin@services.test');
        $this->companyWithAdmin('Industry', 'admin@industry.test');

        $orphan = $this->orphan($servicesAdmin);

        $this->assertSame('errors.account_orphaned', $orphan->accessDenialReason());

        $this->post('/login', ['email' => $orphan->email, 'password' => self::PASSWORD])
            ->assertSessionHasErrors('email');

        $this->assertGuest('web');
    }

    /**
     * The backstop, for every path that does not go through a fresh
     * login — a session already open when the company was deleted, a
     * queued job, an artisan command. "No company" has to mean no
     * rows; a filter that fails open is not a filter.
     */
    public function test_an_orphaned_session_reads_no_company_at_all(): void
    {
        [, $servicesAdmin] = $this->companyWithAdmin('Services', 'admin@services.test');
        $this->companyWithAdmin('Industry', 'admin@industry.test');

        $orphan = $this->orphan($servicesAdmin);

        $this->be($orphan);

        $this->assertSame([], Customer::query()->pluck('name')->all(), 'An orphan read another tenant');
        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(0, Customer::query()->count());
    }

    /** A super_admin has no company_id by design and must stay unscoped. */
    public function test_a_super_admin_is_still_unscoped(): void
    {
        $this->companyWithAdmin('Industry', 'admin@industry.test');
        $this->companyWithAdmin('Services', 'admin@services.test');

        $super = User::factory()->create([
            'email' => 'super@platform.test',
            'password' => self::PASSWORD,
            'role' => UserRole::SuperAdmin->value,
            'company_id' => null,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $this->be($super);

        $this->assertSame(2, Customer::query()->count(), 'A super_admin must still see across companies');
        $this->assertNull($super->accessDenialReason());
    }

    /** An employee of a live company is unaffected by any of the above. */
    public function test_an_employee_of_a_live_company_still_signs_in_normally(): void
    {
        [$services] = $this->companyWithAdmin('Services', 'admin@services.test');
        $this->companyWithAdmin('Industry', 'admin@industry.test');

        $employee = User::factory()->create([
            'email' => 'employee@services.test',
            'password' => self::PASSWORD,
            'role' => UserRole::Employee->value,
            'company_id' => $services->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $this->post('/login', ['email' => $employee->email, 'password' => self::PASSWORD])
            ->assertSessionHasNoErrors();

        $this->assertSame($services->id, Auth::user()->company_id);
        $this->assertSame(['CUSTOMER-OF-Services'], Customer::query()->pluck('name')->all());
    }

    // ══════════════════════════════════════════════════════════
    //  3 · The compiled assets have to actually ship
    // ══════════════════════════════════════════════════════════

    /**
     * The manifest named 85 assets of which 7 were in the repository,
     * so every page loaded with no JS and no CSS while the deploy
     * reported success. deploy.sh now refuses to finish in that
     * state; this asserts the repository itself is consistent.
     */
    public function test_every_asset_the_manifest_names_is_committed(): void
    {
        $manifestPath = public_path('build/manifest.json');
        $this->assertFileExists($manifestPath, 'The build manifest is missing — run npm run build');

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $this->assertIsArray($manifest);

        $tracked = [];
        exec('cd '.escapeshellarg(base_path()).' && git ls-files public/build', $tracked);
        $tracked = array_flip($tracked);

        $missingOnDisk = [];
        $untracked     = [];

        foreach ($manifest as $entry) {
            $files = array_merge([$entry['file'] ?? null], $entry['css'] ?? [], $entry['assets'] ?? []);

            foreach (array_filter($files) as $file) {
                if (! is_file(public_path("build/{$file}"))) {
                    $missingOnDisk[] = $file;
                }

                if (! isset($tracked["public/build/{$file}"])) {
                    $untracked[] = $file;
                }
            }
        }

        $this->assertSame([], $missingOnDisk, 'The manifest names assets that are not on disk');
        $this->assertSame([], $untracked, 'The manifest names assets that git would not ship — the frontend would load blank');
    }

    /**
     * Vite hashes every filename, so building over an old folder
     * leaves the previous build behind. Committed, those pile up
     * forever, which is how the repo ended up with orphaned assets
     * nothing referenced.
     */
    public function test_the_build_script_cleans_before_building(): void
    {
        $package = json_decode(file_get_contents(base_path('package.json')), true);

        $this->assertStringContainsString(
            'clean',
            $package['scripts']['build'] ?? '',
            'npm run build must clear the old build first'
        );
        $this->assertStringContainsString('public/build', $package['scripts']['clean'] ?? '');
    }
}
