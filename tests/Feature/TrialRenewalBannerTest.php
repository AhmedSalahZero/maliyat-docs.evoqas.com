<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  The trial countdown has to say who to talk to.
//
//  The banner already counted down the days. What it did not do was
//  name a way out — and there is no self-service billing page in
//  this app, renewal is arranged with a person. A banner that says
//  "your access ends in three days" and stops there tells the
//  customer they have a problem and nothing about solving it.
//
//  The contact comes from config/subscription.php, shared to every
//  page. Either channel may be unset, and with neither set the
//  banner is a plain warning exactly as it was before — a half
//  configured install must not render a button that goes nowhere.
// ══════════════════════════════════════════════════════════════════
class TrialRenewalBannerTest extends TestCase
{
    use RefreshDatabase;

    private function memberOfCompanyEndingIn(int $days): User
    {
        $company = Company::factory()->create([
            'trial_ends_at' => now()->addDays($days),
        ]);

        return User::factory()->companyAdmin($company)->create();
    }

    private function propsFor(User $user): array
    {
        return $this->actingAs($user)
            ->get(route('app.dashboard'))
            ->viewData('page')['props'];
    }

    // ── The countdown itself ─────────────────────────────────────

    public function test_a_company_in_its_final_week_is_flagged(): void
    {
        $props = $this->propsFor($this->memberOfCompanyEndingIn(3));

        $this->assertTrue($props['auth']['user']['company']['trial_expiring']);
        $this->assertSame(3, $props['auth']['user']['company']['trial_days_left']);
    }

    public function test_a_company_with_months_left_is_not_flagged(): void
    {
        $props = $this->propsFor($this->memberOfCompanyEndingIn(45));

        $this->assertFalse($props['auth']['user']['company']['trial_expiring']);
    }

    public function test_a_company_that_has_paid_never_shows_a_countdown(): void
    {
        $company = Company::factory()->create(['trial_ends_at' => null]);
        $user    = User::factory()->companyAdmin($company)->create();

        $props = $this->propsFor($user);

        $this->assertFalse($props['auth']['user']['company']['trial_expiring']);
        $this->assertNull($props['auth']['user']['company']['trial_days_left']);
    }

    public function test_the_last_day_reads_zero_not_a_negative(): void
    {
        // Ending later today: the countdown must read 0, and the user
        // is still let in — a trial that has not yet lapsed is not the
        // same as one that has.
        $company = Company::factory()->create(['trial_ends_at' => now()->endOfDay()]);
        $user    = User::factory()->companyAdmin($company)->create();

        $props = $this->propsFor($user);

        $this->assertSame(0, $props['auth']['user']['company']['trial_days_left']);
        $this->assertTrue($props['auth']['user']['company']['trial_expiring']);
    }

    // ── The way out ──────────────────────────────────────────────

    public function test_the_contact_details_reach_the_page(): void
    {
        config([
            'subscription.support_email' => 'billing@example.test',
            'subscription.support_phone' => '201234567890',
        ]);

        $props = $this->propsFor($this->memberOfCompanyEndingIn(3));

        $this->assertSame('billing@example.test', $props['support']['email']);
        $this->assertSame('201234567890', $props['support']['phone']);
    }

    /**
     * A half-configured install must not render a button that goes
     * nowhere — the banner falls back to a plain warning.
     */
    public function test_an_unconfigured_install_shares_nothing_rather_than_a_broken_link(): void
    {
        config([
            'subscription.support_email' => null,
            'subscription.support_phone' => null,
        ]);

        $props = $this->propsFor($this->memberOfCompanyEndingIn(3));

        $this->assertNull($props['support']['email']);
        $this->assertNull($props['support']['phone']);
    }

    public function test_one_channel_alone_is_enough(): void
    {
        config([
            'subscription.support_email' => 'billing@example.test',
            'subscription.support_phone' => null,
        ]);

        $props = $this->propsFor($this->memberOfCompanyEndingIn(2));

        $this->assertSame('billing@example.test', $props['support']['email']);
        $this->assertNull($props['support']['phone']);
    }

    /**
     * The contact is shared everywhere, not just on the dashboard —
     * the banner lives in the layout and the countdown follows the
     * user around the app.
     */
    public function test_the_contact_is_shared_on_every_page(): void
    {
        config(['subscription.support_email' => 'billing@example.test']);

        $user = $this->memberOfCompanyEndingIn(3);

        foreach (['app.dashboard', 'app.sales.index', 'app.reports.index'] as $routeName) {
            $props = $this->actingAs($user)->get(route($routeName))->viewData('page')['props'];

            $this->assertSame('billing@example.test', $props['support']['email'], "missing on {$routeName}");
        }
    }

    /**
     * Once the trial has actually lapsed the user cannot reach the
     * layout at all, so there is no "expired" banner to render — the
     * countdown is only ever shown while there is still time to act.
     */
    public function test_a_lapsed_company_is_locked_out_rather_than_shown_a_banner(): void
    {
        $company = Company::factory()->create(['trial_ends_at' => now()->subDay()]);
        $user    = User::factory()->companyAdmin($company)->create();

        $this->actingAs($user)
            ->get(route('app.dashboard'))
            ->assertRedirect(route('login'));
    }
}
