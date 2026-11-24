<?php

namespace Tests\Feature\Storefront;

use Tests\TestCase;

/**
 * DM-2 ratchet (dark-mode.md "Areas"): a raw-white BACKGROUND in the storefront stylesheet (`bg-white`, or a plain `background: #fff`) paints a white box on a dark page, so none may be ADDED. A new surface uses
 * `bg-surface` / `var(--sf-surface, #fff)`. The count may only go down, step by step, until only the allow-list is left. Judged on the source, not on a rendered page.
 *
 * Allow-list: the cookie dialog (`.sf-cc*`, until DM-4), and a line marked `dm-keeps-white` (a disc or a frame that must stay light; the payment-icon plates are in a Blade view, not here).
 */
class DarkModeRatchetTest extends TestCase
{
    /** Lower this in every DM-2 step; it must reach 0 (the allow-list only). Measured after DM-2b (DM-2a: 47). */
    private const MAX_RAW_WHITE_BACKGROUNDS = 33;

    /** @return list<string> "line: text" of every raw-white background outside the allow-list */
    private function rawWhiteBackgrounds(): array
    {
        $css = (string) file_get_contents(base_path('resources/css/storefront.css'));
        $marked = [];

        foreach (preg_split('/\R/', $css) ?: [] as $index => $line) {
            if (str_contains($line, 'dm-keeps-white')) {
                $marked[$index] = true;
            }
        }

        // comments out, the line numbers kept
        $css = (string) preg_replace_callback('~/\*.*?\*/~s', static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")), $css);

        $selector = '';
        $found = [];

        foreach (preg_split('/\R/', $css) ?: [] as $index => $line) {
            $trimmed = trim($line);

            if (str_ends_with($trimmed, '{') && ! str_starts_with($trimmed, '@')) {
                $selector = rtrim(substr($trimmed, 0, -1));
            }

            $white = preg_match('/\bbg-white(\/\d+)?\b/', $line) === 1
                || preg_match('/(^|[;{\s])background(-color)?\s*:\s*#fff(fff)?\s*;/i', $line) === 1;

            if (! $white || isset($marked[$index]) || str_starts_with($selector, '.sf-cc')) {
                continue;
            }

            $found[] = ($index + 1).': '.$selector.' → '.$trimmed;
        }

        return $found;
    }

    public function test_no_new_raw_white_background_is_added_to_the_storefront_stylesheet(): void
    {
        $found = $this->rawWhiteBackgrounds();

        $this->assertLessThanOrEqual(
            self::MAX_RAW_WHITE_BACKGROUNDS,
            count($found),
            "a new raw-white background (use `bg-surface` / `var(--sf-surface, #fff)`):\n".implode("\n", array_slice($found, 0, 60)),
        );
    }

    public function test_the_ratchet_sees_a_raw_white_background(): void
    {
        // the detector itself: both forms are caught, the surface token and a marked line are not
        $probe = static function (string $css): int {
            $count = 0;
            foreach (preg_split('/\R/', $css) ?: [] as $line) {
                if (str_contains($line, 'dm-keeps-white')) {
                    continue;
                }
                $count += (preg_match('/\bbg-white(\/\d+)?\b/', $line) === 1 || preg_match('/(^|[;{\s])background(-color)?\s*:\s*#fff(fff)?\s*;/i', $line) === 1) ? 1 : 0;
            }

            return $count;
        };

        $this->assertSame(1, $probe('@apply rounded bg-white p-4;'));
        $this->assertSame(1, $probe('    background: #fff;'));
        $this->assertSame(1, $probe('    background-color: #FFFFFF;'));
        $this->assertSame(0, $probe('    background: var(--sf-surface, #fff);'));
        $this->assertSame(0, $probe('@apply bg-surface; /* dm-keeps-white */'));
        $this->assertSame(0, $probe('    background: transparent;'));
    }
}
