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
        $texts = ['--sf-text', '--sf-muted', '--sf-n500', '--sf-n700', '--sf-n800', '--sf-accent', '--sf-error'];

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
