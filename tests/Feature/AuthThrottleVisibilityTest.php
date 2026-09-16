<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  Being rate limited must look like being rate limited.
//
//  Every auth route is throttled, which is right. What was wrong is
//  what a throttled request produced: a bare 429 HTML error page.
//  That is not an Inertia response, so the page the person is
//  looking at did nothing at all — no message, no movement, no
//  explanation.
//
//  Registration is where it bit hardest. Somebody whose first
//  attempt failed for any reason retries a few times, silently burns
//  five attempts a minute, and from then on the form is dead in a
//  way that looks IDENTICAL to whatever went wrong first. They
//  cannot tell a fixed bug from a lockout, so they keep retrying,
//  which keeps the lockout alive.
//
//  A throttled Inertia request now comes back as a normal form error
//  naming the wait, which the page already knows how to display.
//  Plain (non-Inertia) requests are left alone — an API client or a
//  curl should still get a real 429 with its Retry-After header.
// ══════════════════════════════════════════════════════════════════
class AuthThrottleVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('register');
    }

    private function payload(int $n): array
    {
        return [
            'name'                  => "Person {$n}",
            'company_name'          => "Company {$n}",
            'email'                 => "person{$n}@example.test",
            'currency'              => 'EGP',
            'language'              => 'en',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'business_types'        => ['trading'],
            '_hp'                   => '',
        ];
    }

    /** Registration allows 5 a minute; this spends all of them. */
    private function exhaustTheLimit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->post('/register', $this->payload($i));
        }
    }

    public function test_the_first_attempts_go_through(): void
    {
        $this->exhaustTheLimit();

        $this->assertGreaterThan(0, User::count());
    }

    public function test_a_throttled_signup_reports_a_normal_form_error(): void
    {
        $this->exhaustTheLimit();

        $this->withHeaders(['X-Inertia' => 'true'])
            ->post('/register', $this->payload(99))
            ->assertRedirect()
            ->assertSessionHasErrors('email');
    }

    public function test_the_message_says_how_long_to_wait(): void
    {
        $this->exhaustTheLimit();

        $this->withHeaders(['X-Inertia' => 'true'])->post('/register', $this->payload(99));

        $message = session('errors')->get('email')[0] ?? '';

        $this->assertNotSame('', trim($message));
        $this->assertMatchesRegularExpression(
            '/\d+/',
            $message,
            'The wait is not stated, so the user cannot act on it'
        );
    }

    /**
     * This handler fires on registration and password reset too, so
     * the message must not be the sign-in one.
     */
    public function test_the_message_is_not_about_logging_in(): void
    {
        $this->exhaustTheLimit();

        $this->withHeaders(['X-Inertia' => 'true'])->post('/register', $this->payload(99));

        $this->assertStringNotContainsStringIgnoringCase(
            'login',
            session('errors')->get('email')[0] ?? ''
        );
    }

    public function test_a_throttled_signup_creates_nothing(): void
    {
        $this->exhaustTheLimit();

        $before = User::count();

        $this->withHeaders(['X-Inertia' => 'true'])->post('/register', $this->payload(99));

        $this->assertSame($before, User::count());
    }

    /**
     * The redirect is a courtesy to the browser, not a change to what
     * rate limiting means — anything else still gets a real 429.
     */
    public function test_a_plain_request_still_gets_a_real_429(): void
    {
        $this->exhaustTheLimit();

        $this->post('/register', $this->payload(99))->assertStatus(429);
    }

    public function test_the_wait_message_exists_in_both_languages(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $strings = require lang_path("{$locale}/auth.php");

            $this->assertArrayHasKey('throttle_requests', $strings, "{$locale} is missing it");
            $this->assertStringContainsString(
                ':seconds',
                $strings['throttle_requests'],
                'The wait is not interpolated into the message'
            );
        }
    }

    /**
     * Sign-in runs through the same middleware — a dead login form is
     * worse than a dead signup one.
     */
    public function test_a_throttled_sign_in_also_reports_visibly(): void
    {
        RateLimiter::clear('login');

        $company = Company::factory()->create();
        $user    = User::factory()->companyAdmin($company)->create([
            'email'             => 'someone@example.test',
            'password'          => 'password123',
            'email_verified_at' => now(),
        ]);

        for ($i = 0; $i < 7; $i++) {
            $this->withHeaders(['X-Inertia' => 'true'])
                ->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $this->withHeaders(['X-Inertia' => 'true'])
            ->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');
    }
}
