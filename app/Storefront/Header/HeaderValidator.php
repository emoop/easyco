<?php

namespace App\Storefront\Header;

use App\Storefront\Design\FieldKinds;

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

        foreach (array_diff(array_keys($layout), ['desktop', 'mobile', 'menu_keys', 'logo_images']) as $key) {
            $this->fail('layout', 'unknown_key', ['key' => mb_substr((string) $key, 0, 30)]);
        }

        $clean = [
            'desktop' => $this->desktop(is_array($layout['desktop'] ?? null) ? $layout['desktop'] : $default['desktop']),
            'mobile' => $this->mobile(is_array($layout['mobile'] ?? null) ? $layout['mobile'] : $default['mobile']),
        ];

        // DS-2a.1: only a COMPILED layout carries `menu_keys` (menu id => menu key, made by HeaderCompiler); a layout that names no menu has none, so it normalises exactly as before.
        $keys = $this->menuKeys($layout['menu_keys'] ?? []);

        $clean = $keys === [] ? $clean : $clean + ['menu_keys' => $keys];

        // LG-1: only a COMPILED layout carries `logo_images` (picture id => {url, width, height}, made by HeaderCompiler); a layout with no logo picture has none
        $logos = $this->logoImages($layout['logo_images'] ?? []);

        return $logos === [] ? $clean : $clean + ['logo_images' => $logos];
    }

    /** @return array<string, array{url: string, width: int, height: int}> */
    private function logoImages(mixed $images): array
    {
        if (! is_array($images)) {
            $this->fail('layout.logo_images', 'bad_list');

            return [];
        }

        $out = [];

        foreach ($images as $id => $image) {
            if (preg_match('/^[1-9][0-9]{0,9}$/', (string) $id) !== 1 || ! is_array($image) || ! is_string($image['url'] ?? null) || strlen($image['url']) > 500 || preg_match('#^(/|https?://)[^\s"<>\\\\]+$#', $image['url']) !== 1
                || ! is_int($image['width'] ?? null) || ! is_int($image['height'] ?? null) || $image['width'] < 1 || $image['height'] < 1 || $image['width'] > 20000 || $image['height'] > 20000) {
                $this->fail('layout.logo_images', 'bad_list');

                return [];
            }

            $out[(string) $id] = ['url' => $image['url'], 'width' => $image['width'], 'height' => $image['height']];
        }

        return $out;
    }

    /** @return array<string, string> menu id (digits) => menu key (`[a-z0-9-]{1,40}`), nothing else */
    private function menuKeys(mixed $keys): array
    {
        if (! is_array($keys)) {
            $this->fail('layout.menu_keys', 'bad_list');

            return [];
        }

        $out = [];

        foreach ($keys as $id => $key) {
            if (preg_match('/^[1-9][0-9]{0,9}$/', (string) $id) !== 1 || ! is_string($key) || preg_match('/^[a-z0-9-]{1,40}$/', $key) !== 1) {
                $this->fail('layout.menu_keys', 'bad_list');

                return [];
            }

            $out[(string) $id] = $key;
        }

        return $out;
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
        $this->unknownKeys($m, ['sticky', 'rows', 'drawer', 'drawer_side', 'drawer_upper'], 'mobile');
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

        $this->limitOnce(['rows' => $rows], 'mobile', HeaderCatalog::ONCE_PER_LAYOUT);

        return ['sticky' => $this->bool($m['sticky'] ?? true, 'mobile.sticky'), 'rows' => $rows, 'drawer' => $drawer, 'drawer_side' => $side, 'drawer_upper' => $this->bool($m['drawer_upper'] ?? true, 'mobile.drawer_upper')];
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
            $this->unknownKeys($row, ['enabled', 'height', 'bg', 'fg', 'text_size', 'left', 'center', 'right'], $path);

            $height = $this->int($row['height'] ?? $default, $min, $max, "{$path}.height", 'height_range', ['min' => $min, 'max' => $max]) ?? $default;
            $clean = ['enabled' => $this->bool($row['enabled'] ?? false, "{$path}.enabled"), 'height' => $height] + $this->look($row, $path);

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

        $this->limitOnce(['rows' => $out], $layout, HeaderCatalog::ONCE_PER_LAYOUT);

        return $out;
    }

    /**
     * DS-2a: the OPTIONAL look of a row: `bg` / `fg` (a hex colour, `#rgb` is stored as `#rrggbb`) and `text_size` (s | m | l). A key that is absent, null or empty is NOT in the output
     * (an untouched layout normalises exactly as before).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    private function look(array $row, string $path): array
    {
        $out = [];

        foreach (['bg', 'fg'] as $key) {
            $color = FieldKinds::color($row[$key] ?? null, "{$path}.{$key}", $this->failer());

            if ($color !== null) {
                $out[$key] = strlen($color) === 4 ? '#'.$color[1].$color[1].$color[2].$color[2].$color[3].$color[3] : $color;
            }
        }

        $size = $row['text_size'] ?? null;

        if ($size !== null && $size !== '') {
            in_array($size, HeaderLayout::TEXT_SIZES, true) ? $out['text_size'] = $size : $this->fail("{$path}.text_size", 'bad_choice');
        }

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

        // MENU-2: a menu element shows the menu of its LOCATION; the old `source` setting is ignored (and dropped) so a layout stored before still validates.
        if ($type === 'menu') {
            unset($settings['source']);
        }

        if ($type === 'logo') {
            [$min, $max, $default] = HeaderLayout::LOGO_HEIGHTS[$layout];
            $schema['height'] = ['kind' => 'int', 'min' => $min, 'max' => $max, 'default' => $default];
        }

        foreach (array_diff(array_keys($settings), array_keys($schema)) as $key) {
            $this->fail("{$path}.settings.{$key}", 'unknown_setting', ['key' => mb_substr((string) $key, 0, 30)]);
        }

        $clean = [];

        foreach ($schema as $name => $field) {
            $value = $this->setting($settings[$name] ?? ($field['default'] ?? null), $field, "{$path}.settings.{$name}");

            // LG-1: a picture field that is empty (= its default) is NOT stored and emits nothing: a layout without a logo picture is byte for byte what it was
            if ($value === null && ($field['kind'] ?? null) === 'media') {
                continue;
            }

            $clean[$name] = $value;
        }

        return ['type' => $type, 'settings' => $clean];
    }

    /**
     * One setting of an element: DS-1, the shared FieldKinds validates it (the header's own options: HeaderCatalog::KIND_OPTIONS); this validator turns a failure into its message.
     *
     * @param  array<string, mixed>  $field
     */
    private function setting(mixed $value, array $field, string $path): mixed
    {
        $clean = FieldKinds::validate($value, $field + HeaderCatalog::KIND_OPTIONS, $path, $this->failer());

        // DS-2c: a colour is stored as `#rrggbb` (the compiler writes only that)
        if (($field['kind'] ?? null) === 'color' && is_string($clean) && strlen($clean) === 4) {
            $clean = '#'.$clean[1].$clean[1].$clean[2].$clean[2].$clean[3].$clean[3];
        }

        return $clean;
    }

    /** @return callable(string, string, array<string, mixed>=): void */
    private function failer(): callable
    {
        return fn (string $path, string $code, array $params = []) => $this->fail($path, $code, $params);
    }

    /** @param  array<string, mixed>  $params */
    private function int(mixed $value, int $min, int $max, string $path, string $code, array $params): ?int
    {
        return FieldKinds::int($value, $min, $max, $path, $code, $params, $this->failer());
    }

    private function bool(mixed $value, string $path): bool
    {
        return FieldKinds::bool($value, $path, $this->failer());
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
}
