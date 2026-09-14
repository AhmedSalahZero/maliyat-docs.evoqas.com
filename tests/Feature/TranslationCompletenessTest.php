<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  A missing translation key is not a silent failure in Laravel —
//  __('emails.verify_code.heading') renders the key itself, straight
//  into the page or the email. Three whole namespaces (emails,
//  errors) plus eight auth keys were missing, which meant every
//  verification email arrived as a list of dotted identifiers and
//  blocked new sign-ups outright.
//
//  These tests walk the source for real translation calls and assert
//  each one resolves in both locales, so that class of bug can't
//  come back unnoticed.
// ══════════════════════════════════════════════════════════════════
class TranslationCompletenessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Namespaces we own. Framework namespaces (validation, passwords)
     * fall back to vendor files and aren't published here.
     */
    private const OWNED_NAMESPACES = ['emails', 'errors', 'auth'];

    /**
     * @return list<string>
     */
    private function translationKeysUsedInSource(): array
    {
        $roots = [app_path(), resource_path('views')];
        $keys  = [];

        foreach ($roots as $root) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                preg_match_all(
                    "/(?:__|trans)\(\s*'([a-z_]+\.[a-z_.]+)'/i",
                    (string) file_get_contents($file->getPathname()),
                    $matches
                );

                foreach ($matches[1] as $key) {
                    $namespace = strtok($key, '.');

                    if (in_array($namespace, self::OWNED_NAMESPACES, true)) {
                        $keys[$key] = true;
                    }
                }
            }
        }

        return array_keys($keys);
    }

    public function test_every_translation_key_used_in_php_resolves_in_both_locales(): void
    {
        $keys = $this->translationKeysUsedInSource();

        $this->assertNotEmpty($keys, 'Found no translation calls to check — the scanner is broken.');

        $missing = [];

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);

            foreach ($keys as $key) {
                if (__($key) === $key) {
                    $missing[] = "[{$locale}] {$key}";
                }
            }
        }

        $this->assertSame([], $missing, "These keys would be shown to users as raw identifiers:\n".implode("\n", $missing));
    }

    public function test_the_two_locale_files_define_the_same_keys(): void
    {
        foreach (self::OWNED_NAMESPACES as $namespace) {
            $en = array_keys(\Illuminate\Support\Arr::dot(require lang_path("en/{$namespace}.php")));
            $ar = array_keys(\Illuminate\Support\Arr::dot(require lang_path("ar/{$namespace}.php")));

            sort($en);
            sort($ar);

            $this->assertSame(
                $en,
                $ar,
                "lang/en/{$namespace}.php and lang/ar/{$namespace}.php have drifted apart."
            );
        }
    }

    /**
     * The templates are where the original bug actually showed up, so
     * assert on the rendered output rather than only on key lookups.
     */
    public function test_the_verification_email_renders_with_no_raw_keys(): void
    {
        $user = User::factory()->companyAdmin()->create(['name' => 'Test Person']);

        foreach (['en', 'ar'] as $locale) {
            $html = view('emails.verify-email-code', [
                'user'            => $user,
                'code'            => '123456',
                'expiresMinutes'  => 15,
                'lang'            => $locale,
                'locale'          => $locale,
            ])->render();

            $this->assertDoesNotMatchRegularExpression(
                '/emails\.[a-z_.]+/',
                $html,
                "[{$locale}] the verification email still contains raw translation keys."
            );

            $this->assertStringContainsString('123456', $html, "[{$locale}] the code itself is missing.");
            $this->assertStringContainsString('Test Person', $html, "[{$locale}] the greeting lost the user's name.");
        }
    }

    public function test_the_password_reset_email_renders_with_no_raw_keys(): void
    {
        $user = User::factory()->companyAdmin()->create();

        foreach (['en', 'ar'] as $locale) {
            $html = view('emails.reset-password', [
                'user'          => $user,
                'url'           => 'https://example.test/reset/token',
                'expireMinutes' => 60,
                'lang'          => $locale,
                'locale'        => $locale,
            ])->render();

            $this->assertDoesNotMatchRegularExpression(
                '/emails\.[a-z_.]+/',
                $html,
                "[{$locale}] the reset email still contains raw translation keys."
            );

            $this->assertStringContainsString('https://example.test/reset/token', $html);
        }
    }

    public function test_email_subjects_are_real_text(): void
    {
        foreach (['en', 'ar'] as $locale) {
            foreach (['emails.verify_code.subject', 'emails.reset_password.subject'] as $key) {
                $subject = __($key, [], $locale);

                $this->assertNotSame($key, $subject, "[{$locale}] {$key} is missing — the email would have a dotted key as its subject line.");
            }
        }
    }
}
