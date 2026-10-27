<?php

namespace App\Storefront\Design;

use App\Rules\PlainText;
use App\Rules\ValidPhone;
use App\Settings\AppearanceSettings;
use App\Storefront\Footer\FooterCatalog;
use App\Storefront\Header\HeaderCatalog;
use App\Storefront\Support\DescriptionSanitizer;

/**
 * THE ONE VALIDATION PER FIELD KIND (DS-1, design-screen-design.md section 2). The header (D2) and the footer (FT-1) used to carry two near-copies of the same code; both validators now
 * call this class for the settings of an element / block, and keep only their structure checks (rows, slots, columns, limits). Nothing here knows Filament, a catalog or a lang file:
 * a failure is `$fail($path, $code, $params)`, and the CALLER turns the code into its own message (`header.errors.*`, `footer.errors.*`).
 *
 * `validate()` returns the NORMALISED value (the default after a failure), exactly as the two validators did. The two catalogs differ in a few places; the differences are explicit
 * options of the spec (a validator adds its catalog's options, the schemas themselves are unchanged), the defaults are the header's behaviour:
 *
 *  - `int_code`        the code of an int failure: `int_range` (header, with the params min / max) or `bad_choice` (footer, no params);
 *  - `too_many_code`   the code of a list that is too long (social, list): `too_many_slot` (header) or `too_many_social` (footer);
 *  - `numeric_string`  an int may be written as a string `^-?\d{1,6}$` (both catalogs do; true by default).
 *
 * Kinds: int, number (GL-2, with decimals), enum, bool, text, textarea, url, email, html, menu, html_block, social, payments (the union of the two catalogs) and, for the Design screen, color, phone, media, category,
 * page, product, tag, attribute and list (not used by any catalog yet).
 */
final class FieldKinds
{
    public const KINDS = ['int', 'number', 'enum', 'bool', 'text', 'textarea', 'url', 'email', 'html', 'menu', 'html_block', 'social', 'payments', 'color', 'phone', 'media', 'category', 'page', 'product', 'tag', 'attribute', 'list'];

    /** Kinds that hold a list: not allowed as the item kind of a `list` (flat only). */
    private const LIST_KINDS = ['social', 'payments', 'list'];

    /** The id kinds: a positive whole number; the target's existence is checked when the layout is compiled, not here. */
    private const ID_KINDS = ['menu', 'html_block', 'media', 'category', 'page', 'product', 'tag'];

    /**
     * @param  array<string, mixed>  $spec  `kind` plus the kind's own keys
     * @param  callable(string, string, array<string, mixed>=): void  $fail
     */
    public static function validate(mixed $value, array $spec, string $path, callable $fail): mixed
    {
        switch ($spec['kind'] ?? null) {
            case 'int':
                return self::intKind($value, $spec, $path, $fail);
            case 'number':
                return self::number($value, $spec, $path, $fail);
            case 'enum':
                if (! in_array($value, $spec['options'], true)) {
                    $fail($path, 'bad_choice', []);

                    return $spec['default'] ?? $spec['options'][0];
                }

                return $value;
            case 'bool':
                return self::bool($value, $path, $fail);
            case 'text':
                return self::text($value, (int) ($spec['max'] ?? 120), ! empty($spec['required']), $path, $fail);
            case 'textarea':
                return self::textarea($value, (int) ($spec['max'] ?? 400), $path, $fail);
            case 'email':
                return self::email($value, (int) ($spec['max'] ?? 120), $path, $fail);
            case 'url':
                return self::url($value, ! empty($spec['required']), $path, $fail);
            case 'html':
                return self::html($value, (int) ($spec['max'] ?? 4000), $path, $fail);
            case 'menu':
                return self::reference($value, false, $path, $fail);
            case 'html_block':
                return self::reference($value, true, $path, $fail);
            case 'social':
                return self::social($value, (int) ($spec['max'] ?? 8), $spec['too_many_code'] ?? 'too_many_slot', $path, $fail);
            case 'payments':
                return self::payments($value, $path, $fail);
            case 'color':
                return self::color($value, $path, $fail);
            case 'phone':
                return self::phone($value, $spec, $path, $fail);
            case 'media':
            case 'category':
            case 'page':
            case 'product':
            case 'tag':
                return self::reference($value, ! empty($spec['required']), $path, $fail);
            case 'attribute':
                return self::attribute($value, ! empty($spec['required']), $path, $fail);
            case 'list':
                return self::list($value, $spec, $path, $fail);
        }

        $fail($path, 'unknown_setting', []);

        return null;
    }

    /**
     * The default of a field: its own `default`, else what the kind starts from (false, the minimum, an empty list, an empty text, `null` for a colour or a target).
     *
     * @param  array<string, mixed>  $spec
     */
    public static function default(array $spec): mixed
    {
        $kind = $spec['kind'] ?? null;

        if (isset($spec['default'])) {
            return $spec['default'];
        }

        return match (true) {
            $kind === 'bool' => false,
            $kind === 'int' || $kind === 'number' => $spec['min'] ?? 0,
            in_array($kind, self::LIST_KINDS, true) => [],
            in_array($kind, ['color', 'media', 'category', 'page', 'product', 'tag', 'attribute'], true) => null,
            default => '',
        };
    }

    // ---- the kinds (the bodies the two validators had) -----------------------------------------------------------------------------

    /** @param  array<string, mixed>  $spec */
    private static function intKind(mixed $value, array $spec, string $path, callable $fail): int
    {
        $code = (string) ($spec['int_code'] ?? 'int_range');
        $params = $code === 'int_range' ? ['min' => $spec['min'] ?? 0, 'max' => $spec['max'] ?? ''] : [];
        $int = self::int($value, (int) ($spec['min'] ?? 0), (int) ($spec['max'] ?? PHP_INT_MAX), $path, $code, $params, $fail, $spec['numeric_string'] ?? true);

        return $int ?? (int) ($spec['default'] ?? $spec['min'] ?? 0);
    }

    /**
     * GL-2: a number WITH a decimal part in a range (`4`, `4.5`): `min` .. `max`, at most `decimals` (default 2) digits after the dot. A string must be exactly that (a dot, no comma, no exponent, no
     * unit, nothing after it): `1;color:red` is refused like any other non-number. A whole result is an int (16, not 16.0), so the stored JSON and the compared defaults read the same.
     *
     * @param  array<string, mixed>  $spec
     * @param  callable(string, string, array<string, mixed>=): void  $fail
     */
    public static function number(mixed $value, array $spec, string $path, callable $fail): int|float
    {
        $min = $spec['min'] ?? 0;
        $max = $spec['max'] ?? 9999;
        $decimals = (int) ($spec['decimals'] ?? 2);
        $number = null;

        if (is_int($value) || (is_float($value) && is_finite($value))) {
            $number = (float) $value;
        } elseif (is_string($value) && preg_match('/^\d{1,5}(\.\d{1,'.max(1, $decimals).'})?$/', $value) === 1) {
            $number = (float) $value;
        }

        if ($number === null || $number < $min || $number > $max) {
            $fail($path, 'number_range', ['min' => $min, 'max' => $max]);

            return $spec['default'] ?? $min;
        }

        $number = round($number, $decimals);

        return floor($number) === $number ? (int) $number : $number;
    }

    /**
     * A whole number in a range (also used by the validators' structure checks: row heights).
     *
     * @param  array<string, mixed>  $params
     * @param  callable(string, string, array<string, mixed>=): void  $fail
     */
    public static function int(mixed $value, int $min, int $max, string $path, string $code, array $params, callable $fail, bool $numericString = true): ?int
    {
        if ($numericString && is_string($value) && preg_match('/^-?\d{1,6}$/', $value) === 1) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < $min || $value > $max) {
            $fail($path, $code, $params);

            return null;
        }

        return $value;
    }

    /** @param  callable(string, string, array<string, mixed>=): void  $fail */
    public static function bool(mixed $value, string $path, callable $fail): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $fail($path, 'bad_choice', []);

        return false;
    }

    /** @param  callable(string, string, array<string, mixed>=): void  $fail */
    public static function text(mixed $value, int $max, bool $required, string $path, callable $fail): string
    {
        $value = is_string($value) ? trim($value) : '';

        if ($required && $value === '') {
            $fail($path, 'required', []);
        }

        if (mb_strlen($value) > $max) {
            $fail($path, 'too_long', ['max' => $max]);

            return mb_substr($value, 0, $max);
        }

        if ($value !== '' && ! self::isPlain($value)) {
            $fail($path, 'not_plain', []);

            return '';
        }

        return $value;
    }

    /** Plain lines: line breaks are kept (as "\n"), every other control character is refused. @param  callable(string, string, array<string, mixed>=): void  $fail */
    public static function textarea(mixed $value, int $max, string $path, callable $fail): string
    {
        $value = is_string($value) ? trim(str_replace(["\r\n", "\r"], "\n", $value)) : '';

        if (mb_strlen($value) > $max) {
            $fail($path, 'too_long', ['max' => $max]);

            return mb_substr($value, 0, $max);
        }

        foreach (explode("\n", $value) as $line) {
            if ($line !== '' && ! self::isPlain($line)) {
                $fail($path, 'not_plain', []);

                return '';
            }
        }

        return $value;
    }

    /** @param  callable(string, string, array<string, mixed>=): void  $fail */
    public static function email(mixed $value, int $max, string $path, callable $fail): string
    {
        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            return '';
        }

        if (mb_strlen($value) > $max || filter_var($value, FILTER_VALIDATE_EMAIL) === false || ! self::isPlain($value)) {
            $fail($path, 'bad_email', []);

            return '';
        }

        return $value;
    }

    /** A path of the shop or an `https://` address (the banner's rule). @param  callable(string, string, array<string, mixed>=): void  $fail */
    public static function url(mixed $value, bool $required, string $path, callable $fail): string
    {
        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            $required && $fail($path, 'required', []);

            return '';
        }

        if (! AppearanceSettings::isSafeBannerUrl($value)) {
            $fail($path, 'bad_url', []);

            return '';
        }

        return $value;
    }

    /** Rich text: cleaned by the description sanitizer; too long (before or after cleaning) is refused. @param  callable(string, string, array<string, mixed>=): void  $fail */
    public static function html(mixed $value, int $max, string $path, callable $fail): string
    {
        $value = is_string($value) ? $value : '';

        if (strlen($value) > $max * 8) {
            $fail($path, 'html_too_long', ['max' => $max]);

            return '';
        }

        $clean = DescriptionSanitizer::clean($value);

        if (strlen($clean) > $max) {
            $fail($path, 'html_too_long', ['max' => $max]);

            return '';
        }

        return $clean;
    }

    /** The id of a target (a menu, an HTML block, a picture, ...): a positive whole number or, when not required, nothing. @param  callable(string, string, array<string, mixed>=): void  $fail */
    public static function reference(mixed $value, bool $required, string $path, callable $fail): ?int
    {
        if ($value === null || $value === '') {
            $required && $fail($path, 'required', []);

            return null;
        }

        $id = is_string($value) && ctype_digit($value) ? (int) $value : $value;

        if (! is_int($id) || $id < 1) {
            $fail($path, 'bad_choice', []);

            return null;
        }

        return $id;
    }

    /** @param  callable(string, string, array<string, mixed>=): void  $fail @return list<array{network: string, url: string}> */
    public static function social(mixed $value, int $max, string $tooManyCode, string $path, callable $fail): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $fail($path, 'bad_list', []);

            return [];
        }

        if (count($value) > $max) {
            $fail($path, $tooManyCode, ['max' => $max]);
        }

        $out = [];

        foreach (array_slice($value, 0, $max) as $i => $item) {
            $network = is_array($item) ? ($item['network'] ?? null) : null;
            $url = is_array($item) && is_string($item['url'] ?? null) ? trim($item['url']) : '';

            if (! in_array($network, HeaderCatalog::SOCIAL_NETWORKS, true)) {
                $fail("{$path}.{$i}.network", 'bad_choice', []);

                continue;
            }

            if (! AppearanceSettings::isSafeBannerUrl($url) || ! str_starts_with(strtolower($url), 'https://')) {
                $fail("{$path}.{$i}.url", 'bad_https_url', []);

                continue;
            }

            $out[] = ['network' => $network, 'url' => $url];
        }

        return $out;
    }

    /** A subset of the closed list, in the order given, each once; a method that is not in the list is dropped. @param  callable(string, string, array<string, mixed>=): void  $fail @return list<string> */
    public static function payments(mixed $value, string $path, callable $fail): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $fail($path, 'bad_list', []);

            return [];
        }

        return array_values(array_unique(array_filter($value, static fn ($method): bool => is_string($method) && in_array($method, FooterCatalog::PAYMENTS, true))));
    }

    // ---- the kinds the Design screen adds (no catalog uses them yet) ----------------------------------------------------------------

    /** `#rgb` or `#rrggbb`, lower case; empty = nothing (the Облик token). @param  callable(string, string, array<string, mixed>=): void  $fail */
    public static function color(mixed $value, string $path, callable $fail): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) !== 1) {
            $fail($path, 'bad_color', []);

            return null;
        }

        return strtolower($value);
    }

    /**
     * A phone number through ValidPhone: an international number (`+` / `00`) is checked in any country; a national one only when the spec names the `country` (an ISO code).
     *
     * @param  array<string, mixed>  $spec
     * @param  callable(string, string, array<string, mixed>=): void  $fail
     */
    public static function phone(mixed $value, array $spec, string $path, callable $fail): string
    {
        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            ! empty($spec['required']) && $fail($path, 'required', []);

            return '';
        }

        $failed = false;
        ValidPhone::forCountry(isset($spec['country']) ? (string) $spec['country'] : null)->validate($path, $value, static function () use (&$failed) {
            $failed = true;

            return new class
            {
                public function translate(): void {}
            };
        });

        if ($failed || mb_strlen($value) > 32) {
            $fail($path, 'bad_phone', []);

            return '';
        }

        return $value;
    }

    /** The code of an attribute (a stable slug, never its label): `^[a-z][a-z0-9_-]{0,63}$`; whether it exists and is filterable is checked later. @param  callable(string, string, array<string, mixed>=): void  $fail */
    public static function attribute(mixed $value, bool $required, string $path, callable $fail): ?string
    {
        if ($value === null || $value === '') {
            $required && $fail($path, 'required', []);

            return null;
        }

        if (! is_string($value) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $value) !== 1) {
            $fail($path, 'bad_choice', []);

            return null;
        }

        return $value;
    }

    /**
     * A small FLAT list of one kind (`of`, a kind name or a spec; never a list kind): at most `max`; an item that is empty after validation is left out.
     *
     * @param  array<string, mixed>  $spec
     * @param  callable(string, string, array<string, mixed>=): void  $fail
     * @return list<mixed>
     */
    public static function list(mixed $value, array $spec, string $path, callable $fail): array
    {
        $of = is_array($spec['of'] ?? null) ? $spec['of'] : ['kind' => $spec['of'] ?? null];

        if (! in_array($of['kind'] ?? null, self::KINDS, true) || in_array($of['kind'], self::LIST_KINDS, true)) {
            $fail($path, 'unknown_setting', []);

            return [];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            $fail($path, 'bad_list', []);

            return [];
        }

        $max = (int) ($spec['max'] ?? 8);

        if (count($value) > $max) {
            $fail($path, (string) ($spec['too_many_code'] ?? 'too_many_slot'), ['max' => $max]);
        }

        $out = [];

        foreach (array_slice($value, 0, $max) as $i => $item) {
            $clean = self::validate($item, $of, "{$path}.{$i}", $fail);

            if ($clean !== null && $clean !== '') {
                $out[] = $clean;
            }
        }

        return $out;
    }

    /** Is the text free of control characters and bidirectional overrides (the PlainText rule)? */
    public static function isPlain(string $text): bool
    {
        $failed = false;
        (new PlainText)->validate('text', $text, static function () use (&$failed): void {
            $failed = true;
        });

        return ! $failed;
    }
}
