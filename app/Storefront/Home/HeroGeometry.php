<?php

namespace App\Storefront\Home;

/**
 * HP-6: the geometry of the «Hero слайдер» component. PURE functions (no query, no setting, no view): the closed lists of the component (the seconds each slide stays, the two proportions of the box), the box of
 * the hero on a desktop (1320px reference) and on a phone (358px reference, a 390px screen less the page padding) and the recommended picture size of the admin's hint (the box, rounded to the nearest 10, and the
 * 2× size for sharp screens). The tests pin these numbers; the CSS (`HP-6 hero slider` in storefront.css) draws the very same box with `aspect-ratio`.
 */
final class HeroGeometry
{
    /** The most slides a hero holds. */
    public const SLIDES_MAX = 6;

    /** The seconds each slide stays (closed list, as strings: the form's selects). */
    public const SECONDS = ['3', '4', '5', '6', '8', '10'];

    public const DEFAULT_SECONDS = '5';

    /** The proportion of the box from 48rem: width : height. */
    public const RATIOS = ['3:1', '21:9', '2:1', '16:9'];

    public const DEFAULT_RATIO = '21:9';

    /** The proportion of the box below 48rem. */
    public const PHONE_RATIOS = ['1:1', '4:5', '3:4', '4:3'];

    public const DEFAULT_PHONE_RATIO = '4:5';

    /** The content width the desktop hint is computed for: a 1440px screen with the default page padding (as HP-2 / HP-3 / HP-5). */
    public const REFERENCE_WIDTH = 1320;

    /** The content width the phone hint is computed for: a 390px screen minus the page padding (16px a side). */
    public const PHONE_REFERENCE_WIDTH = 358;

    /** The box of the hero for a width and a ratio («w:h»; a ratio outside both lists is the desktop default): [width, height]. @return array{0: float, 1: float} */
    public static function box(string $ratio, int $width = self::REFERENCE_WIDTH): array
    {
        $known = in_array($ratio, [...self::RATIOS, ...self::PHONE_RATIOS], true) ? $ratio : self::DEFAULT_RATIO;
        [$w, $h] = array_map('intval', explode(':', $known));

        return [(float) $width, (float) ($width * $h / $w)];
    }

    /**
     * What the admin shows for ONE box: its size, the size to aim at (rounded to the nearest 10) and the 2× size.
     *
     * @return array{width: float, height: float, rounded_width: int, rounded_height: int, double_width: int, double_height: int}
     */
    public static function hint(string $ratio, int $width): array
    {
        [$w, $h] = self::box($ratio, $width);

        return [
            'width' => $w, 'height' => $h,
            'rounded_width' => self::tens($w), 'rounded_height' => self::tens($h),
            'double_width' => self::tens($w * 2), 'double_height' => self::tens($h * 2),
        ];
    }

    /** The picture of a DESKTOP block: the 1320px reference and the desktop ratio. @return array{width: float, height: float, rounded_width: int, rounded_height: int, double_width: int, double_height: int} */
    public static function desktop(string $ratio): array
    {
        return self::hint(in_array($ratio, self::RATIOS, true) ? $ratio : self::DEFAULT_RATIO, self::REFERENCE_WIDTH);
    }

    /** The picture of a PHONE block: the 358px reference and the phone ratio. @return array{width: float, height: float, rounded_width: int, rounded_height: int, double_width: int, double_height: int} */
    public static function phone(string $ratio): array
    {
        return self::hint(in_array($ratio, self::PHONE_RATIOS, true) ? $ratio : self::DEFAULT_PHONE_RATIO, self::PHONE_REFERENCE_WIDTH);
    }

    /** Rounded to the nearest 10 (a size to aim at, not a decimal). */
    public static function tens(float $value): int
    {
        return (int) (round($value / 10) * 10);
    }

    /** The class of a proportion («21:9» → «21-9») for the CSS classes `sf-hs-r-…` / `sf-hs-rp-…`. */
    public static function slug(string $ratio): string
    {
        return str_replace(':', '-', $ratio);
    }
}
