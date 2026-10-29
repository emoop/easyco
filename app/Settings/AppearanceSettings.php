<?php

namespace App\Settings;

/**
 * THE ONE PLACE of the storefront's appearance settings (storefront-customization-design.md 3.1, 3.3): the keys, the defaults and the validation of every
 * value. Nothing a merchant types can become CSS or markup except through these rules: a colour is `#RRGGBB` (no `url()`, no `;`, no `}`), a font and a
 * radius and a button style come from CLOSED lists, a text is plain and bounded, a link is a root-relative path or an https address.
 *
 * DEFAULTS reproduce today's storefront exactly. Only a value that DIFFERS from its default is stored (the admin forgets a key that holds its default,
 * like the checkout settings) and only that is compiled into the token stylesheet; every `var(--sf-...)` in storefront.css carries today's value as its
 * fallback, so an untouched shop renders as it always did and no stylesheet link is printed. (One deliberate difference from the design table: the footer
 * text default is #737373, the colour the footer really has today, not #404040.)
 */
final class AppearanceSettings
{
    public const COMPILED_KEY = 'appearance.compiled';

    public const THEME_KEY = 'storefront.theme';

    /** D2: the PUBLISHED header layout, validated and compiled (JSON {hash, layout}); read with the rest of the appearance. */
    public const HEADER_COMPILED_KEY = 'header.compiled';

    /** D3: ALL compiled menus (JSON, at most 256 KB), written by App\Storefront\Menu\MenuCompiler and read with the appearance. */
    public const MENUS_COMPILED_KEY = 'menus.compiled';

    /** MENU-2: which menu sits in which place of the shop: {"header": id|null, "drawer": id|null, "footer": id|null}. The storefront reads the compiled copy inside `menus.compiled`. */
    public const MENUS_LOCATIONS_KEY = 'menus.locations';

    /** FT-1: the PUBLISHED footer layout with the menus and HTML blocks it shows, validated and compiled (JSON {hash, layout, menus, blocks}, at most 256 KB); read with the rest of the appearance. */
    public const FOOTER_COMPILED_KEY = 'footer.compiled';

    /** DS-2b: the Design screen's generic zone storage (design-screen-design.md section 4): `design.{zone}.{scope}.draft|published` and ONE compiled document `{hash, zones: {zone: {scope: payload}}}`. */
    public const DESIGN_COMPILED_KEY = 'design.compiled';

    /** GL-2: the values of the two plain sections of the Design screen (JSON, only what differs from the defaults; see App\Storefront\PageDesign\PageDesign). Compiled into the token stylesheet; NOT part of the cached read. */
    public const PAGE_DESIGN_KEY = 'design.page.global';

    public const PRODUCT_DESIGN_KEY = 'design.product.global';

    /** DS-5: the DRAFT (working copy) of the plain sections and of the top banner, beside the published storage (same shape; the product draft also holds the gallery choices, written to `storefront.gallery.*` only on publish). Never read by the storefront. */
    public const PAGE_DESIGN_DRAFT_KEY = 'design.page.global.draft';

    public const PRODUCT_DESIGN_DRAFT_KEY = 'design.product.global.draft';

    public const BANNER_DRAFT_KEY = 'banner.draft';

    public const CHECKOUT_HEADER_DRAFT_KEY = 'design.checkout_header.global.draft';

    public const CHECKOUT_HEADER_PUBLISHED_KEY = 'design.checkout_header.global.published';

    /** SC-1: the right column of the product page («Дясна колона»): its working copy and its published layout (`{components: [{type, settings}]}`). */
    public const SUMMARY_DRAFT_KEY = 'design.product_summary.global.draft';

    public const SUMMARY_PUBLISHED_KEY = 'design.product_summary.global.published';

    public const FOOTER_DRAFT_KEY = 'footer.layout.draft';

    public const FOOTER_PUBLISHED_KEY = 'footer.layout.published';

    public const HEADER_DRAFT_KEY = 'header.layout.draft';

    public const HEADER_PUBLISHED_KEY = 'header.layout.published';

    public const BANNER_ENABLED = 'banner.enabled';

    public const BANNER_TEXT = 'banner.text';

    public const BANNER_URL = 'banner.url';

    public const BANNER_LINK_LABEL = 'banner.link_label';

    public const BANNER_COLOR_TEXT = 'banner.color.text';

    public const BANNER_COLOR_BG = 'banner.color.bg';

    public const BANNER_STARTS_AT = 'banner.starts_at';

    public const BANNER_ENDS_AT = 'banner.ends_at';

    public const FONT_BODY = 'appearance.font.body';

    public const FONT_HEADING = 'appearance.font.heading';

    public const RADIUS = 'appearance.radius';

    public const BUTTON = 'appearance.button';

    public const BANNER_TEXT_MAX = 160;

    public const BANNER_LABEL_MAX = 40;

    /**
     * The colour tokens: short name => [setting key, CSS variable, default].
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const COLORS = [
        'background' => ['appearance.color.background', '--sf-bg', '#fafafa'],
        'text' => ['appearance.color.text', '--sf-text', '#171717'],
        'muted' => ['appearance.color.muted', '--sf-muted', '#525252'],
        'primary' => ['appearance.color.primary', '--sf-primary', '#171717'],
        'on_primary' => ['appearance.color.on_primary', '--sf-on-primary', '#ffffff'],
        'accent' => ['appearance.color.accent', '--sf-accent', '#b91c1c'],
        'border' => ['appearance.color.border', '--sf-border', '#e5e5e5'],
        'header_bg' => ['appearance.color.header_bg', '--sf-header-bg', '#ffffff'],
        'header_text' => ['appearance.color.header_text', '--sf-header-text', '#171717'],
        'footer_bg' => ['appearance.color.footer_bg', '--sf-footer-bg', '#ffffff'],
        'footer_text' => ['appearance.color.footer_text', '--sf-footer-text', '#737373'],
        'banner_text' => [self::BANNER_COLOR_TEXT, '--sf-banner-text', '#ffffff'],
        'banner_bg' => [self::BANNER_COLOR_BG, '--sf-banner-bg', '#171717'],
    ];

    /** Font families offered (a closed list): key => label. `system` needs no file. */
    public const FONTS = ['system' => 'System', 'inter' => 'Inter', 'manrope' => 'Manrope', 'lora' => 'Lora', 'montserrat' => 'Montserrat', 'cormorant' => 'Cormorant Garamond'];

    /** The corner radius: key => pixels of the base radius (`pill` is the base `md` for everything but buttons). */
    public const RADII = ['none' => 0, 'sm' => 4, 'md' => 8, 'lg' => 12, 'pill' => 8];

    public const BUTTONS = ['solid', 'outline'];

    /** The pairs the admin warns about (WCAG AA 4.5:1): [text token, background token]. */
    public const CONTRAST_PAIRS = [
        'text_on_background' => ['text', 'background'],
        'on_primary_on_primary' => ['on_primary', 'primary'],
        'header' => ['header_text', 'header_bg'],
        'footer' => ['footer_text', 'footer_bg'],
        'banner' => ['banner_text', 'banner_bg'],
    ];

    public const MIN_CONTRAST = 4.5;

    /** Every key the appearance reader fetches in its ONE `getMany` (D2 adds `header.compiled` here). */
    public const READ_KEYS = [
        self::COMPILED_KEY,
        self::THEME_KEY,
        self::HEADER_COMPILED_KEY,
        self::MENUS_COMPILED_KEY,
        self::FOOTER_COMPILED_KEY,
        self::DESIGN_COMPILED_KEY,
        self::BANNER_ENABLED,
        self::BANNER_TEXT,
        self::BANNER_URL,
        self::BANNER_LINK_LABEL,
        self::BANNER_STARTS_AT,
        self::BANNER_ENDS_AT,
    ];

    public static function isHex(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
    }

    /** @return array<string, string> setting key => default, for every token that is compiled (colours, fonts, radius, button) */
    public static function defaults(): array
    {
        $defaults = [];

        foreach (self::COLORS as [$key, , $default]) {
            $defaults[$key] = $default;
        }

        return $defaults + [self::FONT_BODY => 'system', self::FONT_HEADING => 'same', self::RADIUS => 'md', self::BUTTON => 'solid'];
    }

    /** Is this a valid value for the setting key (a colour, or an entry of a closed list)? */
    public static function isValid(string $key, mixed $value): bool
    {
        foreach (self::COLORS as [$colorKey]) {
            if ($colorKey === $key) {
                return self::isHex($value);
            }
        }

        return match ($key) {
            self::FONT_BODY => is_string($value) && array_key_exists($value, self::FONTS),
            self::FONT_HEADING => is_string($value) && ($value === 'same' || array_key_exists($value, self::FONTS)),
            self::RADIUS => is_string($value) && array_key_exists($value, self::RADII),
            self::BUTTON => is_string($value) && in_array($value, self::BUTTONS, true),
            default => false,
        };
    }

    /**
     * The banner link: a root-relative path or an https address, by the SAME rule as the checkout's terms and the pages (CheckoutFormSettings::isSafeLink)
     * plus: an absolute address must be https (no plain http, no javascript:, no data:, no protocol-relative `//host`).
     */
    public static function isSafeBannerUrl(string $url): bool
    {
        if (! CheckoutFormSettings::isSafeLink($url)) {
            return false;
        }

        return str_starts_with($url, '/') || strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }

    /** The WCAG contrast ratio of two #RRGGBB colours (1 to 21). */
    public static function contrastRatio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(static function (string $part): float {
            $c = hexdec($part) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, [substr($hex, 1, 2), substr($hex, 3, 2), substr($hex, 5, 2)]);

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
