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

    /** DM-1: the least contrast of a UI component (a button) against the page behind it (WCAG 2.x non-text contrast). */
    public const COMPONENT_MIN = 3.0;

    /** DM-1: the dark mode's page background (the `--sf-bg` of `html[data-theme='dark']`). */
    public const DARK_BACKGROUND = '#000000';

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

    /**
     * DM-1: the ONE check of the dark mode's button colour, used by the admin form and by the compiler path. The button must reach COMPONENT_MIN against the dark page, and the COMPUTED text colour
     * (white or black, whichever contrasts more; never typed) must reach TEXT_MIN against the button. `reason` is null when ok, else `format`, `button` or `text`.
     *
     * @return array{ok: bool, reason: ?string, textColour: ?string, buttonRatio: ?float, textRatio: ?float}
     */
    public static function darkButton(mixed $color): array
    {
        if (! is_string($color) || ! self::isHex(strtolower($color))) {
            return ['ok' => false, 'reason' => 'format', 'textColour' => null, 'buttonRatio' => null, 'textRatio' => null];
        }

        $color = strtolower($color);
        $buttonRatio = self::ratio($color, self::DARK_BACKGROUND);
        $best = self::bestText($color);
        $reason = $buttonRatio < self::COMPONENT_MIN ? 'button' : ($best['ratio'] < self::TEXT_MIN ? 'text' : null);

        return ['ok' => $reason === null, 'reason' => $reason, 'textColour' => $best['color'], 'buttonRatio' => $buttonRatio, 'textRatio' => $best['ratio']];
    }
}
