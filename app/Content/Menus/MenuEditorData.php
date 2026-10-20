<?php

namespace App\Content\Menus;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Content\Models\PageModel;
use App\Storefront\Pages\PageReader;
use App\Storefront\Reader\CategoryIndex;
use App\Storefront\Url\StorefrontUrls;
use App\Storefront\Visibility\StorefrontVisibility;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use Illuminate\Support\Facades\DB;

/**
 * Everything the menu editor page hands to the browser (MENU-2): a menu as the flat list the editor shows (each item with the name of its target, where it points and whether the
 * target is hidden or gone), the lists of the add-panels (categories as a tree, pages, HTML blocks) and the server-side product search. Read-only: nothing here writes.
 */
final class MenuEditorData
{
    public const PRODUCT_RESULTS = 20;

    public function __construct(
        private readonly StorefrontVisibility $visibility,
        private readonly StorefrontUrls $urls,
        private readonly MenuLocations $locations,
    ) {
    }

    /** @return list<array{id: int, key: string, name: string, protected: bool}> */
    public function menus(): array
    {
        return MenuModel::query()->orderBy('id')->get()->map(fn (MenuModel $menu): array => ['id' => (int) $menu->id, 'key' => $menu->key, 'name' => $menu->name, 'protected' => $menu->isProtected()])->all();
    }

    /**
     * One menu for the editor.
     *
     * @return array{id: int, key: string, name: string, protected: bool, items: list<array<string, mixed>>, locations: list<string>}
     */
    public function menu(MenuModel $menu): array
    {
        $flat = MenuFlat::toFlat(MenuTree::forMenu((int) $menu->id));
        $context = $this->context($flat);

        return [
            'id' => (int) $menu->id,
            'key' => $menu->key,
            'name' => $menu->name,
            'protected' => $menu->isProtected(),
            'items' => array_map(fn (array $item, int $index): array => $this->item($item + ['id' => $index], $context), $flat, array_keys($flat)),
            'locations' => $this->locations->of((int) $menu->id),
        ];
    }

    /**
     * The categories as a tree in reading order (children after their parent, by name), for the add-panel.
     *
     * @return list<array{id: int, name: string, depth: int, parent: ?int, path: string, url: string}>
     */
    public function categories(): array
    {
        $rows = DB::table('catalog_categories')->orderBy('name')->orderBy('id')->get(['id', 'parent_id', 'name']);
        $byParent = $rows->groupBy(fn (object $row): string => (string) ($row->parent_id ?? 0));
        $paths = $this->categoryPaths($rows);
        $out = [];
        $walk = function (int $parent, int $depth, array $trail) use (&$walk, $byParent, $paths, &$out): void {
            foreach ($byParent->get((string) $parent, collect()) as $row) {
                if (in_array((int) $row->id, $trail, true) || $depth > 8) {
                    continue;
                }

                $out[] = ['id' => (int) $row->id, 'name' => (string) $row->name, 'depth' => $depth, 'parent' => $row->parent_id === null ? null : (int) $row->parent_id, 'path' => $paths[(int) $row->id]['label'], 'url' => $this->urls->category($paths[(int) $row->id]['slugs'])];
                $walk((int) $row->id, $depth + 1, [...$trail, (int) $row->id]);
            }
        };
        $walk(0, 0, []);

        return $out;
    }

    /** @return list<array{id: int, title: string, url: string, updated: int}> the published pages, newest first */
    public function pages(): array
    {
        return PageModel::query()->where('status', PageModel::PUBLISHED)->orderByDesc('updated_at')->orderByDesc('id')->get(['id', 'slug', 'title', 'updated_at'])
            ->map(fn (PageModel $page): array => ['id' => (int) $page->id, 'title' => (string) $page->title, 'url' => PageReader::urlFor((string) $page->slug), 'updated' => (int) $page->updated_at?->timestamp])
            ->all();
    }

    /** @return list<array{id: int, title: string}> */
    public function blocks(): array
    {
        return HtmlBlockModel::query()->orderBy('title')->get(['id', 'title'])->map(fn (HtmlBlockModel $block): array => ['id' => (int) $block->id, 'title' => (string) $block->title])->all();
    }

    /**
     * Products by name or SKU (the base SKU or the SKU of a variation), at most PRODUCT_RESULTS: id, name, sku and address only.
     *
     * @return list<array{id: int, name: string, sku: ?string, url: string}>
     */
    public function products(string $search): array
    {
        $search = trim(mb_substr($search, 0, 100));

        if ($search === '') {
            return [];
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

        return ProductModel::query()
            ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('base_sku', 'like', $like)->orWhereIn('id', DB::table('catalog_variations')->where('sku', 'like', $like)->select('product_id')))
            ->orderBy('name')
            ->limit(self::PRODUCT_RESULTS)
            ->get(['id', 'name', 'slug', 'base_sku'])
            ->map(fn (ProductModel $product): array => ['id' => (int) $product->id, 'name' => (string) $product->name, 'sku' => $product->base_sku === null ? null : (string) $product->base_sku, 'url' => $this->urls->product((string) $product->slug)])
            ->all();
    }

    // ---- the items --------------------------------------------------------------------------------------------------------------------

    /**
     * What the items point at, loaded in a few queries.
     *
     * @param  list<array<string, mixed>>  $flat
     */
    private function context(array $flat): object
    {
        $ids = ['category' => [], 'page' => [], 'product' => []];

        foreach ($flat as $item) {
            if (isset($ids[$item['type']]) && $item['target_id'] !== null) {
                $ids[$item['type']][] = (int) $item['target_id'];
            }
        }

        $context = new class
        {
            public CategoryIndex $categories;

            /** @var array<int, object> */
            public array $pages = [];

            /** @var array<int, ProductModel> */
            public array $products = [];
        };
        $context->categories = new CategoryIndex($this->visibility, $this->urls);

        if ($ids['page'] !== []) {
            $context->pages = PageModel::query()->whereIn('id', array_unique($ids['page']))->get(['id', 'slug', 'title', 'status'])->keyBy('id')->all();
        }

        if ($ids['product'] !== []) {
            $context->products = ProductModel::withTrashed()->whereIn('id', array_unique($ids['product']))->get()->keyBy('id')->all();
        }

        return $context;
    }

    /**
     * An item for the editor: the stored fields, the depth, a stable `uid` and what the target is (`target_name`, `target_url`, `target_status` = ok | hidden | deleted).
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function item(array $item, object $context): array
    {
        $name = null;
        $url = $item['url'];
        $status = 'ok';
        $id = (int) ($item['target_id'] ?? 0);

        if ($item['type'] === 'category') {
            $row = $context->categories->row($id);
            $name = $row['name'] ?? null;
            $url = $row === null ? null : $this->urls->category($context->categories->path($id));
            $status = $row === null ? 'deleted' : ($context->categories->isShown($id) ? 'ok' : 'hidden');
        } elseif ($item['type'] === 'page') {
            $page = $context->pages[$id] ?? null;
            $name = $page?->title;
            $url = $page === null ? null : PageReader::urlFor((string) $page->slug);
            $status = $page === null ? 'deleted' : ($page->status === PageModel::PUBLISHED ? 'ok' : 'hidden');
        } elseif ($item['type'] === 'product') {
            $product = $context->products[$id] ?? null;
            $name = $product?->name;
            $url = $product === null ? null : $this->urls->product((string) $product->slug);
            $status = $product === null || $product->deleted_at !== null ? 'deleted' : ($this->visibility->isProductVisible($product) ? 'ok' : 'hidden');
        }

        return [
            'uid' => 'i'.($item['id'] ?? uniqid()),
            'depth' => (int) $item['depth'],
            'type' => $item['type'],
            'label' => $item['label'],
            'url' => $item['type'] === 'link' ? $item['url'] : null,
            'target_id' => $item['target_id'],
            'submenu' => $item['submenu'],
            'html_block_id' => $item['html_block_id'],
            'mega_columns' => $item['mega_columns'] ?? null,
            'badge_text' => $item['badge_text'],
            'badge_color' => $item['badge_color'],
            'new_tab' => (bool) $item['new_tab'],
            'visible' => (bool) $item['visible'],
            'target_name' => $name === null ? null : (string) $name,
            'target_url' => $url,
            'target_status' => $status,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return array<int, array{label: string, slugs: string}>
     */
    private function categoryPaths($rows): array
    {
        $byId = $rows->keyBy('id');
        $slugs = DB::table('catalog_categories')->pluck('slug', 'id')->all();
        $out = [];

        foreach ($rows as $row) {
            $names = [];
            $segments = [];
            $guard = 0;

            for ($current = $row; $current !== null && $guard++ < 10; $current = $current->parent_id === null ? null : $byId->get($current->parent_id)) {
                array_unshift($names, (string) $current->name);
                array_unshift($segments, (string) ($slugs[$current->id] ?? ''));
            }

            $out[(int) $row->id] = ['label' => implode(' › ', $names), 'slugs' => implode('/', $segments)];
        }

        return $out;
    }
}
