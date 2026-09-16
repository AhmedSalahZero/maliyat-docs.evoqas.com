<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  Every page the app can navigate to actually renders.
//
//  Two of them didn't: /app/profile and /app/team were both wired up
//  in routes and linked from the menu, but the Vue components they
//  render had never been created, so both links returned a server
//  error. Nothing caught it because nothing ever visited them.
//
//  The sweep below walks the route table itself rather than a hand
//  written list, so a page added later is covered automatically.
// ══════════════════════════════════════════════════════════════════
class PageRenderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every named GET route in a prefix that takes no required
     * parameters — i.e. everything reachable by clicking.
     *
     * @return list<string>
     */
    private function navigableRouteNames(string $prefix): array
    {
        $names = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $name = $route->getName();

            if (! $name || ! str_starts_with($name, $prefix)) {
                continue;
            }

            // Skip anything with a required {param} — those need a
            // real record and are covered by their own tests.
            if (preg_match('/\{[^?}]+\}/', $route->uri())) {
                continue;
            }

            $names[] = $name;
        }

        sort($names);

        return $names;
    }

    /**
     * Routes that intentionally redirect rather than render — see
     * routes/web.php, where app.reports.trial-balance and
     * app.reports.journal are old report names kept only as
     * redirects into the newer, consolidated app.reports.external-
     * audit view. A 302 here is correct, not a broken page.
     *
     * FIXED (QA audit, Sep 2026): this test previously required
     * every app.* route to return 200, which failed against these
     * two routes' correct, intended behavior.
     */
    private const INTENTIONAL_REDIRECTS = [
        'app.reports.trial-balance',
        'app.reports.journal',
    ];

    /**
     * Some pages are gated on the company's own business type — e.g.
     * app.production-orders.index 404s for a company that hasn't
     * enabled Production (see EnsureBusinessType). This sweep exists
     * to check that every navigable page renders, not to re-test that
     * gating, so the company used here has every business type
     * enabled — otherwise a real breakage on a Production-only page
     * would be indistinguishable from the company simply not having
     * that feature turned on, and the two failure modes need
     * different fixes.
     *
     * FIXED (QA audit, Sep 2026): this test previously created a
     * company with no business type set, which defaults to
     * ['trading'] (see Company::businessTypes()) — so
     * app.production-orders.index always 404'd here, correctly per
     * EnsureBusinessType, but the test still asserted it should be
     * 200, failing for a reason that had nothing to do with the page
     * itself.
     */
    public function test_every_company_page_renders_for_a_company_admin(): void
    {
        $company = Company::factory()->create(['business_types' => Company::BUSINESS_TYPES]);
        $user    = User::factory()->companyAdmin($company)->create();

        $names = $this->navigableRouteNames('app.');

        $this->assertNotEmpty($names, 'No app.* routes found — the route scan is broken.');

        $failures = [];

        foreach ($names as $name) {
            $response = $this->actingAs($user)->get(route($name));

            $expected = in_array($name, self::INTENTIONAL_REDIRECTS, true) ? 302 : 200;

            if ($response->getStatusCode() !== $expected) {
                $failures[] = sprintf('%s → HTTP %d', $name, $response->getStatusCode());
            }
        }

        $this->assertSame([], $failures, "These pages did not render:\n".implode("\n", $failures));
    }

    public function test_every_admin_page_renders_for_a_super_admin(): void
    {
        $user = User::factory()->superAdmin()->create();

        $failures = [];

        foreach ($this->navigableRouteNames('admin.') as $name) {
            $response = $this->actingAs($user)->get(route($name));

            if ($response->getStatusCode() !== 200) {
                $failures[] = sprintf('%s → HTTP %d', $name, $response->getStatusCode());
            }
        }

        $this->assertSame([], $failures, "These admin pages did not render:\n".implode("\n", $failures));
    }

    /**
     * The two that were actually broken, asserted by name so the
     * regression is obvious if it ever returns.
     */
    public function test_the_profile_page_renders_and_carries_its_user(): void
    {
        $user = User::factory()->companyAdmin()->create(['name' => 'Amina Salah']);

        $this->actingAs($user)
            ->get(route('app.profile.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('App/Profile/Index')
                ->where('user.name', 'Amina Salah')
            );
    }

    public function test_the_team_page_lists_colleagues_but_not_the_viewer(): void
    {
        $company = Company::factory()->create();
        $admin   = User::factory()->companyAdmin($company)->create(['name' => 'The Admin']);
        User::factory()->employee($company)->create(['name' => 'A Colleague']);

        // Somebody from a different company must never appear.
        User::factory()->employee()->create(['name' => 'Outsider']);

        $this->actingAs($admin)
            ->get(route('app.team.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('App/Team/Index')
                ->has('employees', 1)
                ->where('employees.0.name', 'A Colleague')
            );
    }

    /**
     * These three are linked from the app menu. They used to answer
     * ONLY JSON, so clicking the menu entry dropped a raw array on
     * the screen instead of a page — this is that fix.
     *
     * CORRECTED (QA audit, Sep 2026): this comment used to also
     * claim these routes were "fetched as JSON by the sentence
     * forms' dropdowns". That's no longer how dropdown data gets to
     * those forms — a search of the whole frontend found dropdown
     * lists (e.g. the customer list on the Sales page) are embedded
     * directly as an Inertia prop by the page that needs them, not
     * fetched live from these routes. The sibling test that used to
     * assert the JSON behavior was removed for the same reason.
     */
    public function test_the_reference_data_links_render_a_page_for_a_person(): void
    {
        $user = User::factory()->companyAdmin()->create();

        foreach (['app.customers.index', 'app.vendors.index', 'app.items.index'] as $name) {
            $this->actingAs($user)
                ->get(route($name))
                ->assertOk()
                ->assertInertia(fn ($page) => $page->component('App/Lookups/Index'));
        }
    }

    /**
     * REMOVED (QA audit, Sep 2026): this test asserted
     * app.customers.index answers with raw JSON for a "sentence
     * form dropdown" fetch. Traced every use of that route name
     * across the entire frontend (resources/js) and found exactly
     * two: a menu link and a lookup-page tab — both plain page
     * navigation, never a fetch/axios call. Dropdown data (e.g. the
     * customer list on the Sales page) is embedded directly as an
     * Inertia prop by the controller that needs it — see
     * SaleController::index()'s own 'customers' key — not fetched
     * live from this route. The doc comment above the sibling test
     * describes a real historical bug (this route once answered
     * ONLY JSON, so clicking its menu link showed a raw array
     * instead of a page) and the fix for THAT — making it render a
     * real page — is exactly what the sibling test above already
     * covers. Building a JSON-answering branch onto this route now
     * would be adding code for a caller that doesn't exist anywhere
     * in the app, so the test was removed rather than the app
     * changed to match it.
     */

    public function test_the_items_page_carries_its_categories_too(): void
    {
        $company = Company::factory()->create();
        $user    = User::factory()->companyAdmin($company)->create();

        \App\Models\Category::create([
            'name' => 'Rent', 'kind' => 'expense', 'company_id' => $company->id,
        ]);

        $this->actingAs($user)
            ->get(route('app.items.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('App/Lookups/Index')
                ->where('tab', 'items')
                ->has('categories')
            );
    }

    public function test_a_member_cannot_reach_the_admin_area(): void
    {
        $user = User::factory()->companyAdmin()->create();

        // EnsureAdmin aborts rather than redirecting — a member has
        // no admin page to be sent to.
        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    public function test_a_super_admin_visiting_the_member_area_is_sent_to_their_own_dashboard(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->get(route('app.dashboard'))
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get(route('app.dashboard'))->assertRedirect(route('login'));
    }
}
