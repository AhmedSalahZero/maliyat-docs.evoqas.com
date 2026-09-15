<?php

namespace Tests\Feature;

use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  No screen may show the name of a different product.
//
//  The auth shell's header was hardcoded to "InPractice" — the
//  project this codebase was built out of. Login and Register carry
//  their own layout and so were never affected, which is why it
//  survived: the four screens that DID show it (forgot password,
//  reset password, verify email, confirm password) are the ones
//  nobody opens during normal use.
//
//  They are also, precisely, the screens a customer reaches before
//  they have an account. The first unfamiliar thing a new signup saw
//  was another company's name.
//
//  An earlier round reported the InPractice leftovers as cleared. It
//  had removed the dead FILES; a hardcoded string inside a live
//  component is invisible to that kind of sweep, and nothing checked
//  for it. This is that check.
// ══════════════════════════════════════════════════════════════════
class AuthBrandingTest extends TestCase
{
    /**
     * Everything a signed-out person can see, plus the shell they all
     * render inside.
     *
     * @return array<string, array{0: string}>
     */
    public static function customerFacingFiles(): array
    {
        return [
            'the auth shell'   => ['Components/Auth/IpLoginShell.vue'],
            'login'            => ['Pages/Auth/Login.vue'],
            'register'         => ['Pages/Auth/Register.vue'],
            'forgot password'  => ['Pages/Auth/ForgotPassword.vue'],
            'reset password'   => ['Pages/Auth/ResetPassword.vue'],
            'verify email'     => ['Pages/Auth/VerifyEmail.vue'],
            'confirm password' => ['Pages/Auth/ConfirmPassword.vue'],
            'the app layout'   => ['Layouts/AppLayout.vue'],
        ];
    }

    private function source(string $relative): string
    {
        $path = resource_path("js/{$relative}");

        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    /**
     * Comments explaining what a thing used to be called are fine —
     * they are not rendered. Only what reaches the screen counts.
     */
    private function renderedText(string $source): string
    {
        $source = preg_replace('/<!--.*?-->/s', '', $source);
        $source = preg_replace('#^\s*//.*$#m', '', $source);
        $source = preg_replace('#/\*.*?\*/#s', '', $source);

        return $source;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('customerFacingFiles')]
    public function test_it_never_shows_the_old_product_name(string $page): void
    {
        $rendered = $this->renderedText($this->source($page));

        foreach (['InPractice', 'In<span>Practice', 'in-practice'] as $ghost) {
            $this->assertStringNotContainsString(
                $ghost,
                $rendered,
                "{$page} still puts \"{$ghost}\" in front of a customer"
            );
        }
    }

    public function test_the_shell_header_carries_this_products_brand(): void
    {
        $source = $this->source('Components/Auth/IpLoginShell.vue');

        $this->assertStringContainsString('Maliyat Docs', $source);
        $this->assertStringContainsString('ماليات دوكس', $source, 'The header is English-only');
    }

    /**
     * The full logo is a portrait lockup (409x610) sized for Login's
     * tall hero panel. Dropping it into a header bar makes the header
     * taller than the card it sits above.
     */
    public function test_the_header_uses_the_square_icon_not_the_tall_lockup(): void
    {
        $source = $this->source('Components/Auth/IpLoginShell.vue');

        $this->assertStringContainsString('logo-icon-', $source);
        $this->assertDoesNotMatchRegularExpression(
            "#:src=\"[^\"]*'/images/logo-(dark|light)\.png'#",
            $this->renderedText($source),
            'The header uses the tall stacked logo'
        );
    }

    /**
     * A single-colour mark needs a version for each theme, or it
     * disappears into one of them.
     */
    public function test_the_brand_has_a_version_for_each_theme(): void
    {
        $source = $this->source('Components/Auth/IpLoginShell.vue');

        $this->assertStringContainsString('logo-icon-light.png', $source);
        $this->assertStringContainsString('logo-icon-dark.png', $source);
        $this->assertStringContainsString('isDark', $source, 'Both files exist but nothing chooses between them');
    }

    /**
     * @return list<string>
     */
    public static function brandAssets(): array
    {
        return [
            ['images/logo-icon-dark.png'],
            ['images/logo-icon-light.png'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brandAssets')]
    public function test_the_brand_asset_exists(string $asset): void
    {
        $this->assertFileExists(public_path($asset), "{$asset} is referenced but not on disk");
    }

    /**
     * The name a customer reads in an email is the same one they read
     * on the screen that email sends them to.
     */
    public function test_the_emails_and_the_screens_agree_on_the_name(): void
    {
        $arabicEmails = file_get_contents(lang_path('ar/emails.php'));
        $shell        = $this->source('Components/Auth/IpLoginShell.vue');

        $this->assertStringContainsString('ماليات دوكس', $arabicEmails);
        $this->assertStringContainsString('ماليات دوكس', $shell);
    }
}
