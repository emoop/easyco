<?php

namespace App\Storefront\Appearance;

use App\Rules\PlainText;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\StoreTimezone;
use DateTimeImmutable;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * THE ONE READER of everything the storefront shows from the appearance settings (storefront-customization-design.md 3.4): the compiled token
 * stylesheet's hash and fonts, the theme key and the top banner. ONE `getMany` of AppearanceSettings::READ_KEYS (D2 adds `header.compiled` to the same
 * list), memoised for the request, and the result is kept in the APPLICATION CACHE under the stable key `storefront.appearance` (no expiry): the admin
 * save REPLACES that entry after a successful save (`refresh()`), and a cache miss falls back to the one `getMany` and fills the entry. A warm page pays
 * no query for appearance; a cold one pays exactly one, the same for every page.
 *
 * The banner's DATES are compared per request (a comparison, no query) in the store time zone. Every stored value is validated again when the payload
 * is built, so a hand-edited database row cannot print an unsafe link or a text with control characters: such a banner is simply not shown.
 */
#[Scoped]
final class StorefrontAppearance
{
    public const CACHE_KEY = 'storefront.appearance';

    /** @var array{theme: ?string, compiled: ?array{hash: string, fonts: list<string>, body: ?string}, banner: array<string, mixed>}|null */
    private ?array $data = null;

    public function __construct(
        private readonly SiteSettingsRepository $settings,
        private readonly StoreTimezone $timezone,
    ) {
    }

    /**
     * @return array{theme: ?string, compiled: ?array{hash: string, fonts: list<string>, body: ?string}, banner: array<string, mixed>}
     */
    public function data(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached) && array_key_exists('theme', $cached) && array_key_exists('compiled', $cached) && is_array($cached['banner'] ?? null)) {
            return $this->data = $cached;
        }

        return $this->data = $this->rebuild();
    }

    /** Reads the settings (ONE query) and REPLACES the cache entry: what the admin save calls after a successful save. */
    public function refresh(): void
    {
        $this->data = $this->rebuild();
    }

    /** @return array{theme: ?string, compiled: ?array{hash: string, fonts: list<string>, body: ?string}, banner: array<string, mixed>} */
    private function rebuild(): array
    {
        $payload = self::build($this->settings->getMany(AppearanceSettings::READ_KEYS));
        Cache::forever(self::CACHE_KEY, $payload);

        return $payload;
    }

    /**
     * The payload for the raw stored values (pure: no query, no clock).
     *
     * @param  array<string, ?string>  $raw
     * @return array{theme: ?string, compiled: ?array{hash: string, fonts: list<string>, body: ?string}, banner: array<string, mixed>}
     */
    public static function build(array $raw): array
    {
        $theme = trim((string) ($raw[AppearanceSettings::THEME_KEY] ?? ''));

        return [
            'theme' => $theme === '' ? null : mb_substr($theme, 0, 40),
            'compiled' => self::parseCompiled($raw[AppearanceSettings::COMPILED_KEY] ?? null),
            'banner' => self::parseBanner($raw),
        ];
    }

    /** @return array{hash: string, fonts: list<string>, body: ?string}|null */
    private static function parseCompiled(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }

        $data = json_decode($json, true);

        if (! is_array($data) || ! is_string($data['hash'] ?? null) || preg_match('/^[0-9a-f]{12}$/', $data['hash']) !== 1) {
            return null;
        }

        $fonts = array_values(array_filter((array) ($data['fonts'] ?? []), static fn ($font): bool => is_string($font) && array_key_exists($font, AppearanceSettings::FONTS) && $font !== 'system'));
        $body = $data['body'] ?? null;

        return ['hash' => $data['hash'], 'fonts' => $fonts, 'body' => is_string($body) && in_array($body, $fonts, true) ? $body : null];
    }

    /**
     * The banner as stored, validated: `enabled` is false for anything that would not be safe to print.
     *
     * @param  array<string, ?string>  $raw
     * @return array<string, mixed>
     */
    private static function parseBanner(array $raw): array
    {
        $text = trim((string) ($raw[AppearanceSettings::BANNER_TEXT] ?? ''));
        $url = trim((string) ($raw[AppearanceSettings::BANNER_URL] ?? ''));
        $label = trim((string) ($raw[AppearanceSettings::BANNER_LINK_LABEL] ?? ''));
        $enabled = ($raw[AppearanceSettings::BANNER_ENABLED] ?? null) === '1'
            && $text !== '' && mb_strlen($text) <= AppearanceSettings::BANNER_TEXT_MAX && self::isPlain($text);

        if ($url !== '' && ! AppearanceSettings::isSafeBannerUrl($url)) {
            $url = '';
        }

        if ($label !== '' && (mb_strlen($label) > AppearanceSettings::BANNER_LABEL_MAX || ! self::isPlain($label))) {
            $label = '';
        }

        return [
            'enabled' => $enabled,
            'text' => $enabled ? $text : '',
            'url' => $enabled && $url !== '' ? $url : null,
            'label' => $enabled && $url !== '' && $label !== '' ? $label : null,
            'starts_at' => self::date($raw[AppearanceSettings::BANNER_STARTS_AT] ?? null),
            'ends_at' => self::date($raw[AppearanceSettings::BANNER_ENDS_AT] ?? null),
        ];
    }

    private static function isPlain(string $text): bool
    {
        $failed = false;
        (new PlainText)->validate('text', $text, static function () use (&$failed): void {
            $failed = true;
        });

        return ! $failed;
    }

    /** A stored `Y-m-d H:i` (store time zone) or null. */
    private static function date(?string $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value) === 1 ? $value : null;
    }

    public function themeKey(): ?string
    {
        return $this->data()['theme'];
    }

    /** @return array{hash: string, fonts: list<string>, body: ?string}|null */
    public function compiled(): ?array
    {
        return $this->data()['compiled'];
    }

    /**
     * The banner to print NOW, or null: off, empty, or outside its dates (compared in the store time zone, no query).
     *
     * @return array{text: string, url: ?string, label: ?string}|null
     */
    public function banner(?DateTimeImmutable $now = null): ?array
    {
        $banner = $this->data()['banner'];

        if (($banner['enabled'] ?? false) !== true) {
            return null;
        }

        $zone = $this->timezone->zone();
        $now = ($now ?? new DateTimeImmutable('now'))->setTimezone($zone);

        foreach (['starts_at' => 1, 'ends_at' => -1] as $field => $direction) {
            if ($banner[$field] === null) {
                continue;
            }

            try {
                $edge = new DateTimeImmutable($banner[$field], $zone);
            } catch (Throwable) {
                continue;
            }

            if (($direction === 1 && $now < $edge) || ($direction === -1 && $now >= $edge)) {
                return null;
            }
        }

        return ['text' => $banner['text'], 'url' => $banner['url'], 'label' => $banner['label']];
    }

    /**
     * What the layout needs: the token stylesheet's URL, the font to preload (the body font's latin file only) and the banner.
     *
     * @return array{tokensUrl: ?string, preloadFont: ?string, banner: array{text: string, url: ?string, label: ?string}|null}
     */
    public function forLayout(): array
    {
        $compiled = $this->compiled();

        return [
            'tokensUrl' => $compiled === null ? null : '/storage/'.\App\Storefront\Appearance\AppearanceCompiler::DIRECTORY.'/tokens-'.$compiled['hash'].'.css',
            'preloadFont' => $compiled === null || $compiled['body'] === null ? null : '/fonts/storefront/'.$compiled['body'].'/'.$compiled['body'].'-latin-wght-normal.woff2',
            'banner' => $this->banner(),
        ];
    }
}
