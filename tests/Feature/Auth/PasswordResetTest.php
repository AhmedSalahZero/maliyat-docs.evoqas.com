<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  Forgotten-password flow.
//
//  The Breeze original asserted on Laravel's stock ResetPassword
//  notification; this app sends its own bilingual
//  App\Notifications\ResetPasswordNotification instead, which is why
//  those tests failed.
//
//  The token itself is private on the notification, so these tests
//  mint one through the broker the same way the mail does rather
//  than reaching into the object.
// ══════════════════════════════════════════════════════════════════
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_forgot_password_screen_can_be_rendered(): void
    {
        $this->get('/forgot-password')->assertOk();
    }

    public function test_a_reset_link_is_emailed(): void
    {
        Notification::fake();

        $user = User::factory()->companyAdmin()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_the_reset_screen_can_be_rendered_with_a_token(): void
    {
        $user  = User::factory()->companyAdmin()->create();
        $token = Password::createToken($user);

        $this->get('/reset-password/'.$token)->assertOk();
    }

    public function test_the_password_can_be_reset_with_a_valid_token(): void
    {
        $user  = User::factory()->companyAdmin()->create();
        $token = Password::createToken($user);

        $this->post('/reset-password', [
            'token'                 => $token,
            'email'                 => $user->email,
            'password'              => 'brand-new1',
            'password_confirmation' => 'brand-new1',
        ])
            ->assertSessionHasNoErrors()
            // A successful reset signs the user straight in rather
            // than bouncing them back to the login form.
            ->assertRedirect(route('app.dashboard'));

        $this->assertTrue(Hash::check('brand-new1', $user->refresh()->password));
        $this->assertAuthenticatedAs($user->refresh());
    }

    public function test_an_invalid_token_is_refused(): void
    {
        $user = User::factory()->companyAdmin()->create();

        $this->post('/reset-password', [
            'token'                 => 'not-a-real-token',
            'email'                 => $user->email,
            'password'              => 'brand-new1',
            'password_confirmation' => 'brand-new1',
        ])->assertSessionHasErrors();

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_a_reset_password_must_satisfy_the_projects_rules(): void
    {
        $user  = User::factory()->companyAdmin()->create();
        $token = Password::createToken($user);

        $this->post('/reset-password', [
            'token'                 => $token,
            'email'                 => $user->email,
            'password'              => 'lettersonly',
            'password_confirmation' => 'lettersonly',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }
}
