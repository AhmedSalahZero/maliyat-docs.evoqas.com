<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  Who is allowed in, and who is turned away.
//
//  Covers the gap where Company::is_active was writable from the
//  admin screen but read by nothing: deactivating a company changed
//  a flag and otherwise did nothing at all. The rule now lives in
//  User::accessDenialReason() and is enforced twice — once at login,
//  and again on every request through EnsureMember, so revoking
//  access takes effect for sessions that are already open.
// ══════════════════════════════════════════════════════════════════
class AccountAccessTest extends TestCase
{
    use RefreshDatabase;

    private function login(User $user, string $password = 'password'): \Illuminate\Testing\TestResponse
    {
        return $this->post('/login', [
            'email'    => $user->email,
            'password' => $password,
        ]);
    }

    public function test_an_active_user_in_an_active_company_can_log_in(): void
    {
        $user = User::factory()->companyAdmin()->create();

        $this->login($user)->assertRedirect(route('app.dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_user_of_a_suspended_company_cannot_log_in(): void
    {
        $company = Company::factory()->suspended()->create();
        $user    = User::factory()->companyAdmin($company)->create();

        $this->login($user)->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_an_individually_suspended_user_cannot_log_in(): void
    {
        $user = User::factory()->companyAdmin()->suspended()->create();

        $this->login($user)->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_the_users_own_suspension_is_reported_over_the_companys(): void
    {
        $company = Company::factory()->suspended()->create();
        $user    = User::factory()->companyAdmin($company)->suspended()->create();

        $this->assertSame('errors.account_suspended', $user->accessDenialReason());
    }

    public function test_a_super_admin_is_never_blocked_by_a_company(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->assertNull($user->accessDenialReason());

        $this->login($user)->assertRedirect(route('admin.dashboard', absolute: false));
    }

    public function test_suspending_a_company_mid_session_locks_the_user_out_on_the_next_request(): void
    {
        $company = Company::factory()->create();
        $user    = User::factory()->companyAdmin($company)->create();

        // Sign in for real, so the following requests go through the
        // session guard and rebuild the user from scratch each time —
        // exactly like two separate browser requests. (actingAs()
        // would reuse one in-memory User whose `company` relation
        // HandleInertiaRequests has already cached, which is not how
        // a second HTTP request behaves.)
        $this->login($user)->assertRedirect(route('app.dashboard', absolute: false));

        $this->get(route('app.dashboard'))->assertOk();

        $company->update(['is_active' => false]);

        // The very next request bounces them out — they don't keep
        // working until their session happens to expire.
        $this->get(route('app.dashboard'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_the_denial_message_is_real_text_not_a_translation_key(): void
    {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);

            foreach (['errors.account_suspended', 'errors.company_suspended', 'errors.forbidden'] as $key) {
                $this->assertNotSame(
                    $key,
                    __($key),
                    "[{$locale}] {$key} has no translation and would be shown to the user as a raw key."
                );
            }
        }
    }
}
