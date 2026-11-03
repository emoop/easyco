<?php

namespace App\Storefront\Home;

use App\Storefront\PageDesign\PageDesign;

/**
 * HP-3: the geometry of the «Плочки» component. PURE functions (no query, no setting, no view): the size of a tile's picture from the columns, the gap and the shape, for the size hint of the admin
 * (the SAME numbers the tests pin), and the `sizes` attribute of a tile's picture from the published columns and gap (the pattern of ImageSetBuilder::cardSizes, SIZES-1).
 *
 * A row of N tiles is N equal columns with a gap between them: a tile is (content − (N − 1) × gap) / N wide; its picture has the shape's proportion (width : height).
 */
final class TileGeometry
{
    /** The content width the desktop hint is computed for: a 1440px screen with the default page padding (as HP-2). */
    public const REFERENCE_WIDTH = 1320;

    /** The content width the phone hint is computed for: a 390px screen minus the page padding (16px a side). */
    public const PHONE_REFERENCE_WIDTH = 358;

    public const COLUMNS = ['3', '4', '5'];

    public const DEFAULT_COLUMNS = '4';

    public const PHONE_COLUMNS = ['1', '2'];

    public const DEFAULT_PHONE_COLUMNS = '2';

    /** The gap between the tiles in px: the very list of the product grid (GAP-1). */
    public const GAPS = PageDesign::GRID_GAPS;

    public const DEFAULT_GAP = '16';

    /** shape => [width, height] of its proportion. */
    public const SHAPES = ['portrait' => [4, 5], 'square' => [1, 1], 'landscape' => [4, 3]];

    public const DEFAULT_SHAPE = 'square';

    /** The corner radius of the picture in percent of its size (50 on a square is a circle). */
    public const RADII = ['0', '5', '10', '20', '30', '40', '50', '60'];

    public const DEFAULT_RADIUS = '0';

    /** caption size key => px */
    public const CAPTIONS = ['s' => 13, 'm' => 15, 'l' => 18];

    public const DEFAULT_CAPTION = 'm';

    /**
     * The picture of ONE tile for a content width: its size, the size rounded to the nearest 10 and the double for sharp screens.
     *
     * @return array{width: float, height: float, rounded_width: int, rounded_height: int, double_width: int, double_height: int}
     */
    public static function tile(int $columns, int $gap, string $shape, int $contentWidth = self::REFERENCE_WIDTH): array
    {
        $columns = max(1, min(5, $columns));
        [$w, $h] = self::SHAPES[$shape] ?? self::SHAPES[self::DEFAULT_SHAPE];

        $width = ($contentWidth - ($columns - 1) * $gap) / $columns;
        $height = $width * $h / $w;

        return [
            'width' => $width, 'height' => $height,
            'rounded_width' => self::tens($width), 'rounded_height' => self::tens($height),
            'double_width' => self::tens($width * 2), 'double_height' => self::tens($height * 2),
        ];
    }

    /** Rounded to the nearest 10 (a size to aim at, not a decimal). */
    public static function tens(float $value): int
    {
        return (int) (round($value / 10) * 10);
    }

    /** The `width` and `height` attributes of a picture of a shape (its proportion in whole pixels: no layout shift before the picture loads). @return array{0: int, 1: int} */
    public static function attributes(string $shape): array
    {
        [$w, $h] = self::SHAPES[$shape] ?? self::SHAPES[self::DEFAULT_SHAPE];

        return [$w * 100, $h * 100];
    }

    /**
     * The `sizes` of a tile's picture, from the PUBLISHED columns (desktop 3–5, phone 1–2; the tablet shows min(desktop, 3)) and gap. Pure: only the validated integers reach the string.
     *
     * Where it comes from: the content is 100vw minus 2 × the page padding; the columns are the phone's below 48rem, the tablet's (min(desktop, 3)) from 48rem, the desktop's from 64rem; a tile is
     * (content − (n − 1) gaps) / n. The page padding is a design setting, so the formula takes the SMALLEST padding the Design screen allows (0.5rem a side: 1rem in all): the content is never narrower
     * than that, so the value is never SMALLER than the real tile (the browser would otherwise pick a blurry picture).
     */
    public static function sizes(int $columns = 4, int $columnsPhone = 2, int $gap = 16): string
    {
        $columns = in_array((string) $columns, self::COLUMNS, true) ? $columns : (int) self::DEFAULT_COLUMNS;
        $columnsPhone = in_array((string) $columnsPhone, self::PHONE_COLUMNS, true) ? $columnsPhone : (int) self::DEFAULT_PHONE_COLUMNS;
        // a larger gap makes the tile NARROWER, so the unsure value is the smallest gap (0), never a larger one
        $gap = in_array((string) $gap, self::GAPS, true) ? $gap : 0;
        $rem = $gap / 16;
        $tablet = min($columns, 3);

        $calc = static fn (float $spare, int $n): string => 'calc((100vw - '.rtrim(rtrim(number_format($spare, 4, '.', ''), '0'), '.').'rem) / '.$n.')';

        return implode(', ', [
            '(min-width: 64rem) '.$calc(1 + ($columns - 1) * $rem, $columns),
            '(min-width: 48rem) '.$calc(1 + ($tablet - 1) * $rem, $tablet),
            $calc(1 + ($columnsPhone - 1) * $rem, $columnsPhone),
        ]);
    }
}
