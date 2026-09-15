<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\EmailVerificationCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  Signing up must never fail in silence.
//
//  The registration form carries two hidden anti-bot fields: a
//  honeypot (_hp) that only a bot would fill, and a timestamp (_ft)
//  used to reject a submission made faster than a human could type.
//  Both are validated. Neither had anywhere on the form to show an
//  error.
//
//  So a person who tripped one — and a real person could, because
//  the honeypot was named "website", which browsers and password
//  managers autofill from a saved profile without being asked —
//  pressed Create Account and watched the page do absolutely
//  nothing. No message, no movement, no email, no account. Nothing
//  to act on, and nothing in front of them suggesting a reload
//  would help.
//
//  Two changes close it. Every anti-bot rule now carries a real
//  message, and the page renders any error whose field it cannot
//  draw, so a rule added later cannot reintroduce the silence.
//
//  The message is deliberately vague about WHICH guard fired:
//  telling a bot exactly which field gave it away defeats the point
//  of having them.
// ══════════════════════════════════════════════════════════════════
class RegistrationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /** What the browser sends when a person fills the form properly. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name'                  => 'Ahmed Salah',
            'company_name'          => 'Evoqas Trading',
            'email'                 => 'ahmed@example.test',
            'currency'              => 'EGP',
            'language'              => 'en',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            '_hp'                   => '',
            '_ft'                   => (now()->timestamp * 1000) - 10000,
        ], $overrides);
    }

    // ── The happy path still works ───────────────────────────────

    public function test_a_normal_signup_creates_the_company_and_its_admin(): void
    {
        $this->post('/register', $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('verification.notice'));

        $this->assertSame(1, Company::count());
        $this->assertSame(1, User::count());
        $this->assertSame('company_admin', User::query()->firstOrFail()->role);
    }

    public function test_a_normal_signup_issues_a_verification_code(): void
    {
        $this->post('/register', $this->payload())->assertSessionHasNoErrors();

        $this->assertSame(1, EmailVerificationCode::count());
        $this->assertSame('verification-code-sent', session('status'));
    }

    // ── Nothing fails silently ───────────────────────────────────

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function blockedSubmissions(): array
    {
        return [
            'the browser autofilled the honeypot' => [['_hp' => 'https://example.com'], '_hp'],
            'the timestamp never got set'         => [['_ft' => 0], '_ft'],
            'the timestamp was stripped'          => [['_ft' => null], '_ft'],
            'the timestamp is not a number'       => [['_ft' => 'abc'], '_ft'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blockedSubmissions')]
    public function test_a_blocked_submission_says_something(array $overrides, string $field): void
    {
        $this->post('/register', $this->payload($overrides))
            ->assertSessionHasErrors($field);

        $message = session('errors')->get($field)[0] ?? '';

        $this->assertNotSame('', trim($message), 'The guard fired with no message at all');
        $this->assertSame(__('auth.blocked_submission'), $message);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blockedSubmissions')]
    public function test_a_blocked_submission_creates_nothing(array $overrides): void
    {
        $this->post('/register', $this->payload($overrides));

        $this->assertSame(0, User::count());
        $this->assertSame(0, Company::count());
    }

    /**
     * The one guard that already had somewhere to speak: too-fast
     * submissions report against the email field.
     */
    public function test_a_submission_faster_than_a_human_is_refused_on_a_visible_field(): void
    {
        $this->post('/register', $this->payload(['_ft' => (now()->timestamp * 1000) - 500]))
            ->assertSessionHasErrors('email');

        $this->assertSame(0, User::count());
    }

    public function test_the_block_message_exists_in_both_languages(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $strings = require lang_path("{$locale}/auth.php");

            $this->assertArrayHasKey('blocked_submission', $strings, "{$locale} is missing the message");
            $this->assertNotSame('', trim($strings['blocked_submission']));
        }
    }

    /**
     * Vague on purpose — naming the field that caught a bot tells the
     * next bot how to get past it.
     */
    public function test_the_block_message_does_not_name_the_guard(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $message = (require lang_path("{$locale}/auth.php"))['blocked_submission'];

            foreach (['honeypot', '_hp', '_ft', 'timestamp', 'bot'] as $giveaway) {
                $this->assertStringNotContainsStringIgnoringCase($giveaway, $message);
            }
        }
    }

    // ── The page can render what the server sends ────────────────

    private function registerPage(): string
    {
        return file_get_contents(resource_path('js/Pages/Auth/Register.vue'));
    }

    /**
     * The page with its comments stripped.
     *
     * The comments explain what the honeypot used to be named, and a
     * plain search would match that explanation and fail on prose
     * rather than on markup.
     */
    private function registerMarkup(): string
    {
        $source = $this->registerPage();

        $source = preg_replace('/<!--.*?-->/s', '', $source);
        $source = preg_replace('#^\s*//.*$#m', '', $source);

        return $source;
    }

    public function test_the_page_renders_errors_it_has_no_field_for(): void
    {
        $source = $this->registerPage();

        $this->assertStringContainsString(
            'unboundError',
            $source,
            'An error for a hidden field would have nowhere to appear'
        );

        $this->assertStringContainsString(
            'v-if="unboundError"',
            $source,
            'The catch-all error is computed but never rendered'
        );
    }

    /**
     * A catch-all rather than a hardcoded pair, so a rule added to
     * the request later is surfaced automatically.
     */
    public function test_the_catch_all_is_not_a_list_of_the_two_known_fields(): void
    {
        $source = $this->registerPage();

        $this->assertStringContainsString(
            'VISIBLE_FIELDS.includes',
            $source,
            'The page checks for specific hidden fields instead of anything it cannot draw'
        );
    }

    /**
     * The original honeypot was name="website" — a field browsers
     * and password managers fill from a saved profile unprompted,
     * which turned a helpful browser into a reason a real customer
     * could not sign up.
     */
    public function test_the_honeypot_is_not_named_something_browsers_autofill(): void
    {
        $source = $this->registerMarkup();

        foreach (['name="website"', 'name="url"', 'name="company"', 'name="address"'] as $magnet) {
            $this->assertStringNotContainsString(
                $magnet,
                $source,
                "The honeypot uses {$magnet}, which browsers autofill"
            );
        }
    }

    public function test_the_honeypot_refuses_autofill_every_way_it_can(): void
    {
        $source = $this->registerMarkup();

        foreach (['autocomplete="off"', 'data-lpignore', 'data-1p-ignore'] as $refusal) {
            $this->assertStringContainsString(
                $refusal,
                $source,
                "The honeypot does not carry {$refusal}, so a password manager may still fill it"
            );
        }
    }

    /**
     * Zero is the value that used to get through to the server and
     * be rejected there, invisibly.
     */
    public function test_the_time_guard_is_stamped_before_anything_can_submit(): void
    {
        $this->assertStringNotContainsString(
            '_ft: 0,',
            $this->registerMarkup(),
            'The time guard starts at zero, which the server rejects'
        );

        $this->assertStringContainsString('_ft: Date.now()', $this->registerPage());
    }
}
