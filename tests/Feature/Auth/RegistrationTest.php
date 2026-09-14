<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  Public sign-up.
//
//  Unlike the Breeze scaffolding this replaces, registering here
//  creates a Company as well as its first user, makes that user its
//  company_admin, and does NOT log them in — email has to be
//  verified first, so they land on the code screen.
//
//  Two anti-bot fields guard the form: _hp is a honeypot that must
//  stay empty, and _ft is the millisecond timestamp of when the form
//  was rendered, which must be at least three seconds old.
// ══════════════════════════════════════════════════════════════════
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            '_hp'                   => '',
            '_ft'                   => (now()->timestamp - 10) * 1000,
            'company_name'          => 'Northwind Trading',
            'currency'              => 'SAR',
            'name'                  => 'Amina Salah',
            'email'                 => 'amina@example.test',
            'password'              => 'secret-pass1',
            'password_confirmation' => 'secret-pass1',
            'language'              => 'ar',
        ], $overrides);
    }

    public function test_the_registration_screen_can_be_rendered(): void
    {
        $this->get('/register')->assertOk();
    }

    public function test_registering_creates_a_company_and_its_first_admin(): void
    {
        Notification::fake();

        $this->post('/register', $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('verification.notice'));

        $company = Company::sole();
        $user    = User::sole();

        $this->assertSame('Northwind Trading', $company->name);
        $this->assertSame('SAR', $company->currency);
        $this->assertTrue($company->is_active);

        $this->assertSame($company->id, $user->company_id);
        $this->assertSame(UserRole::CompanyAdmin->value, $user->role);
        $this->assertSame('ar', $user->language);
    }

    public function test_the_new_company_gets_a_chart_of_accounts(): void
    {
        Notification::fake();

        $this->post('/register', $this->payload());

        $this->assertGreaterThan(
            0,
            Account::withoutGlobalScopes()->where('company_id', Company::sole()->id)->count(),
            'A company with no accounts cannot post a single journal entry.'
        );
    }

    public function test_the_user_is_not_signed_in_until_they_verify(): void
    {
        Notification::fake();

        $this->post('/register', $this->payload());

        $this->assertGuest();
        $this->assertFalse(User::sole()->hasVerifiedEmail());
    }

    public function test_a_duplicate_email_is_rejected(): void
    {
        User::factory()->companyAdmin()->create(['email' => 'taken@example.test']);

        $this->post('/register', $this->payload(['email' => 'taken@example.test']))
            ->assertSessionHasErrors('email');
    }

    public function test_a_filled_honeypot_is_rejected(): void
    {
        $this->post('/register', $this->payload(['_hp' => 'i am a bot']))
            ->assertSessionHasErrors('_hp');

        $this->assertSame(0, User::count());
    }

    public function test_a_form_submitted_too_fast_is_rejected(): void
    {
        // Submitted the same instant the page rendered — no human
        // fills a sign-up form that quickly.
        $this->post('/register', $this->payload(['_ft' => now()->timestamp * 1000]))
            ->assertSessionHasErrors('email');

        $this->assertSame(0, User::count());
    }

    public function test_a_weak_password_is_rejected(): void
    {
        $this->post('/register', $this->payload([
            'password'              => 'lettersonly',
            'password_confirmation' => 'lettersonly',
        ]))->assertSessionHasErrors('password');

        $this->assertSame(0, User::count());
    }
}
