<?php

namespace App\Storefront\Cookies;

/**
 * CC-1: WCAG 2.x contrast between two `#rrggbb` colours (relative luminance, ratio (L1 + 0.05) / (L2 + 0.05)). Pure. The banner's button colour is checked with it on save (the text
 * colour inside the buttons is COMPUTED here, never typed: white or black, whichever contrasts more).
 */
final class Contrast
{
    public const TEXT_MIN = 4.5;

    public const AGAINST_DIALOG_MIN = 3.0;

    public const DIALOG_BACKGROUND = '#ffffff';

    public static function isHex(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-f]{6}$/D', $value) === 1;
    }

    public static function luminance(string $hex): float
    {
        $channels = array_map(static function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, str_split(substr($hex, 1), 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    public static function ratio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** @return array{color: string, ratio: float} the better of white and black as the text on $background, and its ratio */
    public static function bestText(string $background): array
    {
        $white = self::ratio('#ffffff', $background);
        $black = self::ratio('#000000', $background);

        return $white >= $black ? ['color' => '#ffffff', 'ratio' => $white] : ['color' => '#000000', 'ratio' => $black];
    }
}
