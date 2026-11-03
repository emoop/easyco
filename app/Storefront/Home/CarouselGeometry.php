<?php

namespace App\Storefront\Home;

/**
 * HP-5: the geometry of the «Карусел» component. PURE functions (no query, no setting, no view): the box of an item of a height-driven carousel (blocks, images, brands: box width = height × ratio), the card
 * of a product carousel (EXACTLY one cell of the product grid: the same columns and the same gap, so a card in the carousel is a card of the grid), the closed list of heights with its maximum step, the `sizes`
 * of the pictures and the recommended picture size of the admin's hint. The tests pin these numbers; the CSS (`Carousel (shared)` in storefront.css) draws the very same boxes.
 */
final class CarouselGeometry
{
    public const KINDS = ['products', 'blocks', 'images', 'brands'];

    /** The content width the desktop hint is computed for: a 1440px screen with the default page padding (as HP-2 / HP-3). */
    public const REFERENCE_WIDTH = 1320;

    /** The content width the phone hint is computed for: a 390px screen minus the page padding (16px a side). */
    public const PHONE_REFERENCE_WIDTH = 358;

    /** The candidate heights in px; the closed list is the part of it up to the MAXIMUM STEP (heights()). */
    public const STEPS = ['80', '100', '120', '160', '200', '240', '300', '360', '420', '480'];

    /**
     * The height of one product card of the category grid at 4 columns on a 1440px screen, MEASURED in the browser (HP-5 step 0: card 316.2 × 537.1px; image 421.6, brand 16.5, name 42, footer 41). No item
     * of a height-driven carousel is taller than a product card, so the maximum step is the largest step that is not above it.
     */
    public const CARD_HEIGHT = 537;

    public const DEFAULT_HEIGHT = '160';

    public const DEFAULT_HEIGHT_PHONE = '120';

    public const RATIOS = ['1:1', '4:5', '3:2', '2:1', '3:1', '16:9'];

    public const DEFAULT_RATIO = '3:2';

    public const GAPS = ImageGridGeometry::GAPS;

    public const DEFAULT_GAP = '16';

    public const RADII = ImageGridGeometry::RADII;

    public const DEFAULT_RADIUS = '0';

    public const COUNTS = ['8', '12', '16', '20', '24'];

    public const DEFAULT_COUNT = '12';

    /** The product card is one cell of the grid at these columns: the desktop's 4 (from 64rem), the tablet's 3 (from 48rem); the phone's is the grid's own (1 or 2). */
    public const CARD_COLUMNS = 4;

    public const CARD_COLUMNS_TABLET = 3;

    /** The proportion of the picture of a card (width : height), as the catalog's card. */
    public const CARD_IMAGE = [370, 444];

    /** The largest step of the heights that is not above the card's height. */
    public static function maxHeight(float $cardHeight = self::CARD_HEIGHT): int
    {
        $max = (int) self::STEPS[0];

        foreach (self::STEPS as $step) {
            (int) $step <= $cardHeight && $max = (int) $step;
        }

        return $max;
    }

    /** The closed list of heights, as strings. @return list<string> */
    public static function heights(): array
    {
        return array_values(array_filter(self::STEPS, static fn (string $step): bool => (int) $step <= self::maxHeight()));
    }

    /** The item box: [width, height] for a height and a ratio («w:h»; an unknown ratio is the default). @return array{0: float, 1: float} */
    public static function box(int $height, string $ratio): array
    {
        [$w, $h] = array_map('intval', explode(':', in_array($ratio, self::RATIOS, true) ? $ratio : self::DEFAULT_RATIO));

        return [(float) ($height * $w / $h), (float) $height];
    }

    /**
     * What the admin shows for a height-driven carousel: the box of the desktop height, the box of the phone height, each with the size to aim at (rounded to the nearest 10) and the 2× size for sharp screens.
     *
     * @return array{width: float, height: float, rounded_width: int, rounded_height: int, double_width: int, double_height: int}
     */
    public static function hint(int $height, string $ratio): array
    {
        [$width, $h] = self::box($height, $ratio);

        return [
            'width' => $width, 'height' => $h,
            'rounded_width' => self::tens($width), 'rounded_height' => self::tens($h),
            'double_width' => self::tens($width * 2), 'double_height' => self::tens($h * 2),
        ];
    }

    /**
     * ONE card of a product carousel: the width of one grid cell (the content width less the gaps, shared by the columns) and the height of its picture; the same sizes the hint of the admin shows.
     *
     * @return array{width: float, image_height: float, rounded_width: int, rounded_image_height: int, double_width: int, double_image_height: int}
     */
    public static function card(int $columns, int $gap, int $contentWidth = self::REFERENCE_WIDTH): array
    {
        $columns = max(1, min(5, $columns));
        $width = ($contentWidth - ($columns - 1) * $gap) / $columns;
        $image = $width * self::CARD_IMAGE[1] / self::CARD_IMAGE[0];

        return [
            'width' => $width, 'image_height' => $image,
            'rounded_width' => self::tens($width), 'rounded_image_height' => self::tens($image),
            'double_width' => self::tens($width * 2), 'double_image_height' => self::tens($image * 2),
        ];
    }

    /** Rounded to the nearest 10 (a size to aim at, not a decimal). */
    public static function tens(float $value): int
    {
        return (int) (round($value / 10) * 10);
    }

    /**
     * The `sizes` of ONE picture shown whole in the box (`contain`, never enlarged): its displayed width at the desktop height and at the phone height, from its own pixel size. With a `w` srcset the browser takes
     * `sizes` as the width the picture is DISPLAYED at, so a picture smaller than the box must say its own width (a 60px logo in a 180px box is 60px, not 180px) and a tall one the width its height allows.
     */
    public static function pictureSizes(int $height, int $heightPhone, string $ratio, int $width, int $pictureHeight): string
    {
        $shown = static function (int $boxHeight) use ($ratio, $width, $pictureHeight): int {
            [$boxWidth, $boxH] = self::box($boxHeight, $ratio);

            return (int) ceil($width * min(1.0, $boxWidth / max(1, $width), $boxH / max(1, $pictureHeight)));
        };

        return '(min-width: 48rem) '.$shown($height).'px, '.$shown($heightPhone).'px';
    }

    /**
     * The `sizes` of a picture in a height-driven box: from 48rem the box of the desktop height, below it the box of the phone height (px, from the validated integers and the closed ratio only).
     * A picture is never wider than its box, so the value is exact.
     */
    public static function sizes(int $height, int $heightPhone, string $ratio): string
    {
        return '(min-width: 48rem) '.(int) ceil(self::box($height, $ratio)[0]).'px, '.(int) ceil(self::box($heightPhone, $ratio)[0]).'px';
    }
}
