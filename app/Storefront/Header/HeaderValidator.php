<?php

namespace App\Storefront\Header;

use App\Rules\PlainText;
use App\Settings\AppearanceSettings;
use App\Storefront\Support\DescriptionSanitizer;

/**
 * Validates a header layout against the element catalog (storefront-customization-design.md 4.1, 4.2, 8) and returns it NORMALISED (every setting present,
 * `html` cleaned). It runs on SAVE (the admin), on COMPILE (publish) and again when the cached payload is READ, so a hand-edited database row or cache
 * entry cannot add an unknown element type, an unknown setting, an unsafe link or an element where it does not belong: such a layout is simply refused
 * (the shop then shows the seeded default). Nothing here trusts its input to have been checked earlier.
 *
 * @phpstan-type Errors array<string, string>
 */
final class HeaderValidator
{
    /** @var array<string, string> path => message */
    private array $errors = [];

    /** @var array<string, array{label: string, schema: array<string, array<string, mixed>>, view: string, builtin: bool}> */
    private array $catalog = [];

    public static function validate(array $layout): HeaderValidation
    {
        $validator = new self;
        $validator->catalog = HeaderCatalog::all();
        $clean = $validator->layout($layout);

        return new HeaderValidation($clean, $validator->errors);
    }

    private function fail(string $path, string $code, array $params = []): void
    {
        // The first message of a path is the useful one.
        $this->errors[$path] ??= (string) __('header.errors.'.$code, $params);
    }

    /** @param  array<string, mixed>  $layout */
    private function layout(array $layout): array
    {
        $default = HeaderLayout::default();

        foreach (array_diff(array_keys($layout), ['desktop', 'mobile']) as $key) {
            $this->fail('layout', 'unknown_key', ['key' => mb_substr((string) $key, 0, 30)]);
        }

        return [
            'desktop' => $this->desktop(is_array($layout['desktop'] ?? null) ? $layout['desktop'] : $default['desktop']),
            'mobile' => $this->mobile(is_array($layout['mobile'] ?? null) ? $layout['mobile'] : $default['mobile']),
        ];
    }

    /** @param  array<string, mixed>  $d */
    private function desktop(array $d): array
    {
        $this->unknownKeys($d, ['sticky', 'rows'], 'desktop');
        $rows = $this->rows($d['rows'] ?? [], 'desktop');

        return ['sticky' => $this->bool($d['sticky'] ?? true, 'desktop.sticky'), 'rows' => $rows];
    }

    /** @param  array<string, mixed>  $m */
    private function mobile(array $m): array
    {
        $this->unknownKeys($m, ['sticky', 'rows', 'drawer', 'drawer_side'], 'mobile');
        $rows = $this->rows($m['rows'] ?? [], 'mobile');
        $side = $m['drawer_side'] ?? 'left';

        if (! in_array($side, ['left', 'right'], true)) {
            $this->fail('mobile.drawer_side', 'bad_choice');
            $side = 'left';
        }

        $drawer = [];
        $items = $m['drawer'] ?? [];

        if (! is_array($items) || ! array_is_list($items)) {
            $this->fail('mobile.drawer', 'bad_list');
            $items = [];
        }

        if (count($items) > HeaderLayout::MAX_DRAWER) {
            $this->fail('mobile.drawer', 'too_many_drawer', ['max' => HeaderLayout::MAX_DRAWER]);
        }

        foreach (array_slice($items, 0, HeaderLayout::MAX_DRAWER) as $i => $element) {
            $clean = $this->element($element, "mobile.drawer.{$i}", 'mobile');

            if ($clean !== null && ! in_array($clean['type'], HeaderCatalog::DRAWER_TYPES, true)) {
                $this->fail("mobile.drawer.{$i}", 'not_in_drawer', ['type' => $clean['type']]);
            }

            $clean !== null && $drawer[] = $clean;
        }

        $this->limitOnce(['rows' => $rows], 'mobile', ['logo', 'menu_toggle']);

        return ['sticky' => $this->bool($m['sticky'] ?? true, 'mobile.sticky'), 'rows' => $rows, 'drawer' => $drawer, 'drawer_side' => $side];
    }

    /**
     * @param  array<string, mixed>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function rows(mixed $rows, string $layout): array
    {
        $rows = is_array($rows) ? $rows : [];
        $out = [];

        foreach (array_diff(array_keys($rows), array_keys(HeaderLayout::ROW_HEIGHTS[$layout])) as $key) {
            $this->fail("{$layout}.rows", 'unknown_key', ['key' => mb_substr((string) $key, 0, 30)]);
        }

        foreach (HeaderLayout::ROW_HEIGHTS[$layout] as $name => [$min, $max, $default]) {
            $row = is_array($rows[$name] ?? null) ? $rows[$name] : ['enabled' => false, 'height' => $default, 'left' => [], 'center' => [], 'right' => []];
            $path = "{$layout}.rows.{$name}";
            $this->unknownKeys($row, ['enabled', 'height', 'left', 'center', 'right'], $path);

            $height = $this->int($row['height'] ?? $default, $min, $max, "{$path}.height", 'height_range', ['min' => $min, 'max' => $max]) ?? $default;
            $clean = ['enabled' => $this->bool($row['enabled'] ?? false, "{$path}.enabled"), 'height' => $height];

            foreach (HeaderLayout::SLOTS as $slot) {
                $clean[$slot] = $this->slot($row[$slot] ?? [], "{$path}.{$slot}", $layout);
            }

            // The logo must fit inside its row.
            foreach (HeaderLayout::SLOTS as $slot) {
                foreach ($clean[$slot] as $i => $element) {
                    if ($element['type'] === 'logo' && $element['settings']['height'] > $height - HeaderLayout::LOGO_AIR) {
                        $this->fail("{$path}.{$slot}.{$i}.settings.height", 'logo_taller', ['max' => max(0, $height - HeaderLayout::LOGO_AIR)]);
                    }
                }
            }

            $out[$name] = $clean;
        }

        $this->limitOnce(['rows' => $out], $layout, ['logo', 'menu_toggle']);

        return $out;
    }

    /** @return list<array{type: string, settings: array<string, mixed>}> */
    private function slot(mixed $items, string $path, string $layout): array
    {
        if (! is_array($items) || ! array_is_list($items)) {
            $this->fail($path, 'bad_list');

            return [];
        }

        if (count($items) > HeaderLayout::MAX_PER_SLOT) {
            $this->fail($path, 'too_many_slot', ['max' => HeaderLayout::MAX_PER_SLOT]);
        }

        $out = [];

        foreach (array_slice($items, 0, HeaderLayout::MAX_PER_SLOT) as $i => $element) {
            $clean = $this->element($element, "{$path}.{$i}", $layout);
            $clean !== null && $out[] = $clean;
        }

        return $out;
    }

    /** @return array{type: string, settings: array<string, mixed>}|null */
    private function element(mixed $element, string $path, string $layout): ?array
    {
        if (! is_array($element)) {
            $this->fail($path, 'bad_element');

            return null;
        }

        $this->unknownKeys($element, ['type', 'settings'], $path);
        $type = $element['type'] ?? null;

        if (! is_string($type) || ! isset($this->catalog[$type])) {
            $this->fail("{$path}.type", 'unknown_type', ['type' => is_string($type) ? mb_substr($type, 0, 30) : '?']);

            return null;
        }

        $settings = $element['settings'] ?? [];

        if (! is_array($settings)) {
            $this->fail("{$path}.settings", 'bad_element');

            return null;
        }

        $schema = $this->catalog[$type]['schema'];

        if ($type === 'logo') {
            [$min, $max, $default] = HeaderLayout::LOGO_HEIGHTS[$layout];
            $schema['height'] = ['kind' => 'int', 'min' => $min, 'max' => $max, 'default' => $default];
        }

        foreach (array_diff(array_keys($settings), array_keys($schema)) as $key) {
            $this->fail("{$path}.settings.{$key}", 'unknown_setting', ['key' => mb_substr((string) $key, 0, 30)]);
        }

        $clean = [];

        foreach ($schema as $name => $field) {
            $clean[$name] = $this->setting($settings[$name] ?? ($field['default'] ?? null), $field, "{$path}.settings.{$name}");
        }

        return ['type' => $type, 'settings' => $clean];
    }

    /** @param  array<string, mixed>  $field */
    private function setting(mixed $value, array $field, string $path): mixed
    {
        switch ($field['kind']) {
            case 'int':
                return $this->int($value, (int) ($field['min'] ?? 0), (int) ($field['max'] ?? PHP_INT_MAX), $path, 'int_range', ['min' => $field['min'] ?? 0, 'max' => $field['max'] ?? ''])
                    ?? (int) ($field['default'] ?? $field['min'] ?? 0);
            case 'enum':
                if (! in_array($value, $field['options'], true)) {
                    $this->fail($path, 'bad_choice');

                    return $field['default'] ?? $field['options'][0];
                }

                return $value;
            case 'menu_source':
                // `categories` (the shown category tree, D2) or the key of a menu (D3); whether the menu exists is checked when it is printed.
                if (! is_string($value) || ($value !== 'categories' && preg_match('/^[a-z0-9-]{1,40}$/', $value) !== 1)) {
                    $this->fail($path, 'bad_choice');

                    return $field['default'] ?? 'categories';
                }

                return $value;
            case 'bool':
                return $this->bool($value, $path);
            case 'text':
                return $this->text($value, (int) ($field['max'] ?? 120), ! empty($field['required']), $path);
            case 'url':
                return $this->url($value, ! empty($field['required']), $path);
            case 'html':
                return $this->html($value, (int) ($field['max'] ?? 4000), $path);
            case 'social':
                return $this->social($value, (int) ($field['max'] ?? 8), $path);
        }

        $this->fail($path, 'unknown_setting');

        return null;
    }

    /** @param  array<string, mixed>  $params */
    private function int(mixed $value, int $min, int $max, string $path, string $code, array $params): ?int
    {
        if (is_string($value) && preg_match('/^-?\d{1,6}$/', $value) === 1) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < $min || $value > $max) {
            $this->fail($path, $code, $params);

            return null;
        }

        return $value;
    }

    private function bool(mixed $value, string $path): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $this->fail($path, 'bad_choice');

        return false;
    }

    private function text(mixed $value, int $max, bool $required, string $path): string
    {
        $value = is_string($value) ? trim($value) : '';

        if ($required && $value === '') {
            $this->fail($path, 'required');
        }

        if (mb_strlen($value) > $max) {
            $this->fail($path, 'too_long', ['max' => $max]);

            return mb_substr($value, 0, $max);
        }

        if ($value !== '' && ! $this->isPlain($value)) {
            $this->fail($path, 'not_plain');

            return '';
        }

        return $value;
    }

    private function url(mixed $value, bool $required, string $path): string
    {
        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            $required && $this->fail($path, 'required');

            return '';
        }

        if (! AppearanceSettings::isSafeBannerUrl($value)) {
            $this->fail($path, 'bad_url');

            return '';
        }

        return $value;
    }

    private function html(mixed $value, int $max, string $path): string
    {
        $value = is_string($value) ? $value : '';

        if (strlen($value) > $max * 8) {
            $this->fail($path, 'html_too_long', ['max' => $max]);

            return '';
        }

        $clean = DescriptionSanitizer::clean($value);

        if (strlen($clean) > $max) {
            $this->fail($path, 'html_too_long', ['max' => $max]);

            return '';
        }

        return $clean;
    }

    /** @return list<array{network: string, url: string}> */
    private function social(mixed $value, int $max, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $this->fail($path, 'bad_list');

            return [];
        }

        if (count($value) > $max) {
            $this->fail($path, 'too_many_slot', ['max' => $max]);
        }

        $out = [];

        foreach (array_slice($value, 0, $max) as $i => $item) {
            $network = is_array($item) ? ($item['network'] ?? null) : null;
            $url = is_array($item) && is_string($item['url'] ?? null) ? trim($item['url']) : '';

            if (! in_array($network, HeaderCatalog::SOCIAL_NETWORKS, true)) {
                $this->fail("{$path}.{$i}.network", 'bad_choice');

                continue;
            }

            if (! AppearanceSettings::isSafeBannerUrl($url) || ! str_starts_with(strtolower($url), 'https://')) {
                $this->fail("{$path}.{$i}.url", 'bad_https_url');

                continue;
            }

            $out[] = ['network' => $network, 'url' => $url];
        }

        return $out;
    }

    /** @param  array<string, mixed>  $data  @param  list<string>  $allowed */
    private function unknownKeys(array $data, array $allowed, string $path): void
    {
        foreach (array_diff(array_keys($data), $allowed) as $key) {
            $this->fail($path, 'unknown_key', ['key' => mb_substr((string) $key, 0, 30)]);
        }
    }

    /**
     * At most one of each of these types in the whole layout.
     *
     * @param  array{rows: array<string, array<string, mixed>>}  $part
     * @param  list<string>  $types
     */
    private function limitOnce(array $part, string $layout, array $types): void
    {
        $seen = [];

        foreach ($part['rows'] as $row) {
            foreach (HeaderLayout::SLOTS as $slot) {
                foreach ($row[$slot] ?? [] as $element) {
                    if (in_array($element['type'], $types, true)) {
                        $seen[$element['type']] = ($seen[$element['type']] ?? 0) + 1;
                    }
                }
            }
        }

        foreach ($seen as $type => $count) {
            if ($count > 1) {
                $this->fail("{$layout}.rows", $type === 'logo' ? 'one_logo' : 'one_toggle');
            }
        }
    }

    private function isPlain(string $text): bool
    {
        $failed = false;
        (new PlainText)->validate('text', $text, static function () use (&$failed): void {
            $failed = true;
        });

        return ! $failed;
    }
}
