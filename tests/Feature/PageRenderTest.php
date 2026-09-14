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

    public function test_every_company_page_renders_for_a_company_admin(): void
    {
        $user = User::factory()->companyAdmin()->create();

        $names = $this->navigableRouteNames('app.');

        $this->assertNotEmpty($names, 'No app.* routes found — the route scan is broken.');

        $failures = [];

        foreach ($names as $name) {
            $response = $this->actingAs($user)->get(route($name));

            if ($response->getStatusCode() !== 200) {
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
