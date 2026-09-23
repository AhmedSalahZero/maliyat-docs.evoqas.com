<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  Breeze's own PUT /password endpoint, which this project still
//  routes (password.update). The app's own profile screen posts to
//  app.profile.password instead — that path is covered by
//  Tests\Feature\ProfileTest.
//
//  The original version of this file used 'new-password', which has
//  no digit and so can never satisfy App\Support\PasswordRules.
// ══════════════════════════════════════════════════════════════════
class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_password_can_be_updated(): void
    {
        $user = User::factory()->companyAdmin()->create();

        $this->actingAs($user)
            ->from(route('app.profile.index'))
            ->put('/password', [
                'current_password'      => 'password',
                'password'              => 'New-password1',
                'password_confirmation' => 'New-password1',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('app.profile.index'));

        $this->assertTrue(Hash::check('New-password1', $user->refresh()->password));
    }

    public function test_the_current_password_must_be_correct(): void
    {
        $user = User::factory()->companyAdmin()->create();

        $this->actingAs($user)
            ->from(route('app.profile.index'))
            ->put('/password', [
                'current_password'      => 'wrong-password',
                'password'              => 'New-password1',
                'password_confirmation' => 'New-password1',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_the_confirmation_must_match(): void
    {
        $user = User::factory()->companyAdmin()->create();

        $this->actingAs($user)
            ->from(route('app.profile.index'))
            ->put('/password', [
                'current_password'      => 'password',
                'password'              => 'New-password1',
                'password_confirmation' => 'Different-password1',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }
}
