<?php

namespace Tests\Feature;

use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  The product is called Maliyat Docs in both languages.
//
//  The Arabic was written "مالية دوكس" — maliya, not maliyat. One
//  missing letter, and it turns the brand into an ordinary adjective
//  ("financial") instead of the name on the domain, the logo and
//  every English string. It was in ten places: the app name itself,
//  the install prompt, the sign-in page and all four emails, so an
//  Arabic-speaking customer met the wrong name before they ever
//  reached the app.
//
//  A brand name is exactly the kind of string nobody re-reads, which
//  is why it is worth a test rather than a careful eye.
// ══════════════════════════════════════════════════════════════════
class BrandNameTest extends TestCase
{
    private const ARABIC_NAME = 'ماليات دوكس';

    /** The misspelling this test exists to prevent coming back. */
    private const MISSPELLING = 'مالية دوكس';

    /**
     * @return list<string>
     */
    private function textFiles(): array
    {
        return array_merge(
            glob(resource_path('js/**/*.vue')) ?: [],
            glob(resource_path('js/**/**/*.vue')) ?: [],
            glob(resource_path('js/lang/*.js')) ?: [],
            glob(lang_path('ar/*.php')) ?: [],
            glob(resource_path('views/**/*.blade.php')) ?: [],
        );
    }

    public function test_the_arabic_name_is_never_misspelled(): void
    {
        $offenders = [];

        foreach ($this->textFiles() as $file) {
            if (str_contains(file_get_contents($file), self::MISSPELLING)) {
                $offenders[] = str_replace(base_path().'/', '', $file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'The Arabic brand name is missing its ت in: '.implode(', ', $offenders)
        );
    }

    public function test_the_arabic_app_name_is_the_brand(): void
    {
        $translations = file_get_contents(resource_path('js/lang/appTranslations.js'));

        $this->assertStringContainsString(
            "app_name: '".self::ARABIC_NAME."'",
            $translations
        );
    }

    public function test_the_english_app_name_is_unchanged(): void
    {
        $this->assertStringContainsString(
            "app_name: 'Maliyat Docs'",
            file_get_contents(resource_path('js/lang/appTranslations.js'))
        );
    }

    /**
     * Every Arabic email a customer receives carries the name, so
     * these are where a misspelling is most visible and least
     * recoverable.
     */
    public function test_the_arabic_emails_use_the_brand(): void
    {
        $emails = file_get_contents(lang_path('ar/emails.php'));

        $this->assertStringNotContainsString(self::MISSPELLING, $emails);
        $this->assertGreaterThanOrEqual(
            5,
            substr_count($emails, self::ARABIC_NAME),
            'The Arabic emails stopped naming the product'
        );
    }
}
