<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  A rejected password has to say why, in the reader's language.
//
//  Reported as "no message appeared on register". The server was in
//  fact returning one every time — but two things made it useless:
//
//    - There was no lang/ar/validation.php, so every rule fell back
//      to Laravel's English defaults. An Arabic customer filling an
//      Arabic form was told "The password field must be at least 8
//      characters." That is a fair thing to describe as no message.
//
//    - The mismatch message said "Password confirmed." — the text
//      for the OPPOSITE outcome. Somebody who mistyped the second
//      box was told they had succeeded, while the form refused to
//      move.
//
//  And the requirements were only ever discoverable by failing: the
//  placeholder mentioned the length and nothing else, so the letter
//  and number rules were invisible until they were broken.
// ══════════════════════════════════════════════════════════════════
class PasswordFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('register');
    }

    private function register(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        RateLimiter::clear('register');

        return $this->post('/register', array_merge([
            'name'                  => 'Ahmed Salah',
            'company_name'          => 'Evoqas Trading',
            'email'                 => uniqid().'@example.test',
            'currency'              => 'EGP',
            'language'              => 'en',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'business_types'        => ['trading'],
            '_hp'                   => '',
        ], $overrides));
    }

    private function passwordError(): string
    {
        return session('errors')?->get('password')[0] ?? '';
    }

    // ── Every rule reports something ─────────────────────────────

    /**
     * @return array<string, array{0: string}>
     */
    public static function unacceptablePasswords(): array
    {
        return [
            'too short'      => ['abc1'],
            'numbers only'   => ['12345678'],
            'letters only'   => ['password'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unacceptablePasswords')]
    public function test_a_rejected_password_explains_itself(string $password): void
    {
        $this->register(['password' => $password, 'password_confirmation' => $password])
            ->assertSessionHasErrors('password');

        $this->assertNotSame('', trim($this->passwordError()), 'Rejected with no explanation');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unacceptablePasswords')]
    public function test_a_rejected_password_creates_no_account(string $password): void
    {
        $this->register(['password' => $password, 'password_confirmation' => $password]);

        $this->assertSame(0, \App\Models\User::count());
    }

    /**
     * The message said the opposite of what happened.
     */
    public function test_a_mismatched_confirmation_says_they_do_not_match(): void
    {
        $this->register([
            'password'              => 'password123',
            'password_confirmation' => 'different999',
        ])->assertSessionHasErrors('password');

        $message = $this->passwordError();

        $this->assertStringNotContainsStringIgnoringCase(
            'confirmed',
            $message,
            'The mismatch message still reads as a success'
        );
        $this->assertStringContainsStringIgnoringCase('not match', $message);
    }

    public function test_an_acceptable_password_is_taken(): void
    {
        $this->register()->assertSessionHasNoErrors();

        $this->assertSame(1, \App\Models\User::count());
    }

    // ── In the reader's language ─────────────────────────────────

    public function test_an_arabic_user_is_told_in_arabic(): void
    {
        app()->setLocale('ar');

        $this->register([
            'language'              => 'ar',
            'password'              => 'abc1',
            'password_confirmation' => 'abc1',
        ])->assertSessionHasErrors('password');

        $this->assertMatchesRegularExpression(
            '/\p{Arabic}/u',
            $this->passwordError(),
            'An Arabic customer is shown an English message on an Arabic form'
        );
    }

    public function test_arabic_validation_strings_exist_at_all(): void
    {
        $this->assertFileExists(
            lang_path('ar/validation.php'),
            'Every validation failure falls back to English without this'
        );
    }

    /**
     * Without translated field names the message reads half-Arabic:
     * "حقل password مطلوب".
     */
    public function test_the_field_names_are_translated_too(): void
    {
        $strings = require lang_path('ar/validation.php');

        $this->assertArrayHasKey('attributes', $strings);

        foreach (['name', 'email', 'password', 'company_name'] as $field) {
            $this->assertArrayHasKey($field, $strings['attributes'], "'{$field}' has no Arabic name");
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $strings['attributes'][$field]);
        }
    }

    public function test_the_password_rules_are_translated(): void
    {
        $strings = require lang_path('ar/validation.php');

        foreach (['letters', 'numbers'] as $rule) {
            $this->assertArrayHasKey($rule, $strings['password'] ?? []);
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $strings['password'][$rule]);
        }
    }

    // ── Said before it can be failed ─────────────────────────────

    /**
     * The placeholder mentioned only the length, so the letter and
     * number rules were invisible until the form refused to submit.
     */
    public function test_the_form_states_the_rules_up_front(): void
    {
        $page = file_get_contents(resource_path('js/Pages/Auth/Register.vue'));

        $this->assertStringContainsString(
            "t('password_rule_hint')",
            $page,
            'Nothing tells the user the requirements before they fail them'
        );
    }

    public function test_the_hint_exists_in_both_languages(): void
    {
        $translations = file_get_contents(resource_path('js/lang/authTranslations.js'));

        $this->assertSame(
            2,
            substr_count($translations, 'password_rule_hint:'),
            'The hint needs exactly one English and one Arabic entry'
        );
    }

    /**
     * The hint occupies the same slot as the error, so it must give
     * way rather than sit alongside a contradiction.
     */
    public function test_the_hint_gives_way_to_the_error(): void
    {
        $page = file_get_contents(resource_path('js/Pages/Auth/Register.vue'));

        $this->assertMatchesRegularExpression(
            '/v-if="!form\.errors\.password"[^>]*>\{\{ t\(\'password_rule_hint\'\)/s',
            $page,
            'The hint and the error can be shown at the same time'
        );
    }
}
