<?php

namespace Tests\Feature;

use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  A class name with no rule behind it is a broken screen.
//
//  The auth screens share a vocabulary — .ip-login__label,
//  .ip-login__form, .ip-form-group, .ip-login__submit. Those rules
//  were written three times, inside the SCOPED styles of Login.vue,
//  ForgotPassword.vue and Register.vue. A scoped style only reaches
//  the component it lives in, so the three screens that never
//  received a copy — ResetPassword, VerifyEmail, ConfirmPassword —
//  used the same class names and got nothing: unstyled labels,
//  unspaced fields, error text in the browser's default. And
//  .ip-form-group had never been defined anywhere at all.
//
//  The most visible casualty was "Resend Verification Code", whose
//  .ip-login__link-btn did not exist, so it rendered as a raw
//  operating-system button in the middle of a designed card. It
//  looked broken because it was.
//
//  Nothing about this fails loudly: the page renders, the markup is
//  valid, the class is simply inert. It survives every test that
//  checks behaviour, and a person only finds it by opening that one
//  screen. So it gets checked here instead.
// ══════════════════════════════════════════════════════════════════
class AuthStylingCompletenessTest extends TestCase
{
    /**
     * Every class an auth screen puts in the DOM has to resolve
     * somewhere: the shared stylesheet, or that file's own scoped
     * block.
     *
     * @return array<string, array{0: string}>
     */
    public static function authScreens(): array
    {
        return [
            'login'            => ['Pages/Auth/Login.vue'],
            'register'         => ['Pages/Auth/Register.vue'],
            'forgot password'  => ['Pages/Auth/ForgotPassword.vue'],
            'reset password'   => ['Pages/Auth/ResetPassword.vue'],
            'verify email'     => ['Pages/Auth/VerifyEmail.vue'],
            'confirm password' => ['Pages/Auth/ConfirmPassword.vue'],
            'the shell'        => ['Components/Auth/IpLoginShell.vue'],
        ];
    }

    private function stylesheet(): string
    {
        return file_get_contents(resource_path('css/app.css'));
    }

    private function source(string $relative): string
    {
        $path = resource_path("js/{$relative}");

        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    /**
     * @return list<string>
     */
    private function classesUsedIn(string $source): array
    {
        $used = [];

        preg_match_all('/\bclass="([^"]+)"/', $source, $literal);
        foreach ($literal[1] as $attr) {
            $used = array_merge($used, preg_split('/\s+/', trim($attr)));
        }

        // :class="{ 'ip-thing--error': cond }"
        preg_match_all("/'([a-zA-Z0-9_-]+)':\s/", $source, $conditional);
        $used = array_merge($used, $conditional[1]);

        // Only the app's own auth vocabulary — utility classes from
        // elsewhere are not this test's business.
        return array_values(array_unique(array_filter(
            $used,
            fn (string $class) => str_starts_with($class, 'ip-')
        )));
    }

    private function classesDefinedIn(string $source): array
    {
        if (! str_contains($source, '<style')) {
            return [];
        }

        preg_match_all('/\.([a-zA-Z0-9_-]+)/', explode('<style', $source, 2)[1], $matches);

        return $matches[1];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('authScreens')]
    public function test_every_class_it_uses_actually_has_a_rule(string $page): void
    {
        $source = $this->source($page);

        preg_match_all('/\.([a-zA-Z0-9_-]+)/', $this->stylesheet(), $global);

        $available = array_merge($global[1], $this->classesDefinedIn($source));

        $orphans = array_values(array_diff($this->classesUsedIn($source), $available));

        $this->assertSame(
            [],
            $orphans,
            "{$page} uses class names nothing styles, so they render as browser defaults: "
                .implode(', ', $orphans)
        );
    }

    /**
     * The shared vocabulary belongs in the stylesheet every screen
     * reads, not in one screen's scoped block where the next screen
     * cannot see it.
     */
    public function test_the_shared_auth_classes_live_in_the_shared_stylesheet(): void
    {
        $stylesheet = $this->stylesheet();

        foreach ([
            'ip-form-group',
            'ip-login__form',
            'ip-login__label',
            'ip-login__error',
            'ip-login__submit',
            'ip-login__link-btn',
            'ip-login__row-actions',
            'ip-login__status',
        ] as $class) {
            $this->assertMatchesRegularExpression(
                '/\.'.preg_quote($class, '/').'\s*[,{:]/',
                $stylesheet,
                "'{$class}' is shared between auth screens but is not in app.css"
            );
        }
    }

    /**
     * The button that started this: it performs an action rather than
     * navigating, so it is a <button> — and a <button> with no rule
     * falls back to the operating system's grey default.
     */
    public function test_the_resend_button_is_styled(): void
    {
        $this->assertStringContainsString(
            'ip-login__link-btn',
            $this->source('Pages/Auth/VerifyEmail.vue'),
            'The resend action lost its class'
        );

        $stylesheet = $this->stylesheet();

        foreach (['background', 'border', 'cursor', 'font-weight'] as $property) {
            $this->assertMatchesRegularExpression(
                '/\.ip-login__link-btn\s*\{[^}]*'.$property.'/s',
                $stylesheet,
                "The resend button has no {$property}, so it still reads as a default button"
            );
        }
    }

    /**
     * The address the code was sent to is shown, not asked — the
     * server already knows it, and editing it cannot do what someone
     * would expect, because the code has already gone out.
     */
    public function test_the_verification_screen_shows_the_address_rather_than_asking_for_it(): void
    {
        $source = $this->source('Pages/Auth/VerifyEmail.vue');

        $this->assertDoesNotMatchRegularExpression(
            '/<input[^>]*id="email"/s',
            $source,
            'The email is still an editable field on a screen that already knows it'
        );

        $this->assertStringContainsString('ip-verify-address', $source);
        $this->assertStringContainsString("t('code_sent_to')", $source);
    }

    /**
     * An email address read right-to-left is wrong, and Arabic is
     * half this app's audience.
     */
    public function test_the_address_stays_left_to_right_in_arabic(): void
    {
        $this->assertMatchesRegularExpression(
            '/ip-verify-address__value"[^>]*dir="ltr"/',
            $this->source('Pages/Auth/VerifyEmail.vue')
        );
    }

    public function test_the_new_label_exists_in_both_languages(): void
    {
        $translations = file_get_contents(resource_path('js/lang/authTranslations.js'));

        $this->assertSame(
            2,
            substr_count($translations, 'code_sent_to:'),
            'code_sent_to needs exactly one English and one Arabic entry'
        );
    }
}
