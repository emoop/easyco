<?php

namespace App\Content\Menus;

use Illuminate\Support\Collection;

/** Reads the rows of `menu_items` back into the nested arrays MenuValidator takes and the compiler walks (each item with its `children`). */
final class MenuTree
{
    /** @return list<array<string, mixed>> */
    public static function forMenu(int $menuId): array
    {
        return self::nest(MenuItemModel::query()->where('menu_id', $menuId)->orderBy('sort')->orderBy('id')->get());
    }

    /**
     * Every menu with its tree, in TWO queries.
     *
     * @return array<string, array{menu: MenuModel, items: list<array<string, mixed>>}>
     */
    public static function all(): array
    {
        $rows = MenuItemModel::query()->orderBy('sort')->orderBy('id')->get()->groupBy('menu_id');
        $out = [];

        foreach (MenuModel::query()->orderBy('id')->get() as $menu) {
            $out[$menu->key] = ['menu' => $menu, 'items' => self::nest($rows->get($menu->id, collect()))];
        }

        return $out;
    }

    /**
     * @param  Collection<int, MenuItemModel>  $rows
     * @return list<array<string, mixed>>
     */
    private static function nest(Collection $rows): array
    {
        $byParent = $rows->groupBy(fn (MenuItemModel $row): string => (string) ($row->parent_id ?? 0));
        $build = function (int $parentId, array $trail) use (&$build, $byParent): array {
            $out = [];

            foreach ($byParent->get((string) $parentId, collect()) as $row) {
                if (in_array($row->id, $trail, true)) {
                    continue;
                }

                $item = array_intersect_key($row->attributesToArray(), array_flip(MenuValidator::FIELDS));
                $item['children'] = $build($row->id, [...$trail, $row->id]);
                $out[] = $item;
            }

            return $out;
        };

        return $build(0, []);
    }

    /** The number of items in a nested tree. @param  list<array<string, mixed>>  $items */
    public static function count(array $items): int
    {
        $n = 0;

        foreach ($items as $item) {
            $n += 1 + self::count($item['children'] ?? []);
        }

        return $n;
    }
}
