<?php

namespace App\Content\Menus;

use Illuminate\Support\Facades\DB;

/**
 * Creates the two menus every shop has (D3, MENU-2) and fills them from what the shop has today, so it looks the same right after the migration:
 *
 *  - `main` = the top-level categories in their current order (name, then id), each a `dropdown` holding its direct sub-categories as explicit items;
 *  - `footer` = the pages flagged `in_footer`, by `footer_order` then title (the compiler drops the ones that are not published).
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
            $menu = MenuModel::create(['key' => 'footer', 'name' => __('menus.seed.footer')]);
            $items = [];

            foreach (DB::table('pages')->where('in_footer', 1)->orderBy('footer_order')->orderBy('title')->orderBy('id')->get(['id']) as $page) {
                $items[] = ['type' => 'page', 'target_id' => (int) $page->id];
            }

            $writer->insert($menu->id, null, MenuValidator::validate($items)->items);
        }
    }
}
