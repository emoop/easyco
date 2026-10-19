<?php

namespace App\Content\Menus;

use App\Rules\PlainText;
use App\Settings\AppearanceSettings;

/**
 * THE gate of a menu's tree (D3, storefront-customization-design.md 14): the admin form, the seeder and the tests all pass through here before
 * a row is written. Nothing unknown gets in: an unknown key, type or submenu mode is refused, labels are plain text with a length, links are
 * root-relative or https only (AppearanceSettings::isSafeBannerUrl: the one rule), an image is an asset id.
 *
 * Structure (depth <= 3, the type allowed at each place):
 *   top level           link | category | page        (submenu none | dropdown | mega: top level only)
 *   under a dropdown    link | category | page         (and these may hold one more level of link | category | page)
 *   under a mega        column                        (the column's label is its heading; it holds link | category | page | image)
 *   Limits: 12 top-level items, 15 items per column, 300 items per menu, 2 images per mega panel.
 *
 * Paths look like `items.2.children.0.url`; the admin page maps them back onto its form fields.
 */
final class MenuValidator
{
    public const MAX_TOP = 12;

    public const MAX_COLUMN = 15;

    public const MAX_TOTAL = 300;

    public const MAX_IMAGES = 2;

    public const FIELDS = ['type', 'label', 'url', 'target_id', 'submenu', 'with_children', 'children_limit', 'media_asset_id', 'image_alt', 'caption', 'new_tab', 'visible'];

    public const SUBMENUS = ['none', 'dropdown', 'mega'];

    /** The types allowed under each kind of parent. */
    private const ALLOWED = [
        'root' => ['link', 'category', 'page'],
        'dropdown' => ['link', 'category', 'page'],
        'nested' => ['link', 'category', 'page'],
        'mega' => ['column'],
        'column' => ['link', 'category', 'page', 'image'],
    ];

    /** @var array<string, string> */
    private array $errors = [];

    private int $total = 0;

    /** @param  array<mixed>  $items */
    public static function validate(array $items): MenuValidation
    {
        $validator = new self();
        $clean = $validator->level($items, 'items', 'root');

        if (count($clean) > self::MAX_TOP) {
            $validator->fail('items', 'top_max', ['max' => self::MAX_TOP]);
        }

        if ($validator->total > self::MAX_TOTAL) {
            $validator->fail('items', 'total_max', ['max' => self::MAX_TOTAL]);
        }

        return new MenuValidation($clean, $validator->errors);
    }

    /** @param  array<string, mixed>  $params */
    private function fail(string $path, string $code, array $params = []): void
    {
        $this->errors[$path] ??= __('menus.errors.'.$code, $params);
    }

    /** @return list<array<string, mixed>> */
    private function level(mixed $items, string $path, string $kind): array
    {
        if (! is_array($items)) {
            $this->fail($path, 'not_array');

            return [];
        }

        $clean = [];

        foreach (array_values($items) as $index => $raw) {
            $item = $this->item($raw, "{$path}.{$index}", $kind);

            if ($item !== null) {
                $clean[] = $item;
            }
        }

        return $clean;
    }

    /** @return array<string, mixed>|null */
    private function item(mixed $raw, string $path, string $kind): ?array
    {
        $this->total++;

        if (! is_array($raw)) {
            $this->fail($path, 'not_array');

            return null;
        }

        foreach (array_keys($raw) as $key) {
            if ($key !== 'children' && ! in_array($key, self::FIELDS, true)) {
                $this->fail("{$path}.{$key}", 'unknown_key');
            }
        }

        $type = is_string($raw['type'] ?? null) ? $raw['type'] : '';

        if (! in_array($type, self::ALLOWED[$kind], true)) {
            $this->fail("{$path}.type", 'type_here');

            return null;
        }

        $item = [
            'type' => $type,
            'label' => $this->label($raw['label'] ?? null, $type === 'link', $path),
            'url' => $this->url($raw['url'] ?? null, $type === 'link', in_array($type, ['link', 'image'], true), $path),
            'target_id' => $this->target($raw['target_id'] ?? null, $type, $path),
            'submenu' => $this->submenu($raw['submenu'] ?? 'none', $kind, $path),
            'with_children' => $this->withChildren($raw['with_children'] ?? false, $type, $path),
            'children_limit' => $this->limit($raw['children_limit'] ?? 12, $path),
            'media_asset_id' => $this->asset($raw['media_asset_id'] ?? null, $type, $path),
            'image_alt' => $this->plain($raw['image_alt'] ?? null, 200, "{$path}.image_alt", 'alt_long'),
            'caption' => $this->plain($raw['caption'] ?? null, 80, "{$path}.caption", 'caption_long'),
            'new_tab' => $this->bool($raw['new_tab'] ?? false, "{$path}.new_tab"),
            'visible' => $this->bool($raw['visible'] ?? true, "{$path}.visible"),
        ];

        if ($type === 'image') {
            $item['label'] = null;
        } else {
            $item['image_alt'] = null;
            $item['caption'] = null;
        }

        if ($item['with_children'] && $kind === 'root' && $item['submenu'] === 'mega') {
            $this->fail("{$path}.with_children", 'with_children_mega');
        }

        $item['children'] = $this->children($raw['children'] ?? [], $item, $path, $kind);

        return $item;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<array<string, mixed>>
     */
    private function children(mixed $children, array $item, string $path, string $kind): array
    {
        if ($children === [] || $children === null) {
            return [];
        }

        $childKind = match (true) {
            $item['type'] === 'column' => 'column',
            $kind === 'root' && $item['submenu'] === 'dropdown' => 'dropdown',
            $kind === 'root' && $item['submenu'] === 'mega' => 'mega',
            $kind === 'dropdown' => 'nested',
            default => null,
        };

        if ($childKind === null) {
            $this->fail("{$path}.children", $kind === 'root' ? 'children_need_submenu' : 'too_deep');

            return [];
        }

        $clean = $this->level($children, "{$path}.children", $childKind);

        if ($childKind === 'column' && count($clean) > self::MAX_COLUMN) {
            $this->fail("{$path}.children", 'column_max', ['max' => self::MAX_COLUMN]);
        }

        if ($childKind === 'mega') {
            $images = 0;

            foreach ($clean as $column) {
                foreach ($column['children'] as $child) {
                    $images += $child['type'] === 'image' ? 1 : 0;
                }
            }

            if ($images > self::MAX_IMAGES) {
                $this->fail("{$path}.children", 'images_max', ['max' => self::MAX_IMAGES]);
            }
        }

        // A column's items and the second level below a dropdown item cannot hold more: depth 3 is the last.
        foreach ($clean as $index => $child) {
            if ($child['children'] !== [] && in_array($childKind, ['column', 'nested'], true)) {
                $this->fail("{$path}.children.{$index}.children", 'too_deep');
                $clean[$index]['children'] = [];
            }
        }

        return $clean;
    }

    private function label(mixed $value, bool $required, string $path): ?string
    {
        $text = $this->plain($value, 120, "{$path}.label", 'label_long');

        if ($required && $text === null) {
            $this->fail("{$path}.label", 'label_required');
        }

        return $text;
    }

    private function plain(mixed $value, int $max, string $path, string $longCode): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            $this->fail($path, 'label_plain');

            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $max) {
            $this->fail($path, $longCode, ['max' => $max]);

            return null;
        }

        $bad = false;
        (new PlainText)->validate('text', $value, static function () use (&$bad): void {
            $bad = true;
        });

        if ($bad) {
            $this->fail($path, 'label_plain');

            return null;
        }

        return $value;
    }

    private function url(mixed $value, bool $required, bool $allowed, string $path): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        if ($value === null || $value === '') {
            if ($required) {
                $this->fail("{$path}.url", 'url_required');
            }

            return null;
        }

        if (! $allowed) {
            $this->fail("{$path}.url", 'url_not_allowed');

            return null;
        }

        if (! is_string($value) || mb_strlen($value) > 500 || ! AppearanceSettings::isSafeBannerUrl($value)) {
            $this->fail("{$path}.url", 'url_unsafe');

            return null;
        }

        return $value;
    }

    private function target(mixed $value, string $type, string $path): ?int
    {
        $isTarget = in_array($type, ['category', 'page'], true);

        if ($value === null || $value === '') {
            if ($isTarget) {
                $this->fail("{$path}.target_id", 'target_required');
            }

            return null;
        }

        if (! $isTarget) {
            $this->fail("{$path}.target_id", 'target_not_allowed');

            return null;
        }

        if (! is_numeric($value) || (int) $value < 1) {
            $this->fail("{$path}.target_id", 'target_required');

            return null;
        }

        return (int) $value;
    }

    private function submenu(mixed $value, string $kind, string $path): string
    {
        if (! is_string($value) || ! in_array($value, self::SUBMENUS, true)) {
            $this->fail("{$path}.submenu", 'submenu_value');

            return 'none';
        }

        if ($value !== 'none' && $kind !== 'root') {
            $this->fail("{$path}.submenu", 'submenu_depth');

            return 'none';
        }

        return $value;
    }

    private function withChildren(mixed $value, string $type, string $path): bool
    {
        $on = $this->bool($value, "{$path}.with_children");

        if ($on && $type !== 'category') {
            $this->fail("{$path}.with_children", 'with_children_type');

            return false;
        }

        return $on;
    }

    private function limit(mixed $value, string $path): int
    {
        if (! is_numeric($value) || (int) $value < 1 || (int) $value > 50) {
            $this->fail("{$path}.children_limit", 'limit_range');

            return 12;
        }

        return (int) $value;
    }

    private function asset(mixed $value, string $type, string $path): ?int
    {
        if ($value === null || $value === '') {
            if ($type === 'image') {
                $this->fail("{$path}.media_asset_id", 'asset_required');
            }

            return null;
        }

        if ($type !== 'image') {
            $this->fail("{$path}.media_asset_id", 'asset_not_allowed');

            return null;
        }

        if (! is_numeric($value) || (int) $value < 1) {
            $this->fail("{$path}.media_asset_id", 'asset_required');

            return null;
        }

        return (int) $value;
    }

    private function bool(mixed $value, string $path): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (in_array($value, [0, 1, '0', '1'], true)) {
            return (bool) (int) $value;
        }

        $this->fail($path, 'bool');

        return false;
    }
}
