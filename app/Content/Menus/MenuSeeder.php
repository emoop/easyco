<?php

namespace App\Content\Menus;

use Illuminate\Support\Facades\DB;

/**
 * Creates the two menus every shop has (D3, MENU-2) and fills them from what the shop has today, so it looks the same right after the migration:
 *
 *  - `main` = the top-level categories in their current order (name, then id), each a `dropdown` holding its direct sub-categories as explicit items;
 *  - `footer` = created EMPTY (DS-3.1: pages have no footer flag any more; the merchant places pages with a footer menu block).
 *
 * IDEMPOTENT: a menu that already exists is left exactly as it is (the merchant may have changed it); a second run adds nothing.
 */
final class MenuSeeder
{
    public static function run(): void
    {
        $writer = app(MenuWriter::class);

        if (! MenuModel::query()->where('key', 'main')->exists()) {
            $menu = MenuModel::create(['key' => 'main', 'name' => __('menus.seed.main')]);
            $children = DB::table('catalog_categories')->whereNotNull('parent_id')->orderBy('name')->orderBy('id')->get(['id', 'parent_id'])->groupBy('parent_id');
            $items = [];

            foreach (DB::table('catalog_categories')->whereNull('parent_id')->orderBy('name')->orderBy('id')->get(['id']) as $category) {
                $sub = $children->get($category->id, collect())->map(fn (object $row): array => ['type' => 'category', 'target_id' => (int) $row->id])->all();
                $items[] = ['type' => 'category', 'target_id' => (int) $category->id, 'submenu' => $sub === [] ? 'none' : 'dropdown', 'children' => $sub];
            }

            $writer->insert($menu->id, null, MenuValidator::validate($items)->items);
        }

        if (! MenuModel::query()->where('key', 'footer')->exists()) {
            MenuModel::create(['key' => 'footer', 'name' => __('menus.seed.footer')]);
        }
    }
}
