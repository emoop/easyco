<?php

namespace App\Storefront\Appearance;

use App\Services\ActivityLogger;
use App\Settings\AppearanceSettings as Appearance;
use App\Settings\Contracts\SiteSettingsRepository;

/**
 * What the «Облик» page stores, in one place (DS-2b): the colours, the fonts, the corners, the button style and the top banner. The «Облик» page and the «Дизайн» screen's «Топбанер» zone both call
 * `save()`, so the two always store the same keys the same way, recompile the ONE token stylesheet, REPLACE the reader's cache entry and write the same activity-log lines. No form, no
 * notification and no validation here: the caller has validated.
 *
 * The data is the page's own state: `c_{name}` for every colour of Appearance::COLORS, `font_body`, `font_heading`, `radius`, `button`, and `banner_enabled`, `banner_text`, `banner_url`,
 * `banner_link_label`, `banner_starts_at`, `banner_ends_at`.
 */
final class AppearanceWriter
{
    /** The page's state as it is stored now (what «Облик» fills its form with). @return array<string, mixed> */
    public static function current(): array
    {
        $settings = app(SiteSettingsRepository::class);
        $defaults = Appearance::defaults();
        $stored = $settings->getMany([
            ...array_keys($defaults),
            Appearance::BANNER_ENABLED, Appearance::BANNER_TEXT, Appearance::BANNER_URL, Appearance::BANNER_LINK_LABEL,
            Appearance::BANNER_STARTS_AT, Appearance::BANNER_ENDS_AT,
        ]);
        $state = [];

        foreach (Appearance::COLORS as $name => [$key, , $default]) {
            $value = $stored[$key] ?? null;
            $state['c_'.$name] = Appearance::isHex($value) ? strtolower($value) : $default;
        }

        foreach (['font_body' => Appearance::FONT_BODY, 'font_heading' => Appearance::FONT_HEADING, 'radius' => Appearance::RADIUS, 'button' => Appearance::BUTTON] as $field => $key) {
            $value = $stored[$key] ?? null;
            $state[$field] = Appearance::isValid($key, $value) ? $value : $defaults[$key];
        }

        return $state + [
            'banner_enabled' => ($stored[Appearance::BANNER_ENABLED] ?? null) === '1',
            'banner_text' => $stored[Appearance::BANNER_TEXT] ?? '',
            'banner_url' => $stored[Appearance::BANNER_URL] ?? '',
            'banner_link_label' => $stored[Appearance::BANNER_LINK_LABEL] ?? '',
            'banner_starts_at' => $stored[Appearance::BANNER_STARTS_AT] ?? null,
            'banner_ends_at' => $stored[Appearance::BANNER_ENDS_AT] ?? null,
        ];
    }

    /**
     * Stores the data, recompiles the stylesheet, replaces the cache entry and journals the sections that changed.
     *
     * @param  array<string, mixed>  $data
     */
    public static function save(array $data): void
    {
        $settings = app(SiteSettingsRepository::class);
        $defaults = Appearance::defaults();
        $before = self::snapshot($settings);

        // OB-1: the top banner is edited in the «Дизайн» screen only. A save that does not carry the banner (the «Облик» page) keeps the banner as it is stored: its two colours are read
        // back here so that the stylesheet still holds them, and the banner's own values are not written at all (see the `$ownsBanner` guard below).
        $ownsBanner = array_key_exists('banner_enabled', $data);

        if (! $ownsBanner) {
            $data = array_replace($data, array_intersect_key(self::current(), array_flip(['c_banner_text', 'c_banner_bg'])));
        }

        // Only what DIFFERS from its default is stored (the key of a default value is forgotten): the compiled stylesheet then holds exactly that.
        $overrides = [];

        foreach (Appearance::COLORS as $name => [$key, , $default]) {
            $value = strtolower((string) ($data['c_'.$name] ?? $default));
            $overrides[$key] = $value;
        }

        foreach (['font_body' => Appearance::FONT_BODY, 'font_heading' => Appearance::FONT_HEADING, 'radius' => Appearance::RADIUS, 'button' => Appearance::BUTTON] as $field => $key) {
            $overrides[$key] = (string) ($data[$field] ?? $defaults[$key]);
        }

        $overrides = array_filter($overrides, static fn (string $value, string $key): bool => $value !== $defaults[$key], ARRAY_FILTER_USE_BOTH);

        foreach ($defaults as $key => $default) {
            array_key_exists($key, $overrides) ? $settings->set($key, $overrides[$key]) : $settings->forget($key);
        }

        $ownsBanner && self::storeBanner($settings, $data);

        $previous = StorefrontAppearance::build([Appearance::COMPILED_KEY => $before['compiled']])['compiled']['hash'] ?? null;
        $compiled = app(AppearanceCompiler::class)->compile($overrides, $previous);

        $compiled === null ? $settings->forget(Appearance::COMPILED_KEY) : $settings->set(Appearance::COMPILED_KEY, (string) json_encode($compiled, JSON_THROW_ON_ERROR));

        // The reader's cache entry is REPLACED, so the next request already shows what was saved.
        app(StorefrontAppearance::class)->refresh();

        self::journal($before, self::snapshot($settings));
    }

    /** @param  array<string, mixed>  $data */
    private static function storeBanner(SiteSettingsRepository $settings, array $data): void
    {
        ! empty($data['banner_enabled']) ? $settings->set(Appearance::BANNER_ENABLED, '1') : $settings->forget(Appearance::BANNER_ENABLED);

        foreach ([
            Appearance::BANNER_TEXT => 'banner_text',
            Appearance::BANNER_URL => 'banner_url',
            Appearance::BANNER_LINK_LABEL => 'banner_link_label',
            Appearance::BANNER_STARTS_AT => 'banner_starts_at',
            Appearance::BANNER_ENDS_AT => 'banner_ends_at',
        ] as $key => $field) {
            $value = trim((string) ($data[$field] ?? ''));
            $value === '' ? $settings->forget($key) : $settings->set($key, $value);
        }
    }

    /** @return array<string, ?string> every stored value the page owns */
    private static function snapshot(SiteSettingsRepository $settings): array
    {
        $keys = [...array_keys(Appearance::defaults()), Appearance::BANNER_ENABLED, Appearance::BANNER_TEXT, Appearance::BANNER_URL, Appearance::BANNER_LINK_LABEL, Appearance::BANNER_STARTS_AT, Appearance::BANNER_ENDS_AT];
        $values = $settings->getMany($keys);
        $values['compiled'] = $settings->get(Appearance::COMPILED_KEY);

        return $values;
    }

    /**
     * One activity-log line per SECTION that changed: who (the logger knows), which section, and only the word «saved». No value is written, not even a colour (the activity log is a record
     * that something changed; the compiled stylesheet is the record of what).
     *
     * @param  array<string, ?string>  $before
     * @param  array<string, ?string>  $after
     */
    private static function journal(array $before, array $after): void
    {
        $sections = [
            'colors' => array_map(static fn (array $color): string => $color[0], array_values(Appearance::COLORS)),
            'fonts' => [Appearance::FONT_BODY, Appearance::FONT_HEADING, Appearance::RADIUS, Appearance::BUTTON],
            'banner' => [Appearance::BANNER_ENABLED, Appearance::BANNER_TEXT, Appearance::BANNER_URL, Appearance::BANNER_LINK_LABEL, Appearance::BANNER_STARTS_AT, Appearance::BANNER_ENDS_AT],
        ];
        $logger = app(ActivityLogger::class);

        foreach ($sections as $section => $keys) {
            foreach ($keys as $key) {
                if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                    $logger->logFieldChanged('storefront_appearance', 'appearance', $section, null, 'saved');

                    break;
                }
            }
        }
    }
}
