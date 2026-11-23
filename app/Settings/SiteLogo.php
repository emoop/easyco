<?php

namespace App\Settings;

/**
 * LOGO-1 (site-logo.md): the keys of the «Лого» tab and the PURE parsing of what the storefront, the admin and the mails read. No query, no clock, no request.
 *
 *   site.name           the shop's name (plain text, 1-80); absent = config('app.name') (ShopName)
 *   site_icon.media_id  the site icon: ONE square PNG or WebP picture of the library, 512x512 at least (the storefront and the admin)
 *   logo.main           the wide logo: PNG or WebP, 200 px wide at least (customer emails, the admin sidebar and login)
 *   logo.dark           OPTIONAL: the admin's dark mode logo (empty = dark mode uses logo.main)
 *   logo.compiled       what a save generated: {icon?, main?, dark?} with the url, width and height of each file and a content hash
 *
 * The three picture ids are the MERCHANT'S choice (read by the tab and the usage registry); `logo.compiled` is what is PRINTED. It rides on the appearance payload (`StorefrontAppearance`, one cached
 * read) and is validated AGAIN on every read: a damaged or tampered value gives null and every output falls back to today's static files.
 */
final class SiteLogo
{
    public const NAME = 'site.name';

    public const ICON = 'site_icon.media_id';

    public const MAIN = 'logo.main';

    public const DARK = 'logo.dark';

    public const COMPILED = 'logo.compiled';

    /** The keys the storefront payload reads (the ids are not needed there). */
    public const READ_KEYS_LIST = [self::NAME, self::COMPILED];

    public const NAME_MAX = 80;

    public const MIMES = ['image/png', 'image/webp'];

    public const ICON_MIN = 512;

    public const LOGO_MIN_WIDTH = 200;

    /** The widest generated logo: never upscaled. */
    public const LOGO_MAX_WIDTH = 400;

    /** Refused before decoding: GD holds 4 bytes per pixel (16 MP = 64 MB). */
    public const MAX_PIXELS = 16_000_000;

    public const MAX_BYTES = 25_000_000;

    /** Where the generated files live (the same public-disk area as the token stylesheets; only `icon-*` and `logo-*` are ours). */
    public const DIRECTORY = 'appearance';

    /** The shape of a file name we generate: `icon-{12 hex}-32.png`, `icon-{12 hex}.ico`, `logo-{12 hex}.png`, `logo-{12 hex}-dark.png`. */
    public const FILE = '#^(icon|logo)-[0-9a-f]{12}(-[a-z0-9]+)?\.(png|ico)$#';

    private const URL = '#^/storage/appearance/(icon|logo)-[0-9a-f]{12}(-[a-z0-9]+)?\.(png|ico)$#';

    /**
     * The shop name from a raw stored value: plain text of 1-80 characters, else null.
     */
    public static function cleanName(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || mb_strlen($value) > self::NAME_MAX || preg_match('/[\p{Cc}\p{Cf}]/u', $value) === 1) {
            return null;
        }

        return $value;
    }

    /**
     * What the storefront needs, from the raw stored values.
     *
     * @param  array<string, ?string>  $raw
     * @return array{name: ?string, logo: ?array<string, mixed>}
     */
    public static function payload(array $raw): array
    {
        return ['name' => self::cleanName($raw[self::NAME] ?? null), 'logo' => self::parseCompiled($raw[self::COMPILED] ?? null)];
    }

    /**
     * `logo.compiled`, validated: any damaged part gives null for the WHOLE value (everything falls back to the static files).
     *
     * @return array{icon: ?array<string, mixed>, main: ?array<string, mixed>, dark: ?array<string, mixed>}|null
     */
    public static function parseCompiled(?string $json): ?array
    {
        $data = is_string($json) && $json !== '' ? json_decode($json, true) : null;

        if (! is_array($data)) {
            return null;
        }

        $out = ['icon' => null, 'main' => null, 'dark' => null];

        foreach ($data as $key => $component) {
            if (! in_array($key, ['icon', 'main', 'dark'], true)) {
                return null;
            }

            // a part that is not set is null (the shape the payload itself has)
            if ($component === null) {
                continue;
            }

            if (! is_array($component)) {
                return null;
            }

            $clean = $key === 'icon' ? self::icon($component) : self::wide($component);

            if ($clean === null) {
                return null;
            }

            $out[$key] = $clean;
        }

        return $out === ['icon' => null, 'main' => null, 'dark' => null] ? null : $out;
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array{hash: string, png32: array{url: string, w: int, h: int}, png180: array{url: string, w: int, h: int}, ico: array{url: string}}|null
     */
    private static function icon(array $c): ?array
    {
        $png32 = self::file($c['png32'] ?? null, 32, 32);
        $png180 = self::file($c['png180'] ?? null, 180, 180);
        $ico = $c['ico'] ?? null;

        if (! self::hash($c['hash'] ?? null) || $png32 === null || $png180 === null || ! is_array($ico) || ! self::url($ico['url'] ?? null) || ! str_ends_with((string) $ico['url'], '.ico')) {
            return null;
        }

        return ['hash' => (string) $c['hash'], 'png32' => $png32, 'png180' => $png180, 'ico' => ['url' => (string) $ico['url']]];
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array{hash: string, png: array{url: string, w: int, h: int}}|null
     */
    private static function wide(array $c): ?array
    {
        $png = self::file($c['png'] ?? null, null, null);

        return self::hash($c['hash'] ?? null) && $png !== null && $png['w'] <= self::LOGO_MAX_WIDTH ? ['hash' => (string) $c['hash'], 'png' => $png] : null;
    }

    /** @return array{url: string, w: int, h: int}|null */
    private static function file(mixed $file, ?int $w, ?int $h): ?array
    {
        if (! is_array($file) || ! self::url($file['url'] ?? null) || ! str_ends_with((string) $file['url'], '.png')) {
            return null;
        }

        $fw = $file['w'] ?? null;
        $fh = $file['h'] ?? null;

        if (! is_int($fw) || ! is_int($fh) || $fw < 1 || $fh < 1 || $fw > 4096 || $fh > 4096 || ($w !== null && $fw !== $w) || ($h !== null && $fh !== $h)) {
            return null;
        }

        return ['url' => (string) $file['url'], 'w' => $fw, 'h' => $fh];
    }

    private static function hash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{12}$/D', $value) === 1;
    }

    private static function url(mixed $value): bool
    {
        return is_string($value) && preg_match(self::URL, $value) === 1;
    }
}
