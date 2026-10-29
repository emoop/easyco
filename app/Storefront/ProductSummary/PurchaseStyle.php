<?php

namespace App\Storefront\ProductSummary;

use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;

/**
 * PB-1: the look of the product page's add-to-cart button («Покупка», right column). The settings of the `purchase` component (ProductSummaryCatalog) are VALIDATED by ProductSummaryValidator; this class turns
 * the validated ones into a CLOSED list of CSS variables (`--sf-buy-*`) and re-checks every variable the same way the token compiler does (the pattern of PageDesign::isValidVar). Only a value that differs from
 * the default is emitted, so a shop that never touched the button gets the same stylesheet it always had. Nothing a person types reaches CSS as a string: only `#rrggbb`, `Npx` with N in 1..4, `currentcolor` and the one radius reference
 * are ever written.
 */
final class PurchaseStyle
{
    public const VARS = ['--sf-buy-bg', '--sf-buy-bg-hover', '--sf-buy-color', '--sf-buy-border', '--sf-buy-bw', '--sf-buy-radius', '--sf-buy-off'];

    /** LK-1: what `rounded` switches on: the shop's own button radius (the default look is square). */
    public const ROUNDED = 'var(--sf-r-btn, var(--radius-md))';

    /** The three texts and how long each may be. */
    public const TEXTS = ['label' => 30, 'label_choose' => 30, 'label_sold_out' => 30];

    /**
     * The variables for the settings of ONE `purchase` component (a validated settings array); only what differs from the defaults.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, string>
     */
    public static function vars(array $settings): array
    {
        $vars = [];
        $bg = self::hex($settings['bg'] ?? null);
        $color = self::hex($settings['color'] ?? null);
        $borderColor = self::hex($settings['border_color'] ?? null);
        $width = is_int($settings['border_w'] ?? null) ? $settings['border_w'] : 0;

        if ($bg !== null) {
            $vars['--sf-buy-bg'] = $bg;
            $vars['--sf-buy-bg-hover'] = self::hover($bg);
        }

        $color !== null && $vars['--sf-buy-color'] = $color;

        if ($width >= 1 && $width <= 4) {
            $vars['--sf-buy-bw'] = $width.'px';
            // no colour of its own = the text colour of the button, whatever it is
            $vars['--sf-buy-border'] = $borderColor ?? 'currentcolor';
        }

        // LK-1: square is the default and emits nothing; `true` hands the button the shop's radius variable
        ($settings['rounded'] ?? false) === true && $vars['--sf-buy-radius'] = self::ROUNDED;

        // a button with its own colours dims when it cannot be pressed (the shop's grey disabled look is for the shop's own colours)
        ($bg !== null || $color !== null) && $vars['--sf-buy-off'] = '0.5';

        return $vars;
    }

    /** Is this one of the variables the compiler may write, with a value of the exact form it is written in? The closed list; anything else is refused. */
    public static function isValidVar(string $variable, mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        return match ($variable) {
            '--sf-buy-bg', '--sf-buy-bg-hover', '--sf-buy-color' => preg_match('/\A#[0-9a-f]{6}\z/', $value) === 1,
            '--sf-buy-border' => preg_match('/\A(?:#[0-9a-f]{6}|currentcolor)\z/', $value) === 1,
            '--sf-buy-bw' => preg_match('/\A[1-4]px\z/', $value) === 1,
            '--sf-buy-radius' => $value === self::ROUNDED,
            '--sf-buy-off' => $value === '0.5',
            default => false,
        };
    }

    /**
     * The variables of the PUBLISHED layout (validated again; a layout that does not validate has none). Used by the token compiler.
     *
     * @return array<string, string>
     */
    public static function storedVars(SiteSettingsRepository $settings): array
    {
        $json = $settings->get(AppearanceSettings::SUMMARY_PUBLISHED_KEY);
        $layout = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($layout)) {
            return [];
        }

        [$clean, $errors] = ProductSummaryValidator::validate($layout);

        if ($errors !== []) {
            return [];
        }

        foreach ($clean['components'] as $component) {
            if ($component['type'] === 'purchase') {
                return self::vars($component['settings']);
            }
        }

        return [];
    }

    /**
     * The hover background: a fixed step darker, or lighter when the background is already dark (relative luminance under 0.18). Always a `#rrggbb`.
     */
    public static function hover(string $hex): string
    {
        $dark = AppearanceSettings::contrastRatio($hex, '#000000') < 5.0;
        $channels = array_map(static fn (string $part): int => (int) hexdec($part), [substr($hex, 1, 2), substr($hex, 3, 2), substr($hex, 5, 2)]);
        $mixed = array_map(static fn (int $c): int => (int) round($dark ? $c + (255 - $c) * 0.18 : $c * 0.85), $channels);

        return sprintf('#%02x%02x%02x', $mixed[0], $mixed[1], $mixed[2]);
    }

    /**
     * ADMIN PREVIEW ONLY (the Design screen): the inline style of one state of the button, built from VALIDATED values (a colour is `#rrggbb` or absent, the width 0..4). The storefront never uses it.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function previewStyle(array $settings, string $state): string
    {
        $vars = self::vars($settings);
        $bg = $vars['--sf-buy-bg'] ?? '#171717';
        $color = $vars['--sf-buy-color'] ?? '#ffffff';
        $d = [];

        if ($state === 'hover') {
            $bg = $vars['--sf-buy-bg-hover'] ?? self::hover($bg);
        }

        $d[] = 'background:'.$bg;
        $d[] = 'color:'.$color;

        if (isset($vars['--sf-buy-bw'])) {
            $d[] = 'box-shadow:inset 0 0 0 '.$vars['--sf-buy-bw'].' '.$vars['--sf-buy-border'];
        }

        $d[] = 'border-radius:'.(isset($vars['--sf-buy-radius']) ? '8px' : '0');
        $state === 'disabled' && $d[] = 'opacity:0.5';

        return implode(';', $d);
    }

    private static function hex(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A#[0-9a-fA-F]{6}\z/', $value) === 1 ? strtolower($value) : null;
    }
}
