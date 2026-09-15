<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Notifications\TrialEndingNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  The free trial window.
//
//  Every company gets a fixed free period; when it lapses nobody in
//  that company can sign in, and a week before it lapses its admins
//  are emailed so they aren't surprised.
//
//  Two failure shapes are deliberately kept apart, because the
//  customer's next step differs: an administrative suspension
//  (is_active = false → contact support) and a lapsed trial
//  (→ renew). AccountAccessTest covers the first; this covers the
//  second and the boundary between them.
// ══════════════════════════════════════════════════════════════════
class TrialSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private function login(User $user): \Illuminate\Testing\TestResponse
    {
        return $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    }

    // ── Starting the window ──────────────────────────────────────

    public function test_a_new_company_starts_its_trial_automatically(): void
    {
        $company = Company::create(['name' => 'Fresh Co', 'currency' => 'SAR']);

        $this->assertNotNull($company->trial_ends_at, 'A company was created with unlimited free access.');
        $this->assertEqualsWithDelta(
            config('subscription.trial_months') * 30,
            $company->daysUntilExpiry(),
            3,
            'The trial should run for the configured number of months.'
        );
    }

    public function test_registering_starts_the_trial(): void
    {
        Notification::fake();

        $this->post('/register', [
            '_hp' => '', '_ft' => (now()->timestamp - 10) * 1000,
            'company_name' => 'Signed Up Co', 'currency' => 'SAR',
            'name' => 'Owner', 'email' => 'owner@example.test',
            'password' => 'secret-pass1', 'password_confirmation' => 'secret-pass1',
        ])->assertSessionHasNoErrors();

        $this->assertNotNull(Company::sole()->trial_ends_at);
    }

    public function test_a_company_created_with_no_expiry_stays_unlimited(): void
    {
        // This is how a company that has actually paid is recorded.
        $company = Company::create(['name' => 'Paid Co', 'trial_ends_at' => null]);

        $this->assertNull($company->trial_ends_at);
        $this->assertNull($company->daysUntilExpiry());
        $this->assertFalse($company->hasLapsed());
    }

    // ── Enforcing it ─────────────────────────────────────────────

    public function test_a_company_inside_its_trial_can_sign_in(): void
    {
        $company = Company::factory()->create(['trial_ends_at' => now()->addMonth()]);
        $user    = User::factory()->companyAdmin($company)->create();

        $this->login($user)->assertRedirect(route('app.dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_lapsed_trial_blocks_sign_in(): void
    {
        $company = Company::factory()->create(['trial_ends_at' => now()->subDay()]);
        $user    = User::factory()->companyAdmin($company)->create();

        $this->login($user)->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->assertSame('errors.subscription_expired', $user->accessDenialReason());
    }

    public function test_a_trial_lapsing_mid_session_locks_the_user_out_on_the_next_request(): void
    {
        $company = Company::factory()->create(['trial_ends_at' => now()->addDay()]);
        $user    = User::factory()->companyAdmin($company)->create();

        $this->login($user);
        $this->get(route('app.dashboard'))->assertOk();

        $company->forceFill(['trial_ends_at' => now()->subMinute()])->save();

        $this->get(route('app.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_an_administrative_suspension_is_reported_over_a_lapsed_trial(): void
    {
        // Both wrong at once: the customer must be told to contact
        // support, not to renew — renewing wouldn't let them back in.
        $company = Company::factory()->create([
            'is_active'     => false,
            'trial_ends_at' => now()->subDay(),
        ]);
        $user = User::factory()->companyAdmin($company)->create();

        $this->assertSame('errors.company_suspended', $user->accessDenialReason());
    }

    public function test_a_super_admin_is_never_locked_out_by_a_trial(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->assertNull($user->accessDenialReason());
    }

    public function test_the_expiry_message_is_real_text_in_both_locales(): void
    {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $this->assertNotSame('errors.subscription_expired', __('errors.subscription_expired'));
        }
    }

    // ── Warning ahead of time ────────────────────────────────────

    public function test_admins_are_emailed_inside_the_warning_window(): void
    {
        Notification::fake();

        $company = Company::factory()->create(['trial_ends_at' => now()->addDays(5)]);
        $admin   = User::factory()->companyAdmin($company)->create();

        Artisan::call('subscriptions:notify-expiring');

        Notification::assertSentTo($admin, TrialEndingNotification::class);
        $this->assertNotNull($company->fresh()->expiry_notified_at);
    }

    public function test_a_company_well_inside_its_trial_is_not_emailed(): void
    {
        Notification::fake();

        $company = Company::factory()->create(['trial_ends_at' => now()->addMonths(2)]);
        $admin   = User::factory()->companyAdmin($company)->create();

        Artisan::call('subscriptions:notify-expiring');

        Notification::assertNotSentTo($admin, TrialEndingNotification::class);
    }

    public function test_an_already_lapsed_company_is_not_emailed_a_warning(): void
    {
        Notification::fake();

        $company = Company::factory()->create(['trial_ends_at' => now()->subDay()]);
        $admin   = User::factory()->companyAdmin($company)->create();

        Artisan::call('subscriptions:notify-expiring');

        Notification::assertNotSentTo($admin, TrialEndingNotification::class);
    }

    public function test_the_same_company_is_not_emailed_every_day(): void
    {
        Notification::fake();

        $company = Company::factory()->create(['trial_ends_at' => now()->addDays(5)]);
        $admin   = User::factory()->companyAdmin($company)->create();

        Artisan::call('subscriptions:notify-expiring');
        Artisan::call('subscriptions:notify-expiring');
        Artisan::call('subscriptions:notify-expiring');

        Notification::assertSentToTimes($admin, TrialEndingNotification::class, 1);
    }

    public function test_it_warns_again_once_enough_days_have_passed(): void
    {
        Notification::fake();

        $company = Company::factory()->create([
            'trial_ends_at'      => now()->addDays(5),
            'expiry_notified_at' => now()->subDays(config('subscription.notify_again_after_days') + 1),
        ]);
        $admin = User::factory()->companyAdmin($company)->create();

        Artisan::call('subscriptions:notify-expiring');

        Notification::assertSentTo($admin, TrialEndingNotification::class);
    }

    public function test_employees_are_not_emailed_only_admins(): void
    {
        Notification::fake();

        $company  = Company::factory()->create(['trial_ends_at' => now()->addDays(3)]);
        $admin    = User::factory()->companyAdmin($company)->create();
        $employee = User::factory()->employee($company)->create();

        Artisan::call('subscriptions:notify-expiring');

        Notification::assertSentTo($admin, TrialEndingNotification::class);
        Notification::assertNotSentTo($employee, TrialEndingNotification::class);
    }

    public function test_the_warning_email_renders_with_no_raw_keys(): void
    {
        $company = Company::factory()->create(['trial_ends_at' => now()->addDays(6)]);
        $user    = User::factory()->companyAdmin($company)->create(['name' => 'Owner Person']);

        foreach (['en', 'ar'] as $locale) {
            $html = view('emails.trial-ending', [
                'user'     => $user,
                'company'  => $company,
                'daysLeft' => 6,
                'endsOn'   => $company->trial_ends_at->toDateString(),
                'lang'     => $locale,
                'locale'   => $locale,
            ])->render();

            $this->assertDoesNotMatchRegularExpression('/emails\.[a-z_.]+/', $html, "[{$locale}] raw keys in the trial email.");
            $this->assertStringContainsString('Owner Person', $html);
            $this->assertStringContainsString($company->trial_ends_at->toDateString(), $html);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────

    public function test_days_until_expiry_never_goes_negative(): void
    {
        $company = Company::factory()->create(['trial_ends_at' => now()->subDays(30)]);

        $this->assertSame(0, $company->daysUntilExpiry());
        $this->assertTrue($company->hasLapsed());
        $this->assertFalse($company->isExpiringSoon(), 'A lapsed trial is not "expiring soon" — it has expired.');
    }

    public function test_restarting_the_trial_clears_the_notified_flag(): void
    {
        $company = Company::factory()->create([
            'trial_ends_at'      => now()->subDay(),
            'expiry_notified_at' => now()->subDay(),
        ]);

        $company->startTrial();

        $this->assertFalse($company->hasLapsed());
        $this->assertNull($company->expiry_notified_at, 'A renewed company should be eligible for warnings again.');
    }
}
