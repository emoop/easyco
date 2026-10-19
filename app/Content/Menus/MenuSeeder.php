<?php

namespace App\Content\Menus;

use Illuminate\Support\Facades\DB;

/**
 * Creates the two menus every shop has and fills them from what the shop has today (D3), so it looks the same right after the migration:
 *
 *  - `main` = the top-level categories in their current order (name, then id), each `dropdown` with its shown sub-categories listed automatically;
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
            $items = [];

            foreach (DB::table('catalog_categories')->whereNull('parent_id')->orderBy('name')->orderBy('id')->get(['id']) as $category) {
                $items[] = ['type' => 'category', 'target_id' => (int) $category->id, 'submenu' => 'dropdown', 'with_children' => true];
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
