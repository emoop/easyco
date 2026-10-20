<?php

namespace App\Storefront\Header;

/**
 * The shape, the limits and the SEEDED DEFAULT of the header layout (storefront-customization-design.md 4.1, 4.5; D2 adds the row heights).
 *
 *   layout = { desktop: { sticky, rows: { top, main, bottom } },
 *              mobile:  { sticky, rows: { top, main }, drawer: [Element], drawer_side: left|right } }
 *   Row    = { enabled, height, left: [Element], center: [Element], right: [Element] }
 *   Element = { type, settings }
 *
 * Heights are in px and reach the page only as CSS variables in the token stylesheet (AppearanceCompiler), never as an inline style.
 */
final class HeaderLayout
{
    public const MAX_PER_SLOT = 6;

    public const MAX_DRAWER = 8;

    /** The logo must fit inside its row with this much air: logo height <= row height - LOGO_AIR. */
    public const LOGO_AIR = 8;

    /** @var array<string, array<string, array{0: int, 1: int, 2: int}>> layout => row => [min, max, default] */
    public const ROW_HEIGHTS = [
        'desktop' => ['top' => [24, 64, 36], 'main' => [44, 140, 64], 'bottom' => [32, 80, 44]],
        'mobile' => ['top' => [24, 56, 32], 'main' => [44, 96, 56]],
    ];

    /** @var array<string, array{0: int, 1: int, 2: int}> layout => logo [min, max, default] */
    public const LOGO_HEIGHTS = ['desktop' => [16, 120, 28], 'mobile' => [16, 64, 26]];

    public const SLOTS = ['left', 'center', 'right'];

    /** The seeded default (the owner's vision): categories in the main row on desktop, the logo centred, the icons right. */
    public static function default(): array
    {
        $el = static fn (string $type, array $settings = []): array => ['type' => $type, 'settings' => $settings + HeaderCatalog::defaultsFor($type)];
        $off = static fn (int $height): array => ['enabled' => false, 'height' => $height, 'left' => [], 'center' => [], 'right' => []];

        return [
            'desktop' => [
                'sticky' => true,
                'rows' => [
                    'top' => $off(self::ROW_HEIGHTS['desktop']['top'][2]),
                    'main' => [
                        'enabled' => true, 'height' => self::ROW_HEIGHTS['desktop']['main'][2],
                        'left' => [$el('menu', ['style' => 'links'])],
                        'center' => [$el('logo', ['height' => self::LOGO_HEIGHTS['desktop'][2]])],
                        'right' => [$el('search', ['mode' => 'icon_row']), $el('account'), $el('cart', ['style' => 'icon'])],
                    ],
                    'bottom' => $off(self::ROW_HEIGHTS['desktop']['bottom'][2]),
                ],
            ],
            'mobile' => [
                'sticky' => true,
                'rows' => [
                    'top' => $off(self::ROW_HEIGHTS['mobile']['top'][2]),
                    'main' => [
                        'enabled' => true, 'height' => self::ROW_HEIGHTS['mobile']['main'][2],
                        'left' => [$el('menu_toggle')],
                        'center' => [$el('logo', ['height' => self::LOGO_HEIGHTS['mobile'][2]])],
                        'right' => [$el('search', ['mode' => 'icon_row']), $el('cart', ['style' => 'icon'])],
                    ],
                ],
                'drawer' => [$el('menu', ['style' => 'links']), $el('account')],
                'drawer_side' => 'left',
            ],
        ];
    }

    /**
     * The sizes the token stylesheet carries, as CSS-variable name => px (only the ones that differ from the default are written by the compiler).
     *
     * @param  array<string, mixed>  $layout  a VALIDATED layout
     * @return array<string, int>
     */
    public static function metrics(array $layout): array
    {
        $metrics = [];

        foreach (['desktop' => 'd', 'mobile' => 'm'] as $name => $short) {
            foreach (array_keys(self::ROW_HEIGHTS[$name]) as $row) {
                $metrics["--sf-hdr-{$short}-{$row}-h"] = (int) $layout[$name]['rows'][$row]['height'];
            }

            $logo = null;

            foreach ($layout[$name]['rows'] as $row) {
                foreach (self::SLOTS as $slot) {
                    foreach ($row[$slot] as $element) {
                        if ($element['type'] === 'logo') {
                            $logo = (int) $element['settings']['height'];
                        }
                    }
                }
            }

            $metrics["--sf-logo-{$short}-h"] = $logo ?? self::LOGO_HEIGHTS[$name][2];
        }

        return $metrics;
    }

    /** @return array<string, int> variable => default, the fallbacks storefront.css carries */
    public static function metricDefaults(): array
    {
        return self::metrics(self::default());
    }
}
