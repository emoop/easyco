<?php

namespace Tests\Unit\Storefront;

use App\Storefront\Reader\ImageSetBuilder;
use PHPUnit\Framework\TestCase;

/**
 * SIZES-1: `ImageSetBuilder::cardSizes()` — the `sizes` of a card in the category grid from the published column counts. The value is never SMALLER than the real card width (the browser never picks a blurry
 * image): the test evaluates the string at many viewport widths and compares it with the card the stylesheet really draws (container = 100vw − 2 × the page padding, a `clamp` the merchant can change; gap 12px
 * below 40rem and 16px from it; phone columns below 48rem, 3 from 48rem, the desktop's from 64rem).
 */
class CardSizesFormulaTest extends TestCase
{
    /** What the browser computes from a `sizes` string at a viewport width: the first matching `(min-width: Xrem)` entry, else the last. */
    private function evaluate(string $sizes, float $viewport): float
    {
        foreach (explode(', ', $sizes) as $entry) {
            preg_match('/^(?:\(min-width: ([0-9.]+)rem\) )?calc\(\(100vw - ([0-9.]+)rem\) \/ (\d)\)$/', $entry, $m);
            $this->assertNotEmpty($m, "the entry has the expected shape: {$entry}");

            if (($m[1] ?? '') === '' || $viewport >= (float) $m[1] * 16) {
                return ($viewport - (float) $m[2] * 16) / (int) $m[3];
            }
        }

        $this->fail('no entry matched');
    }

    /** The card the stylesheet draws: pad = clamp(min, vw%, max) a side, columns by breakpoint, gap 12px / 16px. */
    private function realCard(float $viewport, int $cols, int $phoneCols, float $padMin, float $padVw, float $padMax, int $gapX = 16): float
    {
        $pad = min($padMax, max($padMin, $viewport * $padVw / 100));
        $columns = $viewport >= 1024 ? $cols : ($viewport >= 768 ? 3 : $phoneCols);
        // since GAP-1 the grid's column gap is the published one at EVERY width (it was 12px below 40rem before)
        $gap = $gapX;

        return ($viewport - 2 * $pad - ($columns - 1) * $gap) / $columns;
    }

    /** @return array<string, array{0: int, 1: int}> */
    public static function columns(): array
    {
        $cases = [];

        foreach ([3, 4, 5] as $cols) {
            foreach ([1, 2] as $phone) {
                $cases["desktop {$cols}, phone {$phone}"] = [$cols, $phone];
            }
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('columns')]
    public function test_the_value_is_never_smaller_than_the_real_card_at_any_width_or_page_padding(int $cols, int $phone): void
    {
        $sizes = ImageSetBuilder::cardSizes($cols, $phone);

        // the design screen's range: min 8–48px, vw 0–8, max 16–320px; the shop's default (16, 4vw, 160) in the middle
        foreach ([[16.0, 4.0, 160.0], [8.0, 0.0, 16.0], [8.0, 0.0, 8.0], [48.0, 8.0, 320.0], [8.0, 8.0, 320.0]] as [$min, $vw, $max]) {
            foreach (range(320, 2560, 16) as $viewport) {
                $this->assertGreaterThanOrEqual(
                    $this->realCard($viewport, $cols, $phone, min($min, $max), $vw, max($min, $max)) - 0.001,
                    $this->evaluate($sizes, (float) $viewport),
                    "{$cols}/{$phone} at {$viewport}px with the page padding ({$min}, {$vw}vw, {$max})",
                );
            }
        }
    }

    /** @return array<string, array{0: int}> */
    public static function gaps(): array
    {
        return array_combine(array_map(static fn (int $g): string => "gap {$g}px", [0, 4, 8, 12, 16, 20, 24, 32]), array_map(static fn (int $g): array => [$g], [0, 4, 8, 12, 16, 20, 24, 32]));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('gaps')]
    public function test_the_value_is_never_smaller_than_the_real_card_with_any_published_gap(int $gapX): void
    {
        foreach (self::columns() as [$cols, $phone]) {
            $sizes = ImageSetBuilder::cardSizes($cols, $phone, $gapX);

            foreach ([[16.0, 4.0, 160.0], [8.0, 0.0, 8.0], [48.0, 8.0, 320.0]] as [$min, $vw, $max]) {
                foreach (range(320, 2560, 16) as $viewport) {
                    $this->assertGreaterThanOrEqual(
                        $this->realCard($viewport, $cols, $phone, $min, $vw, $max, $gapX) - 0.001,
                        $this->evaluate($sizes, (float) $viewport),
                        "{$cols}/{$phone}, gap {$gapX}px at {$viewport}px",
                    );
                }
            }
        }
    }

    public function test_the_default_gap_is_todays_exact_string_and_a_value_off_the_list_is_the_smallest_gap(): void
    {
        $this->assertSame(ImageSetBuilder::cardSizes(4, 2), ImageSetBuilder::cardSizes(4, 2, 16));
        $this->assertSame('(min-width: 64rem) calc((100vw - 4rem) / 4), (min-width: 48rem) calc((100vw - 3rem) / 3), (min-width: 40rem) calc((100vw - 2rem) / 2), calc((100vw - 1.75rem) / 2)', ImageSetBuilder::cardSizes(4, 2, 16));

        // not on the closed list: the unsure value is the SMALLEST gap (a larger gap makes the card narrower, so 0 can never be too small)
        foreach ([5, 17, -4, 99] as $bad) {
            $this->assertSame(ImageSetBuilder::cardSizes(4, 2, 0), ImageSetBuilder::cardSizes(4, 2, $bad), (string) $bad);
        }
    }

    public function test_the_value_for_each_desktop_column_count(): void
    {
        $this->assertSame('(min-width: 64rem) calc((100vw - 3rem) / 3), (min-width: 48rem) calc((100vw - 3rem) / 3), (min-width: 40rem) calc((100vw - 2rem) / 2), calc((100vw - 1.75rem) / 2)', ImageSetBuilder::cardSizes(3, 2));
        $this->assertSame('(min-width: 64rem) calc((100vw - 4rem) / 4), (min-width: 48rem) calc((100vw - 3rem) / 3), (min-width: 40rem) calc((100vw - 2rem) / 2), calc((100vw - 1.75rem) / 2)', ImageSetBuilder::cardSizes(4, 2));
        $this->assertSame('(min-width: 64rem) calc((100vw - 5rem) / 5), (min-width: 48rem) calc((100vw - 3rem) / 3), (min-width: 40rem) calc((100vw - 2rem) / 2), calc((100vw - 1.75rem) / 2)', ImageSetBuilder::cardSizes(5, 2));
    }

    public function test_the_phone_uses_its_own_column_count(): void
    {
        $this->assertStringEndsWith('(min-width: 40rem) calc((100vw - 1rem) / 1), calc((100vw - 1rem) / 1)', ImageSetBuilder::cardSizes(4, 1));
        $this->assertStringEndsWith('(min-width: 40rem) calc((100vw - 2rem) / 2), calc((100vw - 1.75rem) / 2)', ImageSetBuilder::cardSizes(4, 2));
    }

    public function test_the_default_is_the_four_and_two_columns_and_a_value_outside_the_lists_is_the_default(): void
    {
        $default = ImageSetBuilder::cardSizes();

        $this->assertSame($default, ImageSetBuilder::cardSizes(4, 2));

        foreach ([[2, 2], [6, 2], [0, 2], [-1, 2], [4, 0], [4, 3], [999, 999]] as [$cols, $phone]) {
            $this->assertSame($default, ImageSetBuilder::cardSizes($cols, $phone), "{$cols}/{$phone} is not on the closed lists: the default");
        }
    }

    public function test_at_the_default_the_desktop_value_is_at_least_the_old_twenty_vw_everywhere_it_applies(): void
    {
        foreach (range(1024, 2560, 16) as $viewport) {
            $this->assertGreaterThanOrEqual($viewport * 0.20, $this->evaluate(ImageSetBuilder::cardSizes(4, 2), (float) $viewport));
        }
    }

    public function test_only_digits_and_fixed_words_reach_the_value(): void
    {
        foreach (self::columns() as [$cols, $phone]) {
            $this->assertMatchesRegularExpression('/^[a-z0-9 .(),\/:-]+$/', ImageSetBuilder::cardSizes($cols, $phone));
        }
    }
}
