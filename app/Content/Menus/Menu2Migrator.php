<?php

namespace App\Content\Menus;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Header\HeaderCompiler;
use App\Storefront\Header\HeaderValidator;
use App\Storefront\Menu\MenuRecompiler;
use App\Storefront\Reader\CategoryIndex;
use App\Storefront\Reader\ImageSetBuilder;
use App\Storefront\Url\StorefrontUrls;
use App\Storefront\Visibility\StorefrontVisibility;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The data step of MENU-2 (menu-editor-v2-design.md 3), called by the migration and by the tests. D3 menus become the structure model without losing a link:
 *
 *  - a `column` becomes a `heading` (its label is the heading; its children keep their parent, so they are the links under it);
 *  - the `image` items of a mega item become ONE HTML block («Снимка: <label of the mega item>», one `<figure>` per picture) set on that mega item; the image rows go;
 *  - a category with `with_children` gets its CURRENT shown sub-categories as explicit child items (as the catalog sorts them, cut at the old limit, then a "see all"
 *    item when the old menu had one); only where the new depth allows it (a top-level dropdown item, or an item of its first sub-level);
 *  - `menus.locations` is seeded from what the header and the footer showed (the header's menu element, the drawer's, the `footer` menu);
 *  - the header layouts (draft, published, compiled copy) lose `menu.source`;
 *  - the type CHECK is narrowed to the five final types and the menus are compiled again.
 *
 * IDEMPOTENT: a second run finds no column / image / with_children, an existing `menus.locations` and layouts without `source`: nothing changes.
 */
final class Menu2Migrator
{
    /** What D3 told the browser about a menu picture (only the rendition choice reads it here). */
    private const IMAGE_SIZES = '(min-width: 1024px) 25vw, 50vw';

    /** @return array<string, int> the counts: columns converted, images removed, blocks made, categories expanded, items added, categories skipped, locations seeded, layouts changed */
    public static function run(): array
    {
        $counts = ['columns' => 0, 'images' => 0, 'blocks' => 0, 'expanded' => 0, 'items' => 0, 'skipped' => 0, 'locations' => 0, 'layouts' => 0];

        // A fresh database has no menus yet (the D3 seed migration no longer writes any): `main` and `footer` are made here, in the final shape.
        MenuSeeder::run();

        DB::transaction(function () use (&$counts): void {
            if (Schema::hasColumn('menu_items', 'media_asset_id')) {
                self::images($counts);
            }

            $counts['columns'] = DB::table('menu_items')->where('type', 'column')->count();

            foreach (DB::table('menu_items')->where('type', 'column')->get(['id', 'label']) as $column) {
                DB::table('menu_items')->where('id', $column->id)->update([
                    'type' => 'heading',
                    'label' => trim((string) $column->label) !== '' ? $column->label : __('menus.migration.column', [], 'bg'),
                    'url' => null,
                    'target_id' => null,
                ]);
            }

            if (Schema::hasColumn('menu_items', 'with_children')) {
                self::subCategories($counts);
            }
        });

        self::narrowTypeCheck();
        self::locations($counts);
        self::layouts($counts);

        app(MenuRecompiler::class)->now();

        return $counts;
    }

    /** @param  array<string, int>  $counts */
    private static function images(array &$counts): void
    {
        $rows = DB::table('menu_items')->get()->keyBy('id');
        $byMega = [];

        foreach ($rows->where('type', 'image')->sortBy('id') as $image) {
            $column = $rows->get($image->parent_id);
            $mega = $column === null ? null : $rows->get($column->parent_id);
            $byMega[$mega === null ? 0 : $mega->id][] = [$column->sort ?? 0, $image->sort, $image->id, $image];
        }

        $builder = app(ImageSetBuilder::class);

        foreach ($byMega as $megaId => $entries) {
            usort($entries, fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
            $figures = [];

            foreach ($entries as [, , , $image]) {
                $asset = $image->media_asset_id === null ? null : MediaAssetModel::query()->find((int) $image->media_asset_id);
                $alt = (string) ($image->image_alt ?: ($image->caption ?: config('app.name')));
                $set = $asset === null ? null : $builder->build($asset, $alt, self::IMAGE_SIZES);

                if ($set === null) {
                    continue; // exactly what the D3 compile did with a picture that is not ready
                }

                $img = '<img src="'.e(self::ownPath($set->src)).'" alt="'.e(mb_substr($set->alt, 0, 200)).'" width="'.(int) $set->width.'" height="'.(int) $set->height.'">';
                $link = is_string($image->url) && $image->url !== '' && AppearanceSettings::isSafeBannerUrl($image->url) ? $image->url : null;
                $figure = $link === null ? $img : '<a href="'.e($link).'">'.$img.'</a>';
                $caption = trim((string) $image->caption);
                $figures[] = '<figure>'.$figure.($caption !== '' ? '<figcaption>'.e($caption).'</figcaption>' : '').'</figure>';
            }

            $counts['images'] += count($entries);
            DB::table('menu_items')->whereIn('id', array_map(fn (array $e): int => $e[2], $entries))->delete();

            if ($figures === [] || $megaId === 0) {
                continue;
            }

            $block = HtmlBlockModel::create([
                'title' => mb_substr(__('menus.migration.picture_block', ['label' => self::labelOf($rows->get($megaId))], 'bg'), 0, 120),
                'body' => implode('', $figures),
            ]);
            DB::table('menu_items')->where('id', $megaId)->update(['html_block_id' => $block->id]);
            $counts['blocks']++;
        }
    }

    /** The address as a block may keep it: our own storage path when it is one, else as it is. */
    private static function ownPath(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (is_string($path) && str_starts_with($path, '/storage/') && (str_starts_with($url, '/') || parse_url($url, PHP_URL_HOST) === parse_url((string) config('app.url'), PHP_URL_HOST))) {
            return $path;
        }

        return $url;
    }

    private static function labelOf(?object $item): string
    {
        if ($item === null) {
            return '';
        }

        if (trim((string) $item->label) !== '') {
            return (string) $item->label;
        }

        $name = match ($item->type) {
            'category' => DB::table('catalog_categories')->where('id', $item->target_id)->value('name'),
            'page' => DB::table('pages')->where('id', $item->target_id)->value('title'),
            default => null,
        };

        return (string) ($name ?: '#'.$item->id);
    }

    /** @param  array<string, int>  $counts */
    private static function subCategories(array &$counts): void
    {
        $rows = DB::table('menu_items')->get()->keyBy('id');
        $index = new CategoryIndex(app(StorefrontVisibility::class), app(StorefrontUrls::class));
        $now = now();

        foreach ($rows->where('type', 'category')->where('with_children', 1)->sortBy('id') as $row) {
            $depth = 0;

            for ($parent = $row->parent_id; $parent !== null && $depth < 10; $parent = $rows->get($parent)?->parent_id) {
                $depth++;
            }

            $fits = $row->parent_id === null ? $row->submenu === 'dropdown' : $depth <= 1;

            if ($fits && $row->target_id !== null) {
                $nodes = $index->shownChildren((int) $row->target_id);
                $limit = max(1, (int) $row->children_limit);
                $sort = (int) (DB::table('menu_items')->where('parent_id', $row->id)->max('sort') ?? -1) + 1;
                $base = ['menu_id' => $row->menu_id, 'parent_id' => $row->id, 'type' => 'category', 'submenu' => 'none', 'new_tab' => 0, 'visible' => 1, 'created_at' => $now, 'updated_at' => $now];

                foreach (array_slice($nodes, 0, $limit) as $node) {
                    DB::table('menu_items')->insert($base + ['sort' => $sort++, 'target_id' => (int) $node->id]);
                    $counts['items']++;
                }

                if (count($nodes) > $limit) {
                    DB::table('menu_items')->insert($base + ['sort' => $sort, 'target_id' => (int) $row->target_id, 'label' => __('storefront.see_all')]);
                    $counts['items']++;
                }

                $counts['expanded']++;
            } else {
                $counts['skipped']++;
            }

            DB::table('menu_items')->where('id', $row->id)->update(['with_children' => 0]);
        }
    }

    private static function narrowTypeCheck(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        if (DB::table('menu_items')->whereNotIn('type', ['link', 'category', 'page', 'product', 'heading'])->exists()) {
            throw new \RuntimeException('menu_items holds a type the structure model does not know.');
        }

        if (DB::table('information_schema.table_constraints')->where('table_schema', DB::getDatabaseName())->where('table_name', 'menu_items')->where('constraint_name', 'menu_items_type_check')->exists()) {
            DB::statement('ALTER TABLE menu_items DROP CHECK menu_items_type_check');
        }

        DB::statement("ALTER TABLE menu_items ADD CONSTRAINT menu_items_type_check CHECK (type IN ('link','category','page','product','heading'))");
    }

    /** @param  array<string, int>  $counts */
    private static function locations(array &$counts): void
    {
        $locations = app(MenuLocations::class);

        if ($locations->has()) {
            return;
        }

        $settings = app(SiteSettingsRepository::class);
        $keys = MenuModel::query()->pluck('id', 'key')->all();
        $layout = self::decode($settings->get(AppearanceSettings::HEADER_PUBLISHED_KEY)) ?? self::decode($settings->get(AppearanceSettings::HEADER_COMPILED_KEY))['layout'] ?? null;
        $header = null;
        $drawer = null;

        if (is_array($layout)) {
            $header = self::firstSource($layout, ['desktop', 'mobile'], false);
            $drawer = self::firstSource($layout, ['mobile'], true);
        }

        $header = isset($keys[(string) $header]) ? (string) $header : (isset($keys['main']) ? 'main' : null);
        $drawer = $drawer !== null && $drawer !== $header && isset($keys[$drawer]) ? $drawer : null;
        $ids = ['header' => $header === null ? null : $keys[$header], 'drawer' => $drawer === null ? null : $keys[$drawer], 'footer' => $keys['footer'] ?? null];
        $locations->store($ids);
        $counts['locations'] = count(array_filter($ids));
    }

    /**
     * The `source` of the first `menu` element in the rows (or in the drawer).
     *
     * @param  array<string, mixed>  $layout
     * @param  list<string>  $names
     */
    private static function firstSource(array $layout, array $names, bool $drawer): ?string
    {
        foreach ($names as $name) {
            $lists = [];

            if ($drawer) {
                $lists[] = (array) ($layout[$name]['drawer'] ?? []);
            } else {
                foreach ((array) ($layout[$name]['rows'] ?? []) as $row) {
                    foreach (['left', 'center', 'right'] as $slot) {
                        $lists[] = (array) ($row[$slot] ?? []);
                    }
                }
            }

            foreach ($lists as $elements) {
                foreach ($elements as $element) {
                    if (is_array($element) && ($element['type'] ?? null) === 'menu') {
                        return is_string($element['settings']['source'] ?? null) ? $element['settings']['source'] : null;
                    }
                }
            }
        }

        return null;
    }

    /** @param  array<string, int>  $counts */
    private static function layouts(array &$counts): void
    {
        $settings = app(SiteSettingsRepository::class);

        $draft = self::decode($settings->get(AppearanceSettings::HEADER_DRAFT_KEY));

        if ($draft !== null && self::strip($draft)) {
            $settings->set(AppearanceSettings::HEADER_DRAFT_KEY, (string) json_encode($draft, JSON_THROW_ON_ERROR));
            $counts['layouts']++;
        }

        $published = self::decode($settings->get(AppearanceSettings::HEADER_PUBLISHED_KEY)) ?? self::decode($settings->get(AppearanceSettings::HEADER_COMPILED_KEY))['layout'] ?? null;

        if ($published !== null && self::strip($published)) {
            $validation = HeaderValidator::validate($published);

            if ($validation->passes()) {
                app(HeaderCompiler::class)->publish($validation->layout, $settings);
                $counts['layouts']++;
            }
        }
    }

    /** @return array<string, mixed>|null */
    private static function decode(mixed $json): ?array
    {
        $data = is_string($json) && $json !== '' ? json_decode($json, true) : null;

        return is_array($data) ? $data : null;
    }

    /**
     * Removes `source` from every `menu` element. Returns whether anything changed.
     *
     * @param  array<string, mixed>  $layout
     */
    private static function strip(array &$layout): bool
    {
        $changed = false;
        $fix = static function (mixed &$elements) use (&$changed): void {
            if (! is_array($elements)) {
                return;
            }

            foreach ($elements as &$element) {
                if (is_array($element) && ($element['type'] ?? null) === 'menu' && is_array($element['settings'] ?? null) && array_key_exists('source', $element['settings'])) {
                    unset($element['settings']['source']);
                    $changed = true;
                }
            }
        };

        foreach (['desktop', 'mobile'] as $name) {
            if (isset($layout[$name]['rows']) && is_array($layout[$name]['rows'])) {
                foreach ($layout[$name]['rows'] as &$row) {
                    foreach (['left', 'center', 'right'] as $slot) {
                        if (isset($row[$slot])) {
                            $fix($row[$slot]);
                        }
                    }
                }

                unset($row);
            }
        }

        if (isset($layout['mobile']['drawer'])) {
            $fix($layout['mobile']['drawer']);
        }

        return $changed;
    }
}
