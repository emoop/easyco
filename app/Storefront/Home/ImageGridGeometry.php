<?php

namespace App\Storefront\Home;

/**
 * HP-2: the geometry of the «Решетка от снимки» component. ONE pure function (no query, no setting, no view): from the scheme, the proportion of the whole grid, the gap between the cells and the width of
 * the content area it computes where every cell is, how big it is in pixels and what its proportion is. The admin's size diagram AND the tests use it; the CSS of the shop (`HP-2 image grid` in storefront.css)
 * draws the very same grid with named areas, equal columns, equal rows and the same gap, so the numbers are what a cell really is.
 *
 * A scheme is a grid of C equal columns and R equal rows; a cell is `[column, row, columns spanned, rows spanned]` (1-based). The cells are named a, b, c, … in reading order.
 */
final class ImageGridGeometry
{
    /** The content width the sizes are computed for: a 1440px screen with the default page padding (the content is 1324.8px there; the owner chose the round 1320). */
    public const REFERENCE_WIDTH = 1320;

    /** @var array<string, array{columns: int, rows: int, cells: array<string, array{0: int, 1: int, 2: int, 3: int}>}> */
    public const SCHEMES = [
        'one' => ['columns' => 1, 'rows' => 1, 'cells' => ['a' => [1, 1, 1, 1]]],
        'two' => ['columns' => 2, 'rows' => 1, 'cells' => ['a' => [1, 1, 1, 1], 'b' => [2, 1, 1, 1]]],
        'three' => ['columns' => 3, 'rows' => 1, 'cells' => ['a' => [1, 1, 1, 1], 'b' => [2, 1, 1, 1], 'c' => [3, 1, 1, 1]]],
        'four' => ['columns' => 2, 'rows' => 2, 'cells' => ['a' => [1, 1, 1, 1], 'b' => [2, 1, 1, 1], 'c' => [1, 2, 1, 1], 'd' => [2, 2, 1, 1]]],
        'six' => ['columns' => 3, 'rows' => 2, 'cells' => ['a' => [1, 1, 1, 1], 'b' => [2, 1, 1, 1], 'c' => [3, 1, 1, 1], 'd' => [1, 2, 1, 1], 'e' => [2, 2, 1, 1], 'f' => [3, 2, 1, 1]]],
        // the owner's sketch: left two cells stacked (3 rows each), the middle one tall cell (6 rows), the right three stacked (2 rows each)
        'uneven-a' => ['columns' => 3, 'rows' => 6, 'cells' => ['a' => [1, 1, 1, 3], 'b' => [1, 4, 1, 3], 'c' => [2, 1, 1, 6], 'd' => [3, 1, 1, 2], 'e' => [3, 3, 1, 2], 'f' => [3, 5, 1, 2]]],
        // one big cell on the left (2/3 of the width, the full height), two stacked on the right
        'uneven-b' => ['columns' => 3, 'rows' => 2, 'cells' => ['a' => [1, 1, 2, 2], 'b' => [3, 1, 1, 1], 'c' => [3, 2, 1, 1]]],
        // two stacked on the left, one big cell on the right (2/3 of the width, the full height)
        'uneven-c' => ['columns' => 3, 'rows' => 2, 'cells' => ['a' => [1, 1, 1, 1], 'b' => [1, 2, 1, 1], 'c' => [2, 1, 2, 2]]],
    ];

    /** The proportion of the whole grid: width : height. */
    public const RATIOS = ['16:9', '3:2', '4:3'];

    public const DEFAULT_SCHEME = 'three';

    public const DEFAULT_RATIO = '3:2';

    /** The gap between the cells in px, as strings (the closed list of the form). */
    public const GAPS = ['0', '4', '8', '12', '16', '20', '24', '32'];

    public const DEFAULT_GAP = '16';

    public const PHONE_DEFAULT_GAP = '8';

    public const RADII = ['0', '4', '8', '12', '16', '24'];

    public const DEFAULT_RADIUS = '0';

    /** The proportions a cell is named by (the nearest one; a cell that is none of them is named by its two rounded sizes). */
    private const NAMES = ['1:1', '4:3', '3:2', '16:9', '2:1', '3:1', '3:4', '2:3', '9:16', '1:2', '1:3'];

    /** @return list<string> the cell names of a scheme in reading order (empty for an unknown scheme) */
    public static function names(string $scheme): array
    {
        return array_keys(self::SCHEMES[$scheme]['cells'] ?? []);
    }

    /**
     * Every cell of the grid with its size in pixels. A cell never exceeds the box; with a gap the columns and the rows are what is left after the gaps, shared equally.
     *
     * @return list<array{name: string, column: int, row: int, columns: int, rows: int, width: float, height: float, rounded_width: int, rounded_height: int, label: string}>
     */
    public static function cells(string $scheme, string $ratio, int $gap, int $contentWidth = self::REFERENCE_WIDTH): array
    {
        $spec = self::SCHEMES[$scheme] ?? null;
        [$rw, $rh] = array_map('intval', explode(':', in_array($ratio, self::RATIOS, true) ? $ratio : self::DEFAULT_RATIO));

        if ($spec === null) {
            return [];
        }

        $boxWidth = (float) $contentWidth;
        $boxHeight = $boxWidth * $rh / $rw;
        $column = ($boxWidth - ($spec['columns'] - 1) * $gap) / $spec['columns'];
        $row = ($boxHeight - ($spec['rows'] - 1) * $gap) / $spec['rows'];
        $out = [];

        foreach ($spec['cells'] as $name => [$c, $r, $cs, $rs]) {
            $w = $cs * $column + ($cs - 1) * $gap;
            $h = $rs * $row + ($rs - 1) * $gap;
            $out[] = [
                'name' => $name, 'column' => $c, 'row' => $r, 'columns' => $cs, 'rows' => $rs,
                'width' => $w, 'height' => $h,
                'rounded_width' => self::tens($w), 'rounded_height' => self::tens($h),
                'label' => self::label($w, $h),
            ];
        }

        return $out;
    }

    /** The box of the whole grid: [width, height]. @return array{0: float, 1: float} */
    public static function box(string $ratio, int $contentWidth = self::REFERENCE_WIDTH): array
    {
        [$rw, $rh] = array_map('intval', explode(':', in_array($ratio, self::RATIOS, true) ? $ratio : self::DEFAULT_RATIO));

        return [(float) $contentWidth, $contentWidth * $rh / $rw];
    }

    /** Rounded to the nearest 10 (the merchant needs a size to aim at, not a decimal). */
    public static function tens(float $value): int
    {
        return (int) (round($value / 10) * 10);
    }

    /** The proportion of a cell as a short name: the nearest of the usual ones («4:3»), or the two rounded sizes. */
    public static function label(float $width, float $height): string
    {
        if ($width <= 0 || $height <= 0) {
            return '';
        }

        $best = null;
        $distance = INF;

        foreach (self::NAMES as $name) {
            [$a, $b] = array_map('intval', explode(':', $name));
            $d = abs(log(($width / $height) / ($a / $b)));

            if ($d < $distance) {
                $distance = $d;
                $best = $name;
            }
        }

        // within 4% of a usual proportion: that name; else the sizes
        return $distance <= 0.04 ? (string) $best : self::tens($width).':'.self::tens($height);
    }
}
