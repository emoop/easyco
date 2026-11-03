<?php

namespace Tests\Unit\Storefront\Home;

use App\Storefront\Home\TileGeometry as T;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * HP-3: the pure functions behind the «Плочки» size hint and the `sizes` of a tile's picture. The tile sizes add up to the content width with the gaps, the shape's proportion holds, and the `sizes` value is never
 * SMALLER than the real tile (the browser would pick a blurry picture): checked numerically at several screen widths with the real page padding clamp(1rem, 4vw, 10rem).
 */
class TileGeometryTest extends TestCase
{
    /** @return array<string, array{0: int, 1: int, 2: string}> */
    public static function grids(): array
    {
        $out = [];

        foreach ([1, 2, 3, 4, 5] as $columns) {
            foreach ([0, 32] as $gap) {
                foreach (array_keys(T::SHAPES) as $shape) {
                    $out["{$columns} columns gap {$gap} {$shape}"] = [$columns, $gap, $shape];
                }
            }
        }

        return $out;
    }

    #[DataProvider('grids')]
    public function test_the_tiles_and_the_gaps_add_up_to_the_content_and_the_shape_holds(int $columns, int $gap, string $shape): void
    {
        $tile = T::tile($columns, $gap, $shape);
        [$w, $h] = T::SHAPES[$shape];

        $this->assertEqualsWithDelta(T::REFERENCE_WIDTH, $tile['width'] * $columns + ($columns - 1) * $gap, 0.001, 'the row is the content width');
        $this->assertEqualsWithDelta($w / $h, $tile['width'] / $tile['height'], 0.0001, 'the shape ratio');
        $this->assertSame((int) (round($tile['width'] / 10) * 10), $tile['rounded_width']);
        $this->assertSame((int) (round($tile['width'] * 2 / 10) * 10), $tile['double_width']);
    }

    public function test_the_numbers_of_the_default_row_and_of_a_phone(): void
    {
        $four = T::tile(4, 16, 'square');
        $this->assertEqualsWithDelta((1320 - 48) / 4, $four['width'], 0.001);
        $this->assertSame(320, $four['rounded_width']);
        $this->assertSame(640, $four['double_width']);

        $portrait = T::tile(4, 16, 'portrait');
        $this->assertEqualsWithDelta(318 * 5 / 4, $portrait['height'], 0.001);

        $phone = T::tile(2, 8, 'landscape', T::PHONE_REFERENCE_WIDTH);
        $this->assertEqualsWithDelta((358 - 8) / 2, $phone['width'], 0.001);
        $this->assertEqualsWithDelta(175 * 3 / 4, $phone['height'], 0.001);
    }

    public function test_the_gap_is_taken_off_the_width(): void
    {
        $this->assertGreaterThan(T::tile(5, 32, 'square')['width'], T::tile(5, 0, 'square')['width']);
        $this->assertEqualsWithDelta(264.0, T::tile(5, 0, 'square')['width'], 0.001);
        $this->assertEqualsWithDelta(238.4, T::tile(5, 32, 'square')['width'], 0.001);
    }

    public function test_the_attributes_are_the_proportion_in_whole_pixels(): void
    {
        $this->assertSame([400, 500], T::attributes('portrait'));
        $this->assertSame([100, 100], T::attributes('square'));
        $this->assertSame([400, 300], T::attributes('landscape'));
        $this->assertSame([100, 100], T::attributes('round'), 'an unknown shape is the default one');
    }

    /** The value of the `sizes` for a screen width, in px: the first condition that holds, evaluated. */
    private function sizeAt(string $sizes, int $vw): float
    {
        foreach (preg_split('/, (?=\(min-width|calc)/', $sizes) as $entry) {
            if (preg_match('/^\(min-width: ([0-9.]+)rem\) (.*)$/', $entry, $m) === 1) {
                if ($vw >= (float) $m[1] * 16) {
                    return $this->calc($m[2], $vw);
                }

                continue;
            }

            return $this->calc($entry, $vw);
        }

        $this->fail('no size for '.$vw);
    }

    private function calc(string $calc, int $vw): float
    {
        $this->assertSame(1, preg_match('/^calc\(\(100vw - ([0-9.]+)rem\) \/ ([0-9]+)\)$/', $calc, $m), $calc);

        return ($vw - (float) $m[1] * 16) / (int) $m[2];
    }

    /** @return array<string, array{0: int, 1: int, 2: int}> */
    public static function rows(): array
    {
        $out = [];

        foreach ([3, 4, 5] as $columns) {
            foreach ([1, 2] as $phone) {
                foreach ([0, 32] as $gap) {
                    $out["{$columns}/{$phone} gap {$gap}"] = [$columns, $phone, $gap];
                }
            }
        }

        return $out;
    }

    #[DataProvider('rows')]
    public function test_the_sizes_are_never_smaller_than_the_real_tile(int $columns, int $phone, int $gap): void
    {
        $sizes = T::sizes($columns, $phone, $gap);

        foreach ([390, 640, 768, 1000, 1024, 1100, 1440, 1920] as $vw) {
            $padding = min(160.0, max(16.0, 0.04 * $vw));
            $n = $vw >= 1024 ? $columns : ($vw >= 768 ? min($columns, 3) : $phone);
            $real = ($vw - 2 * $padding - ($n - 1) * $gap) / $n;

            $this->assertGreaterThanOrEqual($real - 0.01, $this->sizeAt($sizes, $vw), "{$vw}px, {$columns}/{$phone} columns, gap {$gap}");
        }
    }

    public function test_sizes_of_a_value_outside_the_lists_are_the_defaults_and_only_numbers_reach_the_string(): void
    {
        $this->assertSame(T::sizes(4, 2, 0), T::sizes(9, 7, 13), 'columns outside the lists are 4 / 2; an unknown gap is 0');
        $this->assertMatchesRegularExpression('/^\(min-width: 64rem\) calc\(\(100vw - [0-9.]+rem\) \/ [345]\), \(min-width: 48rem\) calc\(\(100vw - [0-9.]+rem\) \/ [345]\), calc\(\(100vw - [0-9.]+rem\) \/ [12]\)$/', T::sizes(5, 1, 24));
    }
}
