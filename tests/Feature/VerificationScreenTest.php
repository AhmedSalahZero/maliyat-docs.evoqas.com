<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\EmailVerificationCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  The verification screen has to keep knowing who it is for.
//
//  Nobody is signed in while they are on it — registration
//  deliberately does not log anyone in until they are verified — so
//  the screen depends entirely on being told which account is
//  waiting. It was told by FLASHED session data, which survives
//  exactly one request.
//
//  So it knew the address the first time it rendered and never
//  again. A refresh lost it. So did pressing "Resend code", because
//  that redirects back, and a redirect is a fresh request. The
//  visible symptom was the Resend button being permanently
//  unclickable — it disables itself when there is no address to send
//  to — but the same address is what the typed code is checked
//  against, so the whole screen was one refresh from unusable.
//
//  The address now lives in ordinary session data until verification
//  actually completes, and every door onto the screen sets it:
//  signing up, and signing in with an account that was never
//  verified.
// ══════════════════════════════════════════════════════════════════
class VerificationScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('register');
    }

    private function registerSomebody(string $email = 'ahmed@example.test'): void
    {
        $this->post('/register', [
            'name'                  => 'Ahmed Salah',
            'company_name'          => 'Evoqas Trading',
            'email'                 => $email,
            'currency'              => 'EGP',
            'language'              => 'en',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'business_types'        => ['trading'],
            '_hp'                   => '',
        ])->assertSessionHasNoErrors();
    }

    private function addressOnScreen(): ?string
    {
        return $this->get('/verify-email')->viewData('page')['props']['email'] ?? null;
    }

    // ── It remembers across requests ─────────────────────────────

    public function test_the_screen_knows_the_address_on_first_load(): void
    {
        $this->registerSomebody();

        $this->assertSame('ahmed@example.test', $this->addressOnScreen());
    }

    /**
     * The exact failure: flashed data is gone by the second request.
     */
    public function test_it_still_knows_the_address_after_a_refresh(): void
    {
        $this->registerSomebody();

        $this->addressOnScreen();   // first load consumes the flash
        $this->addressOnScreen();

        $this->assertSame(
            'ahmed@example.test',
            $this->addressOnScreen(),
            'The screen forgot which account it is for, so Resend is dead'
        );
    }

    /**
     * Resend redirects back, which is itself a fresh request — so it
     * used to destroy the very thing it needs to work twice.
     */
    public function test_resending_does_not_destroy_the_address(): void
    {
        $this->registerSomebody();
        $this->addressOnScreen();

        $this->post('/verify-email/resend', ['email' => 'ahmed@example.test'])
            ->assertSessionHasNoErrors();

        $this->assertSame('ahmed@example.test', $this->addressOnScreen());
    }

    public function test_a_code_can_be_resent_more_than_once(): void
    {
        $this->registerSomebody();

        for ($i = 0; $i < 3; $i++) {
            $this->post('/verify-email/resend', ['email' => 'ahmed@example.test'])
                ->assertSessionHasNoErrors();

            $this->assertSame('ahmed@example.test', $this->addressOnScreen());
        }

        $this->assertSame(1, EmailVerificationCode::count(), 'Each new code replaces the last');
    }

    // ── Every door onto the screen sets it ───────────────────────

    public function test_signing_in_unverified_also_leaves_the_address_behind(): void
    {
        $company = Company::factory()->create();
        $user    = User::factory()->companyAdmin($company)->create([
            'email'             => 'unverified@example.test',
            'password'          => 'password123',
            'email_verified_at' => null,
        ]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password123']);

        $this->assertSame('unverified@example.test', $this->addressOnScreen());
    }

    /**
     * A link in an email carries the address in the URL.
     */
    public function test_an_address_in_the_url_still_works(): void
    {
        $company = Company::factory()->create();
        User::factory()->companyAdmin($company)->create([
            'email'             => 'linked@example.test',
            'email_verified_at' => null,
        ]);

        $props = $this->get('/verify-email?email=linked@example.test')
            ->viewData('page')['props'];

        $this->assertSame('linked@example.test', $props['email']);
    }

    // ── It stops remembering once there is nothing to verify ─────

    public function test_verifying_clears_the_pending_address(): void
    {
        $this->registerSomebody();

        $user = User::query()->firstOrFail();
        $code = '123456';

        EmailVerificationCode::query()->delete();
        EmailVerificationCode::create([
            'user_id'    => $user->id,
            'code_hash'  => bcrypt($code),
            'expires_at' => now()->addHour(),
        ]);

        $this->post('/verify-email', ['email' => $user->email, 'code' => $code])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertNull(session('verification.pending_email'));
    }

    // ── Landing cold ─────────────────────────────────────────────

    /**
     * No sign-up and no sign-in behind it: there is nothing to verify
     * and nothing to resend to. The screen should say so rather than
     * render a form whose button cannot be pressed.
     */
    public function test_landing_with_no_pending_account_shows_no_dead_form(): void
    {
        $this->assertNull($this->addressOnScreen());

        $page = file_get_contents(resource_path('js/Pages/Auth/VerifyEmail.vue'));

        $this->assertStringContainsString(
            'v-if="!form.email"',
            $page,
            'Nothing explains an empty screen'
        );
        $this->assertStringContainsString('verify_unknown_address', $page);
    }

    /**
     * The button used to disable on `!form.email`, which is how a
     * lost address became an unclickable button. The form is now
     * hidden entirely in that case, so the guard must be gone —
     * otherwise the same dead-button state can come back.
     */
    public function test_the_resend_button_is_not_gated_on_a_value_the_user_cannot_supply(): void
    {
        $page = file_get_contents(resource_path('js/Pages/Auth/VerifyEmail.vue'));

        $this->assertStringNotContainsString(
            'resendForm.processing || !form.email',
            $page,
            'Resend is disabled whenever the address is missing, which is the original bug'
        );
    }

    public function test_the_unknown_address_message_exists_in_both_languages(): void
    {
        $translations = file_get_contents(resource_path('js/lang/authTranslations.js'));

        $this->assertSame(
            2,
            substr_count($translations, 'verify_unknown_address:'),
            'It needs exactly one English and one Arabic entry'
        );
    }
}
