<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  Signing in and out.
//
//  Rewritten from the Breeze scaffolding this project was copied
//  with, which assumed a single `dashboard` route and users without
//  a company. Here every non-admin user belongs to a company and
//  lands on app.dashboard; a super admin lands on admin.dashboard.
//
//  Who is refused entry is covered separately in AccountAccessTest.
// ══════════════════════════════════════════════════════════════════
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_screen_can_be_rendered(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_a_company_user_lands_on_the_company_dashboard(): void
    {
        $user = User::factory()->companyAdmin()->create();

        $response = $this->post('/login', [
            'email'    => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('app.dashboard', absolute: false));
    }

    public function test_a_super_admin_lands_on_the_admin_dashboard(): void
    {
        $user = User::factory()->superAdmin()->create();

        $response = $this->post('/login', [
            'email'    => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('admin.dashboard', absolute: false));
    }

    public function test_the_wrong_password_is_refused(): void
    {
        $user = User::factory()->companyAdmin()->create();

        $this->post('/login', [
            'email'    => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_an_unknown_email_is_refused(): void
    {
        $this->post('/login', [
            'email'    => 'nobody@example.test',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_repeated_failures_are_rate_limited(): void
    {
        $user = User::factory()->companyAdmin()->create();

        // The limiter allows five attempts before locking the pair.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString(
            'seconds',
            (string) session('errors')->first('email'),
            'The sixth attempt should be throttled, not just rejected.'
        );
    }

    public function test_users_can_log_out(): void
    {
        $user = User::factory()->companyAdmin()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect('/');

        $this->assertGuest();
    }
}
