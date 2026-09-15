<?php

namespace Tests\Feature;

use Tests\TestCase;

// ══════════════════════════════════════════════════════════════════
//  Pressing Edit must land on the form, not on whatever happens to
//  sit at the top of the page.
//
//  Every entry screen puts its form at the top and its list of saved
//  rows underneath. Pressing Edit on a row halfway down that list
//  fills the form and scrolls up to it.
//
//  That scroll was written as window.scrollTo({ top: 0 }), which
//  worked only by coincidence: the form was the first thing under
//  the page title, so the top of the document and the top of the
//  form were the same place. Adding a how-to panel above the form
//  broke it — the scroll still went to zero, which was now the
//  instructions, leaving the form the user had just asked to edit
//  below the fold.
//
//  Scrolling to the form ELEMENT is what the action always meant,
//  and nothing added above the form can break it again. These tests
//  hold that: no page goes back to a bare scroll-to-zero, every page
//  has a form to scroll to, and the sticky header is accounted for
//  so the target does not end up underneath it.
// ══════════════════════════════════════════════════════════════════
class EditScrollTargetTest extends TestCase
{
    /**
     * The screens with an edit-in-place form above their list.
     *
     * @return array<string, array{0: string}>
     */
    public static function editableScreens(): array
    {
        return [
            'sales'               => ['Sales'],
            'expenses'            => ['Expenses'],
            'inventory purchases' => ['InventoryPurchases'],
            'equipment purchases' => ['EquipmentPurchases'],
            'custodies'           => ['Custodies'],
        ];
    }

    private function source(string $screen): string
    {
        $path = resource_path("js/Pages/App/{$screen}/Index.vue");

        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('editableScreens')]
    public function test_the_screen_scrolls_to_its_form_not_the_document_top(string $screen): void
    {
        $source = $this->source($screen);

        $this->assertStringNotContainsString(
            'window.scrollTo({ top: 0',
            $source,
            "{$screen} still scrolls to the top of the page — anything added above the form leaves the form off-screen"
        );

        $this->assertStringContainsString(
            'scrollToForm(formCard)',
            $source,
            "{$screen} does not scroll to its form when Edit is pressed"
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('editableScreens')]
    public function test_the_screen_has_a_form_to_scroll_to(string $screen): void
    {
        $this->assertStringContainsString(
            'ref="formCard"',
            $this->source($screen),
            "{$screen} calls scrollToForm but nothing is marked as the form"
        );
    }

    /**
     * The steps say things like "press Record" — that is not what
     * somebody correcting an existing row is doing, and leaving the
     * panel up pushes the form further down the screen at the exact
     * moment they asked to see it.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('editableScreens')]
    public function test_the_how_to_panel_steps_aside_while_editing(string $screen): void
    {
        $source = $this->source($screen);

        $this->assertMatchesRegularExpression(
            '/<FormInstructions\s+v-if="!editing/',
            $source,
            "{$screen} keeps its how-to panel open while editing"
        );
    }

    /**
     * .app-header is sticky, so an element scrolled to its exact top
     * sits underneath it.
     */
    public function test_the_sticky_header_is_accounted_for(): void
    {
        $helper = file_get_contents(resource_path('js/Composables/useScrollToForm.js'));

        $this->assertStringContainsString(
            '.app-header',
            $helper,
            'The scroll does not measure the sticky header, so the form lands underneath it'
        );

        $this->assertStringContainsString(
            'requestAnimationFrame',
            $helper,
            'The position is measured before the edit-mode layout settles, so the scroll lands short'
        );
    }

    public function test_the_scroll_never_goes_negative(): void
    {
        $this->assertStringContainsString(
            'Math.max(top, 0)',
            file_get_contents(resource_path('js/Composables/useScrollToForm.js')),
            'A form near the top of a short page would compute a negative offset'
        );
    }
}
