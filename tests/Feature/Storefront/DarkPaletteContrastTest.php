<?php

namespace Tests\Feature\Storefront;

use App\Storefront\Cookies\Contrast;
use Tests\TestCase;

/**
 * The shipped dark palette (the `html[data-theme='dark']` block of `storefront.css`) keeps its own promises: every text step is readable (4.5:1) on the black page and on the cards, the status texts on their
 * boxes, the footer and the banner on theirs, the button text on the button, and the focus ring and the strong border stand out (3:1). The ratios are computed from the shipped values, never typed.
 */
class DarkPaletteContrastTest extends TestCase
{
    /** @return array<string, string> the block's custom properties, `var(--x)` references resolved */
    private function palette(): array
    {
        $css = (string) file_get_contents(base_path('resources/css/storefront.css'));
        $this->assertSame(1, preg_match("/html\\[data-theme='dark'\\] \\{(.*?)\\n\\}/s", $css, $block), 'the dark block');
        $this->assertGreaterThan(0, preg_match_all('/(--sf-[\w-]+):\s*([^;]+);/', $block[1], $found, PREG_SET_ORDER));

        $raw = [];
        foreach ($found as $match) {
            $raw[$match[1]] = trim($match[2]);
        }

        $resolve = function (string $value) use (&$resolve, $raw): string {
            return preg_match('/^var\((--sf-[\w-]+)\)$/', $value, $m) === 1 ? $resolve($raw[$m[1]] ?? $value) : $value;
        };

        return array_map($resolve, $raw);
    }

    private function colour(array $palette, string $token): string
    {
        $this->assertArrayHasKey($token, $palette, "the dark block defines {$token}");
        $this->assertTrue(Contrast::isHex($palette[$token]), "{$token} is a #rrggbb value ({$palette[$token]})");

        return $palette[$token];
    }

    public function test_every_text_token_is_readable_on_the_black_page_and_on_the_cards(): void
    {
        $palette = $this->palette();
        $surfaces = ['--sf-bg', '--sf-surface', '--sf-surface-2'];
        $texts = ['--sf-text', '--sf-muted', '--sf-n500', '--sf-n700', '--sf-n800', '--sf-accent', '--sf-error', '--sf-link'];

        foreach ($surfaces as $surface) {
            foreach ($texts as $text) {
                $ratio = Contrast::ratio($this->colour($palette, $text), $this->colour($palette, $surface));
                $this->assertGreaterThanOrEqual(Contrast::TEXT_MIN, $ratio, "{$text} on {$surface} is {$ratio}:1");
            }
        }

        // n400 (used for a struck-through label) reaches 4.5:1 on the page and on the cards (not asked of the lighter card step)
        foreach (['--sf-bg', '--sf-surface'] as $surface) {
            $ratio = Contrast::ratio($this->colour($palette, '--sf-n400'), $this->colour($palette, $surface));
            $this->assertGreaterThanOrEqual(Contrast::TEXT_MIN, $ratio, "--sf-n400 on {$surface} is {$ratio}:1");
        }
    }

    public function test_the_status_texts_the_footer_the_banner_and_the_button_text_are_readable_on_their_own_boxes(): void
    {
        $palette = $this->palette();
        $pairs = [
            ['--sf-status-error-text', '--sf-status-error-bg'], ['--sf-status-warn-text', '--sf-status-warn-bg'],
            ['--sf-status-info-text', '--sf-status-info-bg'], ['--sf-status-ok-text', '--sf-status-ok-bg'],
            ['--sf-footer-text', '--sf-footer-bg'], ['--sf-banner-text', '--sf-banner-bg'], ['--sf-on-primary', '--sf-primary'],
            ['--sf-header-text', '--sf-header-bg'],
        ];

        foreach ($pairs as [$text, $background]) {
            $ratio = Contrast::ratio($this->colour($palette, $text), $this->colour($palette, $background));
            $this->assertGreaterThanOrEqual(Contrast::TEXT_MIN, $ratio, "{$text} on {$background} is {$ratio}:1");
        }
    }

    public function test_a_status_text_is_readable_on_the_page_and_on_the_cards_too(): void
    {
        // a status text is also used WITHOUT its box (a line under a cart row, a promo badge): on the page, the card and the lighter card step
        $palette = $this->palette();

        foreach (['error', 'warn', 'info', 'ok'] as $family) {
            foreach (['--sf-bg', '--sf-surface', '--sf-surface-2'] as $surface) {
                $ratio = Contrast::ratio($this->colour($palette, "--sf-status-{$family}-text"), $this->colour($palette, $surface));
                $this->assertGreaterThanOrEqual(Contrast::TEXT_MIN, $ratio, "the {$family} text on {$surface} is {$ratio}:1");
            }
        }
    }

    public function test_the_checkout_labels_the_required_star_and_the_error_ring_are_readable_on_a_field(): void
    {
        // a floating label sits ON the field: the card's ground, or the error box's ground when the field is invalid; the star and the error text sit on the card
        $palette = $this->palette();
        $pairs = [
            ['--sf-n500', '--sf-surface'], ['--sf-n500', '--sf-status-error-bg'], ['--sf-n800', '--sf-surface'],
            ['--sf-muted', '--sf-surface'], ['--sf-muted', '--sf-status-error-bg'],
            ['--sf-error', '--sf-surface'], ['--sf-error', '--sf-status-error-bg'],
            ['--sf-link', '--sf-surface'], ['--sf-status-warn-text', '--sf-surface'],
        ];

        foreach ($pairs as [$text, $ground]) {
            $ratio = Contrast::ratio($this->colour($palette, $text), $this->colour($palette, $ground));
            $this->assertGreaterThanOrEqual(Contrast::TEXT_MIN, $ratio, "{$text} on {$ground} is {$ratio}:1");
        }

        // the focus ring and the error ring are drawn on the card and must stand out from it (3:1)
        foreach (['--sf-focus', '--sf-error'] as $ring) {
            $ratio = Contrast::ratio($this->colour($palette, $ring), $this->colour($palette, '--sf-surface'));
            $this->assertGreaterThanOrEqual(Contrast::COMPONENT_MIN, $ratio, "{$ring} on --sf-surface is {$ratio}:1");
        }
    }

    public function test_the_account_pill_and_the_destructive_button_are_readable_and_stand_out(): void
    {
        // the pill is the inverse: the text colour as its fill, the page colour as its label (a dark-only rule); the destructive button keeps its red text and its border takes that same colour
        $palette = $this->palette();

        $pill = Contrast::ratio($this->colour($palette, '--sf-bg'), $this->colour($palette, '--sf-text'));
        $this->assertGreaterThanOrEqual(Contrast::TEXT_MIN, $pill, "the pill's label on its fill is {$pill}:1");

        foreach (['--sf-surface', '--sf-surface-2'] as $ground) {
            $label = Contrast::ratio($this->colour($palette, '--sf-status-error-text'), $this->colour($palette, $ground));
            $this->assertGreaterThanOrEqual(Contrast::TEXT_MIN, $label, "the destructive label on {$ground} is {$label}:1");
        }

        // the pill stands out from the card it sits on (3:1)
        $edge = Contrast::ratio($this->colour($palette, '--sf-text'), $this->colour($palette, '--sf-surface'));
        $this->assertGreaterThanOrEqual(Contrast::COMPONENT_MIN, $edge, "the pill against the card is {$edge}:1");
    }

    public function test_the_cookie_dialog_its_three_equal_buttons_and_its_switch_are_readable(): void
    {
        // the dialog's ground is the card; its text is the ink and the scale; the three buttons are ONE class: the text colour as the ground, the page colour as the label (a merchant colour stays as set)
        $palette = $this->palette();

        foreach (['--sf-field-text', '--sf-n700', '--sf-muted'] as $text) {
            $ratio = Contrast::ratio($this->colour($palette, $text), $this->colour($palette, '--sf-surface'));
            $this->assertGreaterThanOrEqual(Contrast::TEXT_MIN, $ratio, "{$text} on the dialog is {$ratio}:1");
        }

        $label = Contrast::ratio($this->colour($palette, '--sf-bg'), $this->colour($palette, '--sf-text'));
        $this->assertGreaterThanOrEqual(Contrast::TEXT_MIN, $label, "a button's label on its ground is {$label}:1");

        $button = Contrast::ratio($this->colour($palette, '--sf-text'), $this->colour($palette, '--sf-surface'));
        $this->assertGreaterThanOrEqual(Contrast::COMPONENT_MIN, $button, "a button against the dialog is {$button}:1");

        // the switch: its border against the dialog, its knob against the (off) track, and the ON knob (the button's label colour) against the ON track (the button's ground)
        $this->assertGreaterThanOrEqual(Contrast::COMPONENT_MIN, Contrast::ratio($this->colour($palette, '--sf-n500'), $this->colour($palette, '--sf-surface')), 'the switch border');
        $this->assertGreaterThanOrEqual(Contrast::COMPONENT_MIN, Contrast::ratio($this->colour($palette, '--sf-n800'), $this->colour($palette, '--sf-surface-2')), 'the off knob on its track');
        $this->assertGreaterThanOrEqual(Contrast::COMPONENT_MIN, Contrast::ratio($this->colour($palette, '--sf-bg'), $this->colour($palette, '--sf-text')), 'the on knob on its track');
    }

    public function test_the_text_pinned_for_a_light_element_is_readable_on_that_light_element(): void
    {
        // the size strip over a card picture (white at 90% over the light frame) and, later, the gallery arrow: a white or very light ground in dark mode too
        $palette = $this->palette();

        foreach (['--sf-on-light', '--sf-on-light-muted'] as $text) {
            foreach (['#ffffff', '#f5f5f5'] as $ground) {
                $ratio = Contrast::ratio($this->colour($palette, $text), $ground);
                $this->assertGreaterThanOrEqual(Contrast::TEXT_MIN, $ratio, "{$text} on {$ground} is {$ratio}:1");
            }
        }
    }

    public function test_the_focus_ring_and_the_strong_border_stand_out_from_the_black_page(): void
    {
        $palette = $this->palette();

        foreach (['--sf-focus', '--sf-border-strong'] as $token) {
            foreach (['--sf-bg', '--sf-surface'] as $surface) {
                $ratio = Contrast::ratio($this->colour($palette, $token), $this->colour($palette, $surface));
                $this->assertGreaterThanOrEqual(Contrast::COMPONENT_MIN, $ratio, "{$token} against {$surface} is {$ratio}:1");
            }
        }
    }
}
