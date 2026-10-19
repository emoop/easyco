<?php

namespace App\Content\Menus;

/**
 * Converts between a menu tree (what MenuValidator takes and MenuTree returns) and the state of the admin form (nested Filament repeaters), and maps a
 * validator path back onto the form field it belongs to.
 *
 * The form keeps three repeaters where the tree has one `children` list, because what a top-level item can hold depends on its submenu:
 *   dropdown -> `dropdown_items` (each item may have `sub_items`, one more level)
 *   mega     -> `columns` (each column has a `label` and its `column_items`)
 * The category and the page are two select fields (`category_id`, `page_id`) that become the tree's `target_id`.
 */
final class MenuFormState
{
    /**
     * @param  list<array<string, mixed>>  $tree
     * @return list<array<string, mixed>>
     */
    public static function toForm(array $tree): array
    {
        return array_map(function (array $item): array {
            $state = self::itemState($item);

            if ($item['submenu'] === 'dropdown') {
                $state['dropdown_items'] = array_map(function (array $child): array {
                    $childState = self::itemState($child);
                    $childState['sub_items'] = array_map(fn (array $leaf): array => self::itemState($leaf), $child['children']);

                    return $childState;
                }, $item['children']);
            }

            if ($item['submenu'] === 'mega') {
                $state['columns'] = array_map(fn (array $column): array => [
                    'label' => $column['label'],
                    'visible' => $column['visible'],
                    'column_items' => array_map(fn (array $leaf): array => self::itemState($leaf), $column['children']),
                ], $item['children']);
            }

            return $state;
        }, $tree);
    }

    /** @param  array<string, mixed>  $item */
    private static function itemState(array $item): array
    {
        return [
            'type' => $item['type'],
            'label' => $item['label'],
            'url' => $item['url'],
            'category_id' => $item['type'] === 'category' ? $item['target_id'] : null,
            'page_id' => $item['type'] === 'page' ? $item['target_id'] : null,
            'submenu' => $item['submenu'],
            'with_children' => $item['with_children'],
            'children_limit' => $item['children_limit'],
            'media_asset_id' => $item['media_asset_id'],
            'image_alt' => $item['image_alt'],
            'caption' => $item['caption'],
            'new_tab' => $item['new_tab'],
            'visible' => $item['visible'],
        ];
    }

    /**
     * The form state as a tree, plus the map of tree paths to form paths (both without the `data.` prefix of the Livewire property).
     *
     * @param  array<mixed>  $state
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>, 2: array<string, string>} tree, path map, type by path
     */
    public static function fromForm(array $state): array
    {
        $map = [];
        $types = [];
        $tree = [];

        foreach (array_keys($state) as $position => $formKey) {
            $item = self::itemTree($state[$formKey], "items.{$position}", "items.{$formKey}", $map, $types);
            $submenu = $item['submenu'];

            if ($submenu === 'dropdown') {
                foreach (array_keys((array) ($state[$formKey]['dropdown_items'] ?? [])) as $i => $childKey) {
                    $raw = $state[$formKey]['dropdown_items'][$childKey];
                    $child = self::itemTree($raw, "items.{$position}.children.{$i}", "items.{$formKey}.dropdown_items.{$childKey}", $map, $types);

                    foreach (array_keys((array) ($raw['sub_items'] ?? [])) as $j => $leafKey) {
                        $child['children'][] = self::itemTree($raw['sub_items'][$leafKey], "items.{$position}.children.{$i}.children.{$j}", "items.{$formKey}.dropdown_items.{$childKey}.sub_items.{$leafKey}", $map, $types);
                    }

                    $item['children'][] = $child;
                }
            } elseif ($submenu === 'mega') {
                foreach (array_keys((array) ($state[$formKey]['columns'] ?? [])) as $c => $columnKey) {
                    $raw = $state[$formKey]['columns'][$columnKey];
                    $path = "items.{$position}.children.{$c}";
                    $formPath = "items.{$formKey}.columns.{$columnKey}";
                    $map[$path] = $formPath;
                    $types[$path] = 'column';
                    $column = ['type' => 'column', 'label' => $raw['label'] ?? null, 'visible' => $raw['visible'] ?? true, 'children' => []];

                    foreach (array_keys((array) ($raw['column_items'] ?? [])) as $k => $leafKey) {
                        $column['children'][] = self::itemTree($raw['column_items'][$leafKey], "{$path}.children.{$k}", "{$formPath}.column_items.{$leafKey}", $map, $types);
                    }

                    $item['children'][] = $column;
                }
            }

            $tree[] = $item;
        }

        return [$tree, $map, $types];
    }

    /**
     * One item of the form as a tree item (its children are added by the caller).
     *
     * @param  array<string, mixed>  $raw
     * @param  array<string, string>  $map
     * @param  array<string, string>  $types
     * @return array<string, mixed>
     */
    private static function itemTree(array $raw, string $path, string $formPath, array &$map, array &$types): array
    {
        $type = (string) ($raw['type'] ?? '');
        $map[$path] = $formPath;
        $types[$path] = $type;

        return [
            'type' => $type,
            'label' => $raw['label'] ?? null,
            'url' => $raw['url'] ?? null,
            'target_id' => $type === 'category' ? ($raw['category_id'] ?? null) : ($type === 'page' ? ($raw['page_id'] ?? null) : null),
            'submenu' => $raw['submenu'] ?? 'none',
            'with_children' => (bool) ($raw['with_children'] ?? false),
            'children_limit' => $raw['children_limit'] ?? 12,
            'media_asset_id' => $raw['media_asset_id'] ?? null,
            'image_alt' => $raw['image_alt'] ?? null,
            'caption' => $raw['caption'] ?? null,
            'new_tab' => (bool) ($raw['new_tab'] ?? false),
            'visible' => (bool) ($raw['visible'] ?? true),
            'children' => [],
        ];
    }

    /**
     * The form field a validator path belongs to (e.g. `items.1.children.0.url` -> `items.{key}.dropdown_items.{key}.url`), or the nearest item.
     *
     * @param  array<string, string>  $map
     * @param  array<string, string>  $types
     */
    public static function fieldFor(string $path, array $map, array $types): string
    {
        $best = null;

        foreach ($map as $prefix => $target) {
            if (($path === $prefix || str_starts_with($path, $prefix.'.')) && ($best === null || strlen($prefix) > strlen($best))) {
                $best = $prefix;
            }
        }

        if ($best === null) {
            return 'items';
        }

        $rest = ltrim(substr($path, strlen($best)), '.');
        $field = explode('.', $rest)[0];

        if ($field === '' || $field === 'children') {
            return $map[$best];
        }

        $field = match ($field) {
            'target_id' => ($types[$best] ?? '') === 'page' ? 'page_id' : 'category_id',
            'media_asset_id' => 'image_file',
            default => $field,
        };

        return $map[$best].'.'.$field;
    }
}
