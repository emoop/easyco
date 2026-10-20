<?php

namespace App\Content\Menus;

use App\Storefront\Menu\MenuRecompiler;
use Illuminate\Support\Facades\DB;

/**
 * The data step of MENU-3 (menu-editor-v2-design.md, "MENU-3 as built"): a mega item's columns become real `column` items, so the one model serves a 3-level menu (a heading on
 * every column) and a 2-level one (no heading). For every top-level mega item that has no `mega_columns` yet:
 *
 *  - a `heading` child becomes a `column` with the same label and the same children;
 *  - a link / category / page / product child becomes a `column` whose heading is that item's label (or the name of its target) and whose FIRST link is that item itself, then its
 *    former children, so every link that showed before still shows, in the same order;
 *  - more than 4 columns: columns 5 and up are merged into the 4th (their links are appended in order; their headings go);
 *  - `mega_columns` = the number of columns (a mega item with no children gets 3 empty columns).
 *
 * A heading that is not under a mega item (the old validator never allowed one) is dissolved: its children take its place. Then the type CHECK is narrowed to the final five types and
 * the menus are compiled again. IDEMPOTENT: a mega item with `mega_columns` is left alone, a second run finds nothing.
 */
final class Menu3Migrator
{
    /** @return array<string, int> megas converted, headings turned into columns, items promoted to columns, columns merged, links moved by the merge, empty megas filled, strays dissolved */
    public static function run(): array
    {
        $counts = ['megas' => 0, 'headings' => 0, 'promoted' => 0, 'merged_columns' => 0, 'merged_links' => 0, 'empty' => 0, 'strays' => 0];

        DB::transaction(function () use (&$counts): void {
            $megas = DB::table('menu_items')->whereNull('parent_id')->where('submenu', 'mega')->whereNull('mega_columns')->orderBy('id')->get();

            foreach ($megas as $mega) {
                self::convert($mega, $counts);
                $counts['megas']++;
            }

            self::dissolveStrayHeadings($counts);
        });

        self::narrowTypeCheck();
        app(MenuRecompiler::class)->now();

        return $counts;
    }

    /** @param  array<string, int>  $counts */
    private static function convert(object $mega, array &$counts): void
    {
        $now = now();
        $columns = [];

        foreach (DB::table('menu_items')->where('parent_id', $mega->id)->orderBy('sort')->orderBy('id')->get() as $child) {
            if ($child->type === 'heading') {
                DB::table('menu_items')->where('id', $child->id)->update(['type' => 'column', 'label' => self::heading($child->label), 'url' => null, 'target_id' => null, 'badge_text' => null, 'badge_color' => null]);
                $columns[] = (int) $child->id;
                $counts['headings']++;

                continue;
            }

            if ($child->type === 'column') {
                $columns[] = (int) $child->id; // already a column (a second run over a half-converted menu)

                continue;
            }

            $column = (int) DB::table('menu_items')->insertGetId([
                'menu_id' => $child->menu_id, 'parent_id' => $mega->id, 'sort' => $child->sort, 'type' => 'column', 'label' => self::heading($child->label ?: self::targetName($child)),
                'submenu' => 'none', 'new_tab' => 0, 'visible' => $child->visible, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $former = DB::table('menu_items')->where('parent_id', $child->id)->orderBy('sort')->orderBy('id')->pluck('id')->all();
            DB::table('menu_items')->where('id', $child->id)->update(['parent_id' => $column, 'sort' => 0]);

            foreach ($former as $position => $id) {
                DB::table('menu_items')->where('id', $id)->update(['parent_id' => $column, 'sort' => $position + 1]);
            }

            $columns[] = $column;
            $counts['promoted']++;
        }

        if (count($columns) > 4) {
            $last = $columns[3];
            $sort = (int) (DB::table('menu_items')->where('parent_id', $last)->max('sort') ?? -1) + 1;

            foreach (array_slice($columns, 4) as $extra) {
                foreach (DB::table('menu_items')->where('parent_id', $extra)->orderBy('sort')->orderBy('id')->pluck('id') as $id) {
                    DB::table('menu_items')->where('id', $id)->update(['parent_id' => $last, 'sort' => $sort++]);
                    $counts['merged_links']++;
                }

                DB::table('menu_items')->where('id', $extra)->delete();
                $counts['merged_columns']++;
            }

            $columns = array_slice($columns, 0, 4);
        }

        if ($columns === []) {
            for ($i = 0; $i < 3; $i++) {
                $columns[] = (int) DB::table('menu_items')->insertGetId([
                    'menu_id' => $mega->menu_id, 'parent_id' => $mega->id, 'sort' => $i, 'type' => 'column', 'label' => null, 'submenu' => 'none', 'new_tab' => 0, 'visible' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            $counts['empty']++;
        }

        foreach ($columns as $position => $id) {
            DB::table('menu_items')->where('id', $id)->update(['sort' => $position]);
        }

        DB::table('menu_items')->where('id', $mega->id)->update(['mega_columns' => count($columns)]);
    }

    private static function heading(mixed $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : mb_substr($text, 0, MenuValidator::MAX_HEADING);
    }

    /** The name a category / page / product item shows when it has no label of its own. */
    private static function targetName(object $item): ?string
    {
        $name = match ($item->type) {
            'category' => DB::table('catalog_categories')->where('id', $item->target_id)->value('name'),
            'page' => DB::table('pages')->where('id', $item->target_id)->value('title'),
            'product' => DB::table('catalog_products')->where('id', $item->target_id)->value('name'),
            default => null,
        };

        return $name === null ? null : (string) $name;
    }

    /** @param  array<string, int>  $counts */
    private static function dissolveStrayHeadings(array &$counts): void
    {
        foreach (DB::table('menu_items')->where('type', 'heading')->orderBy('id')->get() as $heading) {
            $children = DB::table('menu_items')->where('parent_id', $heading->id)->orderBy('sort')->orderBy('id')->pluck('id')->all();
            $sort = (int) $heading->sort;

            // the children take the heading's place, in order, among its siblings (the siblings after it move down)
            DB::table('menu_items')->where('menu_id', $heading->menu_id)->where('parent_id', $heading->parent_id)->where('sort', '>', $heading->sort)->increment('sort', count($children));

            foreach ($children as $position => $id) {
                DB::table('menu_items')->where('id', $id)->update(['parent_id' => $heading->parent_id, 'sort' => $sort + $position]);
            }

            DB::table('menu_items')->where('id', $heading->id)->delete();
            $counts['strays']++;
        }
    }

    private static function narrowTypeCheck(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        if (DB::table('menu_items')->whereNotIn('type', ['link', 'category', 'page', 'product', 'column'])->exists()) {
            throw new \RuntimeException('menu_items holds a type the column model does not know.');
        }

        if (DB::table('information_schema.table_constraints')->where('table_schema', DB::getDatabaseName())->where('table_name', 'menu_items')->where('constraint_name', 'menu_items_type_check')->exists()) {
            DB::statement('ALTER TABLE menu_items DROP CHECK menu_items_type_check');
        }

        DB::statement("ALTER TABLE menu_items ADD CONSTRAINT menu_items_type_check CHECK (type IN ('link','category','page','product','column'))");
    }
}
