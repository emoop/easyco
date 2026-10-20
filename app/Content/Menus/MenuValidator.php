<?php

namespace App\Content\Menus;

use App\Rules\PlainText;
use App\Settings\AppearanceSettings;

/**
 * THE gate of a menu's tree (D3, MENU-2): the admin form, the seeder, the migration and the tests all pass through here before a row is written. Nothing unknown gets
 * in: an unknown key, type or submenu mode is refused, labels are plain text with a length, links are root-relative or https only (AppearanceSettings::isSafeBannerUrl:
 * the one rule), a badge colour is a preset name or `#RRGGBB`.
 *
 * Structure (depth <= 3, the type allowed at each place):
 *   top level           link | category | page | product    (submenu none | dropdown | mega: top level only)
 *   under a dropdown    link | category | page | product     (and these may hold one more level of the same four)
 *   under a mega        column x EXACTLY `mega_columns` (1-4)   (a column has an optional heading as its label, no target, no link, no badge; MENU-3)
 *   under a column      link | category | page | product     (the links of the column; at most 15)
 *   `column` is allowed ONLY directly under a mega item, and a mega item holds nothing else.
 *   Limits: 12 top-level items, 4 columns per mega item, 15 links per column, 300 items per menu, 12 distinct custom badge colours per menu.
 *
 * Paths look like `items.2.children.0.url`; the admin page maps them back onto its form fields.
 */
final class MenuValidator
{
    public const MAX_TOP = 12;

    public const MAX_COLUMNS = 4;

    public const MAX_HEADING = 60;

    public const MAX_COLUMN = 15;

    public const MAX_TOTAL = 300;

    public const MAX_CUSTOM_COLORS = 12;

    public const FIELDS = ['type', 'label', 'url', 'target_id', 'submenu', 'html_block_id', 'mega_columns', 'badge_text', 'badge_color', 'new_tab', 'visible'];

    public const SUBMENUS = ['none', 'dropdown', 'mega'];

    /** The six badge colours that need no stylesheet of their own (storefront.css has a class for each). */
    public const BADGE_PRESETS = ['red', 'green', 'orange', 'blue', 'dark', 'grey'];

    /** The types allowed under each kind of parent. */
    private const ALLOWED = [
        'root' => ['link', 'category', 'page', 'product'],
        'dropdown' => ['link', 'category', 'page', 'product'],
        'nested' => ['link', 'category', 'page', 'product'],
        'mega' => ['column'],
        'column' => ['link', 'category', 'page', 'product'],
    ];

    /** @var array<string, string> */
    private array $errors = [];

    private int $total = 0;

    /** @var array<string, true> */
    private array $customColors = [];

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

        if (count($validator->customColors) > self::MAX_CUSTOM_COLORS) {
            $validator->fail('items', 'colors_max', ['max' => self::MAX_CUSTOM_COLORS]);
        }

        return new MenuValidation($clean, $validator->errors);
    }

    /** True for a preset name or `#RRGGBB`. */
    public static function isBadgeColor(mixed $value): bool
    {
        return is_string($value) && (in_array($value, self::BADGE_PRESETS, true) || AppearanceSettings::isHex($value));
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
            $this->fail("{$path}.type", $type === 'column' ? 'column_here' : 'type_here');

            return null;
        }

        $submenu = $this->submenu($raw['submenu'] ?? 'none', $kind, $path);
        $item = [
            'type' => $type,
            'label' => $this->label($raw['label'] ?? null, $type === 'link', $path, $type === 'column' ? self::MAX_HEADING : 120),
            'url' => $this->url($raw['url'] ?? null, $type === 'link', $type === 'link', $path),
            'target_id' => $this->target($raw['target_id'] ?? null, $type, $path),
            'submenu' => $submenu,
            'html_block_id' => $this->block($raw['html_block_id'] ?? null, $kind, $submenu, $path),
            'mega_columns' => $this->megaColumns($raw['mega_columns'] ?? null, $kind, $submenu, $path),
            'badge_text' => $this->plain($raw['badge_text'] ?? null, 16, "{$path}.badge_text", 'badge_long'),
            'badge_color' => $this->color($raw['badge_color'] ?? null, $path),
            'new_tab' => $this->bool($raw['new_tab'] ?? false, "{$path}.new_tab"),
            'visible' => $this->bool($raw['visible'] ?? true, "{$path}.visible"),
        ];

        if ($item['badge_text'] === null) {
            $item['badge_color'] = null; // a colour with no text is nothing
        }

        if ($type === 'column') {
            if ($item['badge_text'] !== null) {
                $this->fail("{$path}.badge_text", 'badge_here');
            }

            $item['badge_text'] = null;
            $item['badge_color'] = null;
        }

        $item['children'] = $this->children($raw['children'] ?? [], $item, $path, $kind);

        // A mega item holds EXACTLY as many columns as it says (an empty column is a column): the editor and the storefront both rely on it.
        if ($kind === 'root' && $submenu === 'mega' && $item['mega_columns'] !== null) {
            $found = count($item['children']);

            if ($found > self::MAX_COLUMNS) {
                $this->fail("{$path}.children", 'columns_max', ['max' => self::MAX_COLUMNS]);
            } elseif ($found !== $item['mega_columns']) {
                $this->fail("{$path}.children", 'columns_count', ['count' => $item['mega_columns']]);
            }
        }

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
            $kind === 'root' && $item['submenu'] === 'dropdown' => 'dropdown',
            $kind === 'root' && $item['submenu'] === 'mega' => 'mega',
            $kind === 'dropdown' => 'nested',
            $kind === 'mega' => 'column',
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

        // The last level holds nothing more: depth 3 is the end.
        foreach ($clean as $index => $child) {
            if ($child['children'] !== [] && in_array($childKind, ['column', 'nested'], true)) {
                $this->fail("{$path}.children.{$index}.children", 'too_deep');
                $clean[$index]['children'] = [];
            }
        }

        return $clean;
    }

    private function label(mixed $value, bool $required, string $path, int $max = 120): ?string
    {
        $text = $this->plain($value, $max, "{$path}.label", 'label_long');

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
        $isTarget = in_array($type, ['category', 'page', 'product'], true);

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

    /** The HTML block of a mega item: a block id, on a top-level item whose submenu is `mega` only. */
    private function block(mixed $value, string $kind, string $submenu, string $path): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($kind !== 'root' || $submenu !== 'mega') {
            $this->fail("{$path}.html_block_id", 'block_here');

            return null;
        }

        if (! is_numeric($value) || (int) $value < 1) {
            $this->fail("{$path}.html_block_id", 'block_value');

            return null;
        }

        return (int) $value;
    }

    /** The number of columns of a mega item: 1-4, on a top-level item whose submenu is `mega` and nowhere else. */
    private function megaColumns(mixed $value, string $kind, string $submenu, string $path): ?int
    {
        $isMega = $kind === 'root' && $submenu === 'mega';

        if ($value === null || $value === '') {
            if ($isMega) {
                $this->fail("{$path}.mega_columns", 'mega_columns_required');
            }

            return null;
        }

        if (! $isMega) {
            $this->fail("{$path}.mega_columns", 'mega_columns_here');

            return null;
        }

        if (! is_numeric($value) || (int) $value < 1 || (int) $value > self::MAX_COLUMNS) {
            $this->fail("{$path}.mega_columns", 'mega_columns_range', ['max' => self::MAX_COLUMNS]);

            return null;
        }

        return (int) $value;
    }

    private function color(mixed $value, string $path): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! self::isBadgeColor($value)) {
            $this->fail("{$path}.badge_color", 'badge_color');

            return null;
        }

        if (AppearanceSettings::isHex($value)) {
            $value = strtolower($value);
            $this->customColors[$value] = true;
        }

        return $value;
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
