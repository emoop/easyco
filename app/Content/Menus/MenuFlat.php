<?php

namespace App\Content\Menus;

/**
 * The editor's view of a menu (MENU-2, menu-editor-v2-design.md M-D5/M-D6): a FLAT ordered list of items, each with a `depth` (0-2), the way a person sees and drags it.
 * The server derives the parents from the depths, so a save is one call with the whole list; MenuValidator then judges the TREE. Nothing here trusts the browser: a first item
 * that is not at depth 0, a jump of more than one level and a depth above 2 are refused before any row is written, with the index of the item.
 */
final class MenuFlat
{
    public const MAX_DEPTH = 2;

    /**
     * The flat list as a tree.
     *
     * @param  array<mixed>  $flat
     * @return array{0: list<array<string, mixed>>, 1: array<string, int>, 2: array<int, string>} the tree, the map "tree path => index in the flat list", the depth errors by index
     */
    public static function toTree(array $flat): array
    {
        $map = [];
        $errors = [];
        $items = [];
        $children = [];
        $last = [];
        $previousDepth = -1;

        foreach (array_values($flat) as $index => $raw) {
            $raw = is_array($raw) ? $raw : [];
            $depth = $raw['depth'] ?? null;

            if (! is_int($depth) && ! (is_string($depth) && ctype_digit($depth))) {
                $errors[$index] = __('menus.errors.depth_value');
                $depth = 0;
            }

            $depth = (int) $depth;

            if ($depth > self::MAX_DEPTH) {
                $errors[$index] ??= __('menus.errors.depth_max');
                $depth = self::MAX_DEPTH;
            }

            if ($depth > $previousDepth + 1) {
                $errors[$index] ??= $index === 0 ? __('menus.errors.depth_first') : __('menus.errors.depth_jump');
                $depth = $previousDepth + 1;
            }

            $items[$index] = array_intersect_key($raw, array_flip(MenuValidator::FIELDS));
            $children[$depth === 0 ? -1 : $last[$depth - 1]][] = $index;
            $last[$depth] = $index;
            $previousDepth = $depth;
        }

        $build = function (int $parent, string $prefix) use (&$build, $items, $children, &$map): array {
            $out = [];

            foreach ($children[$parent] ?? [] as $n => $index) {
                $path = $prefix.$n;
                $map[$path] = $index;
                $out[] = $items[$index] + ['children' => $build($index, $path.'.children.')];
            }

            return $out;
        };
        $tree = $build(-1, 'items.');

        return [$tree, $map, $errors];
    }

    /**
     * A tree (MenuTree::forMenu) as the flat list of the editor.
     *
     * @param  list<array<string, mixed>>  $tree
     * @return list<array<string, mixed>> every item with its fields and `depth`, without `children`
     */
    public static function toFlat(array $tree, int $depth = 0): array
    {
        $out = [];

        foreach ($tree as $item) {
            $children = $item['children'] ?? [];
            unset($item['children']);
            $out[] = ['depth' => $depth] + $item;
            array_push($out, ...self::toFlat($children, $depth + 1));
        }

        return $out;
    }

    /**
     * The validator's / writer's messages (keyed by tree path) as messages per item of the flat list: [index => [field => message]]. `_item` is a message about the item as a whole
     * (its place, its sub-items); `_menu` is about the menu (its name, the size of the list, the locations).
     *
     * @param  array<string, string>  $errors  tree path => message
     * @param  array<string, int>  $map  tree path => flat index
     * @return array{items: array<int, array<string, string>>, menu: array<string, string>}
     */
    public static function errorsByItem(array $errors, array $map): array
    {
        $items = [];
        $menu = [];

        foreach ($errors as $path => $message) {
            $best = null;

            foreach ($map as $prefix => $index) {
                if (($path === $prefix || str_starts_with($path, $prefix.'.')) && ($best === null || strlen($prefix) > strlen($best))) {
                    $best = $prefix;
                }
            }

            if ($best === null) {
                $menu[$path] = $message;

                continue;
            }

            $rest = ltrim(substr($path, strlen($best)), '.');
            $field = explode('.', $rest)[0];
            $field = $field === '' || $field === 'children' ? '_item' : $field;
            $items[$map[$best]][$field] ??= $message;
        }

        return ['items' => $items, 'menu' => $menu];
    }
}
