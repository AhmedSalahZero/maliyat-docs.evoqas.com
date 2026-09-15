<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  Every screen a signed-out user can reach belongs to this app.
//
//  The app was scaffolded from Laravel Breeze, and Breeze ships its
//  own stock pages. Most were replaced; ConfirmPassword.vue was not,
//  and kept the default GuestLayout with Tailwind utility classes
//  and hardcoded English in an otherwise bilingual product. It is
//  rarely reached, which is exactly why nobody noticed — a user who
//  did land there would suddenly be looking at a different app.
//
//  These tests hold three lines. Every follow-on auth screen renders
//  through the app's own shell (IpLoginShell); every auth page is
//  bilingual; and every Breeze component that was only kept alive by
//  those pages is gone rather than lying in wait for the next screen
//  to reach for it by mistake.
//
//  The flows themselves — the reset link and the verification code —
//  have their own tests in Auth/; what is checked here is that the
//  pages behind them exist and are ours.
// ══════════════════════════════════════════════════════════════════
class AuthScreenConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private function source(string $page): string
    {
        $path = resource_path("js/Pages/Auth/{$page}.vue");

        $this->assertFileExists($path, "{$page}.vue is missing");

        return file_get_contents($path);
    }

    /**
     * Every page a signed-out user can reach.
     *
     * @return list<array{0: string}>
     */
    public static function authPages(): array
    {
        return [
            'login'            => ['Login'],
            'register'         => ['Register'],
            'forgot password'  => ['ForgotPassword'],
            'reset password'   => ['ResetPassword'],
            'verify email'     => ['VerifyEmail'],
            'confirm password' => ['ConfirmPassword'],
        ];
    }

    /**
     * The secondary auth screens — the ones reached FROM login rather
     * than instead of it.
     *
     * Login and Register are deliberately not in this list: they are
     * the product's front door and carry their own full-page layout
     * (a split hero with marketing copy) rather than the compact
     * shell the follow-on screens use. That is a design decision, not
     * a leftover — what matters is that everything BEHIND them looks
     * like one app.
     *
     * @return list<array{0: string}>
     */
    public static function secondaryAuthPages(): array
    {
        return [
            'forgot password'  => ['ForgotPassword'],
            'reset password'   => ['ResetPassword'],
            'verify email'     => ['VerifyEmail'],
            'confirm password' => ['ConfirmPassword'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('secondaryAuthPages')]
    public function test_the_page_uses_the_apps_own_shell(string $page): void
    {
        $this->assertStringContainsString(
            'IpLoginShell',
            $this->source($page),
            "{$page}.vue is not using the app's auth shell"
        );
    }

    /**
     * Bilingual by either route: the shared translation composable,
     * or the inline locale ternaries Login and Register use for their
     * own marketing copy. What is being checked is that an Arabic
     * user is not shown an English-only page.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('authPages')]
    public function test_the_page_is_bilingual(string $page): void
    {
        $source = $this->source($page);

        $this->assertTrue(
            str_contains($source, 'useAuthTranslations') || str_contains($source, "locale === 'ar'"),
            "{$page}.vue has no Arabic at all, so it renders English for every user"
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('authPages')]
    public function test_the_page_carries_no_breeze_leftovers(string $page): void
    {
        $source = $this->source($page);

        foreach (['GuestLayout', 'PrimaryButton', 'TextInput', 'InputLabel', 'InputError'] as $stock) {
            $this->assertStringNotContainsString(
                "@/Components/{$stock}.vue",
                $source,
                "{$page}.vue still imports Breeze's {$stock}"
            );
            $this->assertStringNotContainsString(
                "@/Layouts/{$stock}.vue",
                $source,
                "{$page}.vue still imports Breeze's {$stock}"
            );
        }
    }

    public function test_the_unused_breeze_components_are_gone(): void
    {
        foreach (['Components/InputError', 'Components/InputLabel', 'Components/PrimaryButton',
                  'Components/TextInput', 'Layouts/GuestLayout'] as $stock) {
            $this->assertFileDoesNotExist(
                resource_path("js/{$stock}.vue"),
                "{$stock}.vue is unused — leaving it invites the next screen to reach for it"
            );
        }
    }

    // ── The pages actually render ────────────────────────────────

    public function test_the_forgot_password_page_renders(): void
    {
        $this->get(route('password.request'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/ForgotPassword'));
    }

    public function test_the_reset_page_renders_with_a_token(): void
    {
        $this->get(route('password.reset', ['token' => 'a-token', 'email' => 'x@example.test']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/ResetPassword'));
    }

    public function test_the_verification_page_renders(): void
    {
        $company = Company::factory()->create();
        $user    = User::factory()->companyAdmin($company)->create(['email_verified_at' => null]);

        $this->actingAs($user)
            ->get(route('verification.notice'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/VerifyEmail'));
    }

    public function test_the_confirm_password_page_renders(): void
    {
        $company = Company::factory()->create();
        $user    = User::factory()->companyAdmin($company)->create();

        $this->actingAs($user)
            ->get(route('password.confirm'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/ConfirmPassword'));
    }

    // ── The reset mail is the app's, not the framework's ─────────

    public function test_the_reset_email_is_the_projects_own_notification(): void
    {
        Notification::fake();

        $company = Company::factory()->create();
        $user    = User::factory()->companyAdmin($company)->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_the_reset_email_renders_with_no_raw_translation_keys(): void
    {
        $company = Company::factory()->create();
        $user    = User::factory()->companyAdmin($company)->create();

        $token = Password::broker()->createToken($user);
        $mail  = (new ResetPasswordNotification($token))->toMail($user);

        $html = (string) $mail->render();

        $this->assertStringNotContainsString('emails.', $html, 'A translation key leaked into the email body');
        $this->assertNotEmpty(trim(strip_tags($html)));
    }
}
