<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  The company user's own profile page.
//
//  Rewritten from Breeze's scaffolding, which pointed at /profile and
//  tested an account-deletion feature this app does not have. Here
//  the page lives under /app/profile, the email address is fixed
//  (ProfileController::update only persists `name`), and passwords
//  must satisfy App\Support\PasswordRules — at least eight
//  characters with letters and numbers.
// ══════════════════════════════════════════════════════════════════
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_profile_page_is_displayed(): void
    {
        $user = User::factory()->companyAdmin()->create();

        $this->actingAs($user)->get(route('app.profile.index'))->assertOk();
    }

    public function test_the_name_can_be_updated(): void
    {
        $user = User::factory()->companyAdmin()->create(['name' => 'Old Name']);

        $this->actingAs($user)
            ->patch(route('app.profile.update'), ['name' => 'New Name'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('New Name', $user->refresh()->name);
    }

    public function test_a_too_short_name_is_rejected(): void
    {
        $user = User::factory()->companyAdmin()->create(['name' => 'Original']);

        $this->actingAs($user)
            ->patch(route('app.profile.update'), ['name' => 'A'])
            ->assertSessionHasErrors('name');

        $this->assertSame('Original', $user->refresh()->name);
    }

    public function test_the_email_address_is_not_changed_by_a_profile_update(): void
    {
        $user  = User::factory()->companyAdmin()->create();
        $email = $user->email;

        // Even if an email field is posted, only `name` is validated
        // and persisted — so the address must survive untouched.
        $this->actingAs($user)->patch(route('app.profile.update'), [
            'name'  => 'Renamed',
            'email' => 'attacker@example.test',
        ]);

        $this->assertSame($email, $user->refresh()->email);
    }

    public function test_the_password_can_be_changed(): void
    {
        $user = User::factory()->companyAdmin()->create();

        $this->actingAs($user)
            ->patch(route('app.profile.password'), [
                'current_password'      => 'password',
                'password'              => 'new-password1',
                'password_confirmation' => 'new-password1',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertTrue(Hash::check('new-password1', $user->refresh()->password));
    }

    public function test_the_current_password_must_be_right(): void
    {
        $user = User::factory()->companyAdmin()->create();

        $this->actingAs($user)
            ->patch(route('app.profile.password'), [
                'current_password'      => 'not-the-password',
                'password'              => 'new-password1',
                'password_confirmation' => 'new-password1',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_a_password_without_a_number_is_rejected(): void
    {
        $user = User::factory()->companyAdmin()->create();

        $this->actingAs($user)
            ->patch(route('app.profile.password'), [
                'current_password'      => 'password',
                'password'              => 'letters-only',
                'password_confirmation' => 'letters-only',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_a_guest_cannot_reach_the_profile(): void
    {
        $this->get(route('app.profile.index'))->assertRedirect(route('login'));
    }
}
