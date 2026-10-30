<?php

namespace App\Storefront\PageDesign;

use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\GallerySettings;
use App\Storefront\Design\FieldKinds;

/**
 * GL-2: the two sections of the «Дизайн» screen that hold plain values instead of a layout: «Страница и карти» (`page`) and «Продуктова страница» (`product`). THE ONE LIST of their
 * fields, their ranges, their defaults (TODAY's values) and the CSS variables they set; the admin form, the validator and the token compiler all read it.
 *
 * Every value is a number in a range, a member of a closed set or a switch, checked by FieldKinds on SAVE, again when the compiler stylesheet is built and again on every recompile: nothing free
 * reaches the CSS. Lengths are entered in PIXELS (the merchant thinks in px) and written in REM (px / 16, a dot, at most 4 decimals so a whole pixel stays exact); the fluid part of the page
 * padding is in `vw`. Only what DIFFERS from the default is ever written to the stylesheet: an untouched shop compiles to exactly what it did before this stage.
 *
 * The five gallery choices (`gallery_*`) are NOT variables: they are the `storefront.gallery.*` keys that GallerySettings reads at render (the data-gal-* attributes are HTML). Every other field
 * of the product section, and every field of the page section, lives in ONE JSON setting per section (`design.page.global`, `design.product.global`).
 *
 * @phpstan-type Field array{kind: string, default: mixed, unit?: string, min?: int|float, max?: int|float, options?: list<string>, gallery?: bool}
 */
final class PageDesign
{
    public const PAGE = 'page';

    public const PRODUCT = 'product';

    /** CD-1a: «Каталог» — the columns and the number of products per page of the product listing (the category page). */
    public const CATALOG = 'catalog';

    public const SECTIONS = [self::PAGE, self::PRODUCT, self::CATALOG];

    /** The settings key of a section's JSON (the variable part). */
    public const KEYS = [self::PAGE => AppearanceSettings::PAGE_DESIGN_KEY, self::PRODUCT => AppearanceSettings::PRODUCT_DESIGN_KEY, self::CATALOG => AppearanceSettings::CATALOG_DESIGN_KEY];

    /** The settings key of a section's DRAFT. */
    public const DRAFT_KEYS = [self::PAGE => AppearanceSettings::PAGE_DESIGN_DRAFT_KEY, self::PRODUCT => AppearanceSettings::PRODUCT_DESIGN_DRAFT_KEY, self::CATALOG => AppearanceSettings::CATALOG_DESIGN_DRAFT_KEY];

    /** CD-1a: the closed lists of the catalog section (strings: what the panel posts and the JSON stores). */
    public const CATALOG_COLS = ['3', '4', '5'];

    public const CATALOG_COLS_PHONE = ['1', '2'];

    public const CATALOG_PER_PAGE = ['12', '20', '24', '36', '48', '60', '72'];

    /** The `site_settings` keys of the gallery choices, by field. */
    public const GALLERY_KEYS = [
        'gallery_desktop_mode' => GallerySettings::DESKTOP_MODE_KEY,
        'gallery_mobile_mode' => GallerySettings::MOBILE_MODE_KEY,
        'gallery_width' => GallerySettings::WIDTH_PERCENT_KEY,
        'gallery_odd_last' => GallerySettings::ODD_LAST_KEY,
        'gallery_hover_zoom' => GallerySettings::HOVER_ZOOM_KEY,
    ];

    /** A value of a stylesheet length: `0` or a few digits of rem (a dot, up to 4 decimals). */
    private const LENGTH = '(?:0|[0-9]{1,2}(?:\.[0-9]{1,4})?rem)';

    /** @return array<string, array<string, mixed>> the fields of a section, in the order of the panel */
    public static function fields(string $section): array
    {
        if ($section === self::PAGE) {
            return [
                'pad_min' => ['kind' => 'number', 'min' => 8, 'max' => 48, 'default' => 16, 'unit' => 'px'],
                'pad_vw' => ['kind' => 'number', 'min' => 0, 'max' => 8, 'default' => 4, 'unit' => 'vw', 'decimals' => 2],
                'pad_max' => ['kind' => 'number', 'min' => 16, 'max' => 320, 'default' => 160, 'unit' => 'px'],
                'card_border' => ['kind' => 'number', 'min' => 0, 'max' => 4, 'default' => 1, 'unit' => 'px'],
                'card_radius' => ['kind' => 'number', 'min' => 0, 'max' => 32, 'default' => 8, 'unit' => 'px'],
                'card_border_m' => ['kind' => 'number', 'min' => 0, 'max' => 4, 'default' => 0, 'unit' => 'px'],
                'card_radius_m' => ['kind' => 'number', 'min' => 0, 'max' => 32, 'default' => 0, 'unit' => 'px'],
            ];
        }

        if ($section === self::PRODUCT) {
            return [
                'gallery_desktop_mode' => ['kind' => 'enum', 'options' => GallerySettings::DESKTOP_MODES, 'default' => GallerySettings::DEFAULT_DESKTOP_MODE, 'gallery' => true],
                'gallery_mobile_mode' => ['kind' => 'enum', 'options' => GallerySettings::MOBILE_MODES, 'default' => GallerySettings::DEFAULT_MOBILE_MODE, 'gallery' => true],
                'gallery_width' => ['kind' => 'enum', 'options' => array_map('strval', GallerySettings::WIDTH_PERCENTS), 'default' => (string) GallerySettings::DEFAULT_WIDTH_PERCENT, 'gallery' => true],
                'gallery_odd_last' => ['kind' => 'enum', 'options' => GallerySettings::ODD_LAST, 'default' => GallerySettings::DEFAULT_ODD_LAST, 'gallery' => true],
                'gallery_hover_zoom' => ['kind' => 'enum', 'options' => GallerySettings::HOVER_ZOOMS, 'default' => GallerySettings::DEFAULT_HOVER_ZOOM, 'gallery' => true],
                'gal_gap' => ['kind' => 'number', 'min' => 0, 'max' => 32, 'default' => 8, 'unit' => 'px'],
                'gal_radius' => ['kind' => 'number', 'min' => 0, 'max' => 16, 'default' => 4, 'unit' => 'px'],
                'col_gap' => ['kind' => 'number', 'min' => 0, 'max' => 96, 'default' => 32, 'unit' => 'px'],
                'sticky' => ['kind' => 'bool', 'default' => true],
            ];
        }

        if ($section === self::CATALOG) {
            return [
                'cols' => ['kind' => 'enum', 'options' => self::CATALOG_COLS, 'default' => '4'],
                'cols_phone' => ['kind' => 'enum', 'options' => self::CATALOG_COLS_PHONE, 'default' => '2'],
                'per_page' => ['kind' => 'enum', 'options' => self::CATALOG_PER_PAGE, 'default' => '24'],
            ];
        }

        return [];
    }

    /** @return array<string, mixed> every field at its default */
    public static function defaults(string $section): array
    {
        return array_map(static fn (array $field): mixed => $field['default'], self::fields($section));
    }

    /** @return array<string, array<string, mixed>> the fields that become CSS variables (everything but the gallery choices) */
    public static function variableFields(string $section): array
    {
        return array_filter(self::fields($section), static fn (array $field): bool => empty($field['gallery']));
    }

    /**
     * The values NORMALISED (every field present, a failing one at its default, numbers as numbers) and the messages by field. The gallery choices are checked too unless `$withGallery` is false (the
     * stored JSON never holds them). Cross-field rule: the page padding minimum is at most its maximum.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public static function validate(string $section, mixed $values, bool $withGallery = true): array
    {
        $errors = [];
        $fail = static function (string $path, string $code, array $params = []) use (&$errors): void {
            $errors[$path] ??= (string) __('design.page_design.errors.'.$code, $params);
        };
        $fields = $withGallery ? self::fields($section) : self::variableFields($section);

        if (! is_array($values)) {
            $fail('values', 'bad_choice');

            return [array_map(static fn (array $field): mixed => $field['default'], $fields), $errors];
        }

        foreach (array_diff(array_keys($values), array_keys($fields)) as $key) {
            $fail((string) $key, 'unknown_setting', ['key' => mb_substr((string) $key, 0, 30)]);
        }

        $out = [];

        foreach ($fields as $name => $spec) {
            $value = array_key_exists($name, $values) ? $values[$name] : $spec['default'];

            $out[$name] = FieldKinds::validate($value, $spec, $name, $fail);

            // a switch that is not a real boolean is refused, and what is kept is the DEFAULT (the bare kind answers `false`, which for «sticky» would mean off)
            isset($errors[$name]) && $spec['kind'] === 'bool' && $out[$name] = $spec['default'];
        }

        if ($section === self::PAGE && ! isset($errors['pad_min'], $errors['pad_max']) && $out['pad_min'] > $out['pad_max']) {
            $fail('pad_max', 'pad_order');
        }

        return [$out, $errors];
    }

    /** @param  array<string, mixed>  $clean  validated @return bool is every VARIABLE field at its default? */
    public static function isDefault(string $section, array $clean): bool
    {
        foreach (self::variableFields($section) as $name => $field) {
            if (! self::same($clean[$name] ?? $field['default'], $field['default'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * The CSS variables of a validated section: ONLY what differs from the default. `--sf-page-pad` is one `clamp()` written when any of its three parts differs.
     *
     * @param  array<string, mixed>  $clean
     * @return array<string, string>
     */
    public static function vars(string $section, array $clean, bool $all = false): array
    {
        $fields = self::fields($section);
        $differs = static fn (string $name): bool => $all || ! self::same($clean[$name] ?? $fields[$name]['default'], $fields[$name]['default']);
        $vars = [];

        if ($section === self::PAGE) {
            if ($differs('pad_min') || $differs('pad_vw') || $differs('pad_max')) {
                $vars['--sf-page-pad'] = 'clamp('.self::rem($clean['pad_min']).', '.self::number($clean['pad_vw']).'vw, '.self::rem($clean['pad_max']).')';
            }

            foreach (['card_border' => '--sf-card-border-width', 'card_radius' => '--sf-card-radius', 'card_border_m' => '--sf-card-border-width-mobile', 'card_radius_m' => '--sf-card-radius-mobile'] as $name => $variable) {
                $differs($name) && $vars[$variable] = self::rem($clean[$name]);
            }
        }

        if ($section === self::CATALOG) {
            // `per_page` is not a variable: the listing reads it at render (StorefrontAppearance::catalogPerPage). The tablet is always 3 columns (min(desktop, 3)), so it needs no variable.
            $differs('cols') && $vars['--sf-cat-cols'] = (string) $clean['cols'];
            $differs('cols_phone') && $vars['--sf-cat-cols-phone'] = (string) $clean['cols_phone'];
        }

        if ($section === self::PRODUCT) {
            foreach (['gal_gap' => '--sf-gal-gap', 'gal_radius' => '--sf-gal-radius', 'col_gap' => '--sf-product-col-gap'] as $name => $variable) {
                $differs($name) && $vars[$variable] = self::rem($clean[$name]);
            }

            if ($differs('sticky')) {
                // off: the right column is a plain block (storefront.css reads these two with the sticky values as fallbacks)
                $vars['--sf-summary-position'] = 'static';
                $vars['--sf-summary-max-height'] = 'none';
            }
        }

        return $vars;
    }

    /**
     * CD-1a.1: the variables of the admin's PREVIEW of a section's working copy: what the draft writes, plus an EXPLICIT default for every variable the PUBLISHED values write and the draft does not (so the
     * preview frame, which also links the published token stylesheet, shows the draft and not the published value). Only for the signed preview; the public stylesheet never gets these.
     *
     * @param  array<string, mixed>  $draft  validated  @param  array<string, mixed>  $published  validated
     * @return array<string, string>
     */
    public static function previewVars(string $section, array $draft, array $published): array
    {
        $vars = self::vars($section, $draft);
        $explicit = self::vars($section, $draft, true);

        foreach (array_diff_key(self::vars($section, $published), $vars) as $variable => $_) {
            isset($explicit[$variable]) && $vars[$variable] = $explicit[$variable];
        }

        return $vars;
    }

    /**
     * The variables of BOTH stored sections, each one validated again (a value that does not validate makes its whole section contribute nothing), for the token compiler.
     *
     * @return array<string, string>
     */
    public static function storedVars(SiteSettingsRepository $settings): array
    {
        $vars = [];
        $stored = $settings->getMany(array_values(self::KEYS));

        foreach (self::SECTIONS as $section) {
            $values = self::decode($stored[self::KEYS[$section]] ?? null);

            if ($values === null) {
                continue;
            }

            [$clean, $errors] = self::validate($section, $values, false);

            if ($errors === []) {
                $vars += self::vars($section, $clean);
            }
        }

        return $vars;
    }

    /** Is this a variable the compiler may write, with a value of the form it is written in? The closed list; anything else is refused. */
    public static function isValidVar(string $variable, mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        return match ($variable) {
            '--sf-page-pad' => preg_match('/^clamp\('.self::LENGTH.', [0-9](?:\.[0-9]{1,3})?vw, '.self::LENGTH.'\)$/', $value) === 1,
            '--sf-card-border-width', '--sf-card-radius', '--sf-card-border-width-mobile', '--sf-card-radius-mobile', '--sf-gal-gap', '--sf-gal-radius', '--sf-product-col-gap' => preg_match('/^'.self::LENGTH.'$/', $value) === 1,
            '--sf-cat-cols' => in_array($value, self::CATALOG_COLS, true),
            '--sf-cat-cols-phone' => in_array($value, self::CATALOG_COLS_PHONE, true),
            '--sf-summary-position' => $value === 'static',
            '--sf-summary-max-height' => $value === 'none',
            default => false,
        };
    }

    /** @return array<string, mixed>|null */
    public static function decode(?string $json): ?array
    {
        $values = is_string($json) && $json !== '' ? json_decode($json, true) : null;

        return is_array($values) ? $values : null;
    }

    /** CD-1a: the VALIDATED values of the catalog section from its stored JSON (every field present; anything that does not validate gives the defaults). @return array<string, mixed> */
    public static function catalogValues(?string $json): array
    {
        $values = self::decode($json);

        if ($values !== null) {
            [$clean, $errors] = self::validate(self::CATALOG, $values, false);

            if ($errors === []) {
                return $clean;
            }
        }

        return self::defaults(self::CATALOG);
    }

    /** px -> rem for the stylesheet: `px / 16`, a dot, at most 4 decimals, no trailing zeros; zero is a plain `0`. */
    public static function rem(int|float $px): string
    {
        if ((float) $px === 0.0) {
            return '0';
        }

        return rtrim(rtrim(number_format($px / 16, 4, '.', ''), '0'), '.').'rem';
    }

    /** A plain number for the stylesheet: a dot, at most 3 decimals, no trailing zeros. */
    private static function number(int|float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

        return $text === '' ? '0' : $text;
    }

    private static function same(mixed $a, mixed $b): bool
    {
        return is_numeric($a) && is_numeric($b) ? abs((float) $a - (float) $b) < 0.0001 : $a === $b;
    }
}
