<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  An email has to survive having its <style> block deleted.
//
//  Plenty of mail clients strip <style> from <head> on delivery.
//  These templates kept their entire design there, so when one did,
//  everything went: no card, no header band, no code box, no footer
//  — unstyled text running the full width of the reading pane.
//
//  What made it hard to name is how it presented. It did not look
//  like a missing stylesheet. It looked like ONE line was in the
//  wrong place — because the only styles that survive are the inline
//  ones, and the templates had exactly two, both a text-align:center
//  on an expiry line. That line was the only element still obeying
//  its styling, so it read as the single broken thing in an
//  otherwise fine email. It was the opposite: the only unbroken one.
//
//  So the check is not "does it look right" — it is "does every
//  element carry its own styling", because that is what makes the
//  <style> block optional rather than load-bearing.
// ══════════════════════════════════════════════════════════════════
class EmailRenderingTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return new User(['name' => 'Ahmed Salah', 'email' => 'ahmed@example.test', 'language' => 'en']);
    }

    private function render(string $view, array $data = [], string $locale = 'en'): string
    {
        return view("emails.{$view}", array_merge([
            'user'    => $this->user(),
            'locale'  => $locale,
            'subject' => 'Test',
        ], $data))->render();
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function everyEmail(): array
    {
        return [
            'verification code' => ['verify-email-code', ['code' => '046771', 'expiresMinutes' => 15]],
            'password reset'    => ['reset-password', ['url' => 'https://example.test/reset/abc', 'expireMinutes' => 60]],
            'trial ending'      => ['trial-ending', ['daysLeft' => 3, 'endsOn' => '2026-10-01']],
        ];
    }

    private function dataFor(array $extra): array
    {
        if (array_key_exists('daysLeft', $extra)) {
            $extra['company'] = new Company(['name' => 'Evoqas Trading']);
        }

        return $extra;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('everyEmail')]
    public function test_every_visible_element_carries_its_own_styling(string $view, array $extra): void
    {
        $html = $this->render($view, $this->dataFor($extra));

        preg_match_all('/<(p|h1|span|td)\b[^>]*>/', $html, $matches);

        $bare = array_values(array_filter(
            $matches[0],
            fn (string $tag) => ! str_contains($tag, 'style=')
        ));

        $this->assertSame(
            [],
            $bare,
            "These elements rely on the <style> block, so they lose all styling if a client strips it: "
                .implode(' ', $bare)
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('everyEmail')]
    public function test_nothing_depends_on_a_class_from_the_style_block(string $view, array $extra): void
    {
        $html = $this->render($view, $this->dataFor($extra));

        preg_match_all('/class="([^"]+)"/', $html, $matches);

        foreach ($matches[1] as $classAttr) {
            foreach (preg_split('/\s+/', trim($classAttr)) as $class) {
                // m-* classes exist only for the phone breakpoint,
                // which an inline style cannot express. Losing them
                // costs a font size on mobile, not the whole design.
                $this->assertStringStartsWith(
                    'm-',
                    $class,
                    "'{$class}' carries design that vanishes if <style> is stripped"
                );
            }
        }
    }

    /**
     * The quotes in a font stack become &#039; if the style string is
     * escaped, which breaks the declaration.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('everyEmail')]
    public function test_the_css_is_not_html_escaped(string $view, array $extra): void
    {
        $html = $this->render($view, $this->dataFor($extra));

        // Style attributes only. An escaped apostrophe inside body
        // text — "didn't create this account" — is correct; one
        // inside a font stack is not.
        preg_match_all('/style="([^"]*)"/', $html, $matches);

        foreach ($matches[1] as $css) {
            $this->assertStringNotContainsString(
                '&#039;',
                $css,
                'A style attribute was HTML-escaped, which breaks the font stack: '.$css
            );
        }

        $this->assertStringContainsString("font-family:'Inter'", $html);
    }

    /**
     * The line that started this. Centred deliberately, under the box
     * it captions — and it must stay a deliberate choice rather than
     * an accident of being the only styled element.
     */
    public function test_the_expiry_caption_is_centred_with_the_box_it_belongs_to(): void
    {
        $html = $this->render('verify-email-code', ['code' => '046771', 'expiresMinutes' => 15]);

        $this->assertMatchesRegularExpression(
            '/<p style="[^"]*text-align:center;[^"]*">\s*This code expires/',
            $html
        );
    }

    /**
     * Everything that is NOT a caption follows the reading direction,
     * so a left-aligned English email has no stray centred lines.
     */
    public function test_body_text_follows_the_reading_direction(): void
    {
        $english = $this->render('verify-email-code', ['code' => '1', 'expiresMinutes' => 15], 'en');
        $arabic  = $this->render('verify-email-code', ['code' => '1', 'expiresMinutes' => 15], 'ar');

        $this->assertStringContainsString('text-align:left;', $english);
        $this->assertStringContainsString('text-align:right;', $arabic);
        $this->assertStringContainsString('dir="rtl"', $arabic);
    }

    /**
     * A code and a date are numbers. Arabic does not reverse digits,
     * and an RTL container would reorder them.
     */
    public function test_codes_and_dates_stay_left_to_right_in_arabic(): void
    {
        $html = $this->render('verify-email-code', ['code' => '046771', 'expiresMinutes' => 15], 'ar');

        $this->assertMatchesRegularExpression('/<span[^>]*dir="ltr"[^>]*>046771</', $html);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('everyEmail')]
    public function test_the_brand_and_footer_survive(string $view, array $extra): void
    {
        $html = $this->render($view, $this->dataFor($extra));

        $this->assertStringContainsString('logo-icon-light.png', $html);
        $this->assertStringContainsString('Maliyat Docs', $html);
    }

    /**
     * Most mail clients block remote images by default, so the header
     * cannot depend on one loading. The brand name is real text.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('everyEmail')]
    public function test_the_header_reads_without_the_image(string $view, array $extra): void
    {
        $html = $this->render($view, $this->dataFor($extra));

        $withoutImages = preg_replace('/<img[^>]*>/', '', $html);

        $this->assertStringContainsString(
            'Maliyat Docs',
            $withoutImages,
            'With images blocked the header shows nothing at all'
        );
    }

    /**
     * An empty alt leaves a clean header when the image is blocked;
     * a populated one puts broken-image text next to the brand name
     * that is already there in real text.
     */
    public function test_the_logo_does_not_duplicate_the_brand_name_as_alt_text(): void
    {
        $html = $this->render('verify-email-code', ['code' => '1', 'expiresMinutes' => 15]);

        $this->assertMatchesRegularExpression('/<img[^>]*alt=""/', $html);
    }

    /**
     * The recipient's mail client fetches this from wherever they
     * are. A development host shows a broken image to everyone.
     */
    public function test_the_image_host_is_configurable_away_from_app_url(): void
    {
        config(['mail.asset_url' => 'https://maliyat-docs.evoqas.com']);

        $html = $this->render('verify-email-code', ['code' => '1', 'expiresMinutes' => 15]);

        $this->assertStringContainsString(
            'https://maliyat-docs.evoqas.com/images/logo-icon-light.png',
            $html
        );
    }

    /**
     * The full logo is portrait (409x610). Sized by height it renders
     * about 32px wide — a sliver.
     */
    public function test_the_header_uses_the_square_icon(): void
    {
        $html = $this->render('verify-email-code', ['code' => '1', 'expiresMinutes' => 15]);

        $this->assertStringContainsString('logo-icon-light.png', $html);
        $this->assertStringNotContainsString('logo-dark.png', $html);
        $this->assertMatchesRegularExpression('/<img[^>]*width:52px; height:52px/', $html);
    }

    /**
     * A reset email whose button is a plain blue link has lost the
     * one thing the recipient is supposed to press.
     */
    public function test_the_reset_button_looks_like_a_button(): void
    {
        $html = $this->render('reset-password', [
            'url' => 'https://example.test/reset/abc', 'expireMinutes' => 60,
        ]);

        $this->assertMatchesRegularExpression(
            '/<a href="https:\/\/example\.test\/reset\/abc" style="[^"]*background:#1D9E75[^"]*"/',
            $html,
            'The call to action renders as a default link, not a button'
        );
    }

    /**
     * A reset URL is long. Without this it pushes the email wider
     * than the card and everything scrolls sideways.
     */
    public function test_the_fallback_link_wraps_instead_of_stretching_the_email(): void
    {
        $html = $this->render('reset-password', [
            'url' => 'https://example.test/reset/'.str_repeat('a', 120), 'expireMinutes' => 60,
        ]);

        $this->assertStringContainsString('word-break:break-all', $html);
    }
}
