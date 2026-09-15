<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  Every form screen explains how to use itself.
//
//  The app is built for somebody who runs a shop, not somebody who
//  knows bookkeeping — so each entry screen carries a short numbered
//  "how to use this screen" panel above the form, collapsed after
//  the first visit so it never gets in a regular user's way.
//
//  What these tests hold in place is the part that rots quietly: a
//  new form added without a panel, a step referencing a translation
//  key nobody wrote, or an English string with no Arabic beside it.
//  None of those break the app — they just leave a user staring at
//  `howto_sale_3` on screen — which is exactly the kind of thing
//  that survives a manual test pass.
// ══════════════════════════════════════════════════════════════════
class FormInstructionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every screen whose job is entering data. These are the ones a
     * newcomer has to be walked through; report screens only display.
     *
     * @return array<string, array{0: string}>
     */
    public static function formScreens(): array
    {
        return [
            'sales'               => ['Pages/App/Sales/Index.vue'],
            'expenses'            => ['Pages/App/Expenses/Index.vue'],
            'inventory purchases' => ['Pages/App/InventoryPurchases/Index.vue'],
            'equipment purchases' => ['Pages/App/EquipmentPurchases/Index.vue'],
            'custodies'           => ['Pages/App/Custodies/Index.vue'],
            'payments'            => ['Pages/App/Payments/Index.vue'],
            'team'                => ['Pages/App/Team/Index.vue'],
            'reference data'      => ['Pages/App/Lookups/Index.vue'],
            'opening balance'     => ['Pages/App/Settings/OpeningBalance.vue'],
        ];
    }

    private function source(string $relative): string
    {
        $path = resource_path("js/{$relative}");

        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    private function translations(): string
    {
        return file_get_contents(resource_path('js/lang/appTranslations.js'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('formScreens')]
    public function test_the_screen_carries_an_instructions_panel(string $page): void
    {
        $this->assertStringContainsString(
            '<FormInstructions',
            $this->source($page),
            "{$page} has no how-to panel"
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('formScreens')]
    public function test_every_step_it_names_actually_has_text(string $page): void
    {
        $source       = $this->source($page);
        $translations = $this->translations();

        preg_match_all("/'(howto_[a-z0-9_]+)'/", $source, $matches);
        preg_match_all('/"(howto_[a-z0-9_]+)"/', $source, $attrMatches);

        $keys = array_unique(array_merge($matches[1], $attrMatches[1]));

        $this->assertNotEmpty($keys, "{$page} references no instruction text at all");

        foreach ($keys as $key) {
            $this->assertStringContainsString(
                "{$key}:",
                $translations,
                "{$page} asks for '{$key}', which has no text — the user would see the raw key"
            );
        }
    }

    public function test_every_instruction_exists_in_both_languages(): void
    {
        $translations = $this->translations();

        preg_match_all('/(howto_[a-z0-9_]+|howToUseLbl|goodToKnowLbl):/', $translations, $matches);

        $counts = array_count_values($matches[1]);

        foreach ($counts as $key => $count) {
            $this->assertSame(
                2,
                $count,
                "'{$key}' is defined {$count} time(s) — it needs exactly one English and one Arabic"
            );
        }
    }

    public function test_no_instruction_text_is_left_unused(): void
    {
        $translations = $this->translations();

        preg_match_all('/(howto_[a-z0-9_]+):/', $translations, $matches);

        $defined = array_unique($matches[1]);

        $used = [];
        foreach (array_merge(
            glob(resource_path('js/Pages/App/*/Index.vue')),
            glob(resource_path('js/Pages/App/*/*.vue')),
            glob(resource_path('js/Components/App/*.vue')),
        ) as $file) {
            preg_match_all('/howto_[a-z0-9_]+/', file_get_contents($file), $found);
            $used = array_merge($used, $found[0]);
        }

        $orphans = array_diff($defined, array_unique($used));

        $this->assertEmpty(
            $orphans,
            'Instruction text nothing shows: '.implode(', ', $orphans)
        );
    }

    /**
     * The panel's whole value is being skippable — a user recording
     * their fortieth sale should not have to scroll past a paragraph
     * to reach the form.
     */
    public function test_the_panel_can_be_collapsed_and_remembers_that(): void
    {
        $component = $this->source('Components/App/FormInstructions.vue');

        $this->assertStringContainsString('localStorage', $component, 'The panel does not remember being closed');
        $this->assertStringContainsString('formKey', $component, 'Each screen needs its own memory');
    }

    /**
     * Storage throws in a private window and on blocked site data. A
     * help panel must never be the reason a page fails to render.
     */
    public function test_the_panel_survives_storage_being_unavailable(): void
    {
        $component = $this->source('Components/App/FormInstructions.vue');

        $this->assertSame(
            2,
            substr_count($component, 'catch'),
            'Both the read and the write of the remembered state must be guarded'
        );
    }

    /**
     * It is read on a phone more than anywhere else, so the trigger
     * has to be a real tap target and the layout has to reflow.
     */
    public function test_the_panel_is_built_for_a_phone(): void
    {
        $component = $this->source('Components/App/FormInstructions.vue');

        $this->assertStringContainsString('@media (max-width: 480px)', $component, 'No phone-width styles');
        $this->assertStringContainsString('min-height: 48px', $component, 'The tap target is too small on a phone');
    }

    public function test_the_steps_avoid_accounting_jargon(): void
    {
        $translations = $this->translations();

        preg_match_all("/howto_[a-z0-9_]+: '([^']*)'/", $translations, $matches);

        // Terms a shop owner should never have to meet to use a form.
        // If a step needs one of these, the step is explaining the
        // bookkeeping rather than the screen.
        $jargon = ['debit', 'credit', 'ledger', 'journal', 'accrual', 'double-entry', 'reconcil'];

        foreach ($matches[1] as $text) {
            foreach ($jargon as $term) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $term,
                    $text,
                    "An instruction uses the word '{$term}': \"{$text}\""
                );
            }
        }
    }
}
