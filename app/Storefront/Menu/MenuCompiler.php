<?php

namespace App\Storefront\Menu;

use App\Content\HtmlBlocks\HtmlBlockSanitizer;
use App\Content\Menus\MenuLocations;
use App\Content\Menus\MenuTree;
use App\Content\Models\PageModel;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Header\AppearanceRecompiler;
use App\Storefront\Pages\PageReader;
use App\Storefront\Reader\CategoryIndex;
use App\Storefront\Url\StorefrontUrls;
use App\Storefront\Visibility\StorefrontVisibility;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use Illuminate\Support\Facades\DB;

/**
 * Builds every menu's tree for the storefront (D3, MENU-2, storefront-customization-design.md 5.3 and 14) and stores ALL of them, with the menu of each location, in ONE
 * settings key (`menus.compiled`), which rides the appearance read: no page pays a query for its menus.
 *
 *  - Labels are resolved (a category / page / product item with no label shows its target's own name); links come from StorefrontUrls and PageReader::urlFor.
 *  - A hidden item, a category that is not shown (StorefrontVisibility: no visible product in it or below), a product that is not visible, a page that is not published
 *    and a deleted target are SKIPPED WITH THEIR SUBTREE. A top-level item whose submenu has nothing left becomes a plain link.
 *  - A mega item holds `column`s (MENU-3): each has an optional heading (its label) and its links. An EMPTY column is kept (it keeps its place, so four boxes with one empty look
 *    like the editor); a mega item whose columns are ALL empty becomes a plain link.
 *  - A mega item carries its HTML block, sanitized again here (HtmlBlockSanitizer::render), as `html`.
 *  - A badge is `{text, color}`; a custom colour is a class of the token stylesheet (see AppearanceCompiler), never a `style=`.
 *
 * The result is refused above MAX_BYTES (the save of a menu fails with a message, nothing is half-saved).
 */
class MenuCompiler
{
    public const MAX_BYTES = 262_144;

    public function __construct(
        private readonly SiteSettingsRepository $settings,
        private readonly StorefrontUrls $urls,
        private readonly StorefrontVisibility $visibility,
        private readonly MenuLocations $locations,
    ) {
    }

    /**
     * @return array<string, list<array<string, mixed>>> menu key => compiled items
     */
    public function compile(): array
    {
        $context = new class
        {
            public CategoryIndex $categories;

            /** @var array<int, object> */
            public array $pages = [];

            /** @var array<int, object> */
            public array $products = [];

            /** @var array<int, string> */
            public array $blocks = [];
        };
        // Fresh readers: the compile may run at the END of a request that has already loaded (and changed) the catalog.
        $context->categories = new CategoryIndex($this->visibility, $this->urls);
        $context->pages = PageModel::query()->where('status', PageModel::PUBLISHED)->get(['id', 'slug', 'title'])->keyBy('id')->all();

        $productIds = DB::table('menu_items')->where('type', 'product')->distinct()->pluck('target_id')->all();

        if ($productIds !== []) {
            $context->products = $this->visibility->products(ProductModel::query())->whereIn('catalog_products.id', $productIds)->get(['catalog_products.id', 'catalog_products.name', 'catalog_products.slug'])->keyBy('id')->all();
        }

        $blockIds = DB::table('menu_items')->whereNotNull('html_block_id')->distinct()->pluck('html_block_id')->all();

        if ($blockIds !== []) {
            $context->blocks = DB::table('html_blocks')->whereIn('id', $blockIds)->pluck('body', 'id')->all();
        }

        $out = [];

        foreach (MenuTree::all() as $key => $entry) {
            $out[$key] = $this->items($entry['items'], $context, 'root');
        }

        return $out;
    }

    /**
     * Compiles and stores; returns whether the stored value changed. When the custom badge colours changed, the token stylesheet is built again (it holds a class per colour).
     *
     * @throws MenuCompileException when the result is over MAX_BYTES
     */
    public function compileAndStore(): bool
    {
        $menus = $this->compile();
        $locations = $this->locations->keys();

        foreach ($locations as $location => $key) {
            if ($key !== null && ! isset($menus[$key])) {
                $locations[$location] = null;
            }
        }

        $json = (string) json_encode(['menus' => $menus, 'locations' => $locations], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (strlen($json) > static::MAX_BYTES) {
            throw new MenuCompileException(__('menus.errors.too_large', ['max' => number_format(static::MAX_BYTES / 1024, 0)]));
        }

        $current = $this->settings->get(AppearanceSettings::MENUS_COMPILED_KEY);
        $empty = array_filter($menus, fn (array $items): bool => $items !== []) === [] && array_filter($locations) === [];

        if ($current === $json || ($current === null && $empty)) {
            return false;
        }

        $before = self::customColors(is_string($current) ? (json_decode($current, true)['menus'] ?? []) : []);
        $this->settings->set(AppearanceSettings::MENUS_COMPILED_KEY, $json);

        if ($before !== self::customColors($menus)) {
            app(AppearanceRecompiler::class)->run($this->settings);
        }

        app(StorefrontAppearance::class)->refresh();

        return true;
    }

    /**
     * The distinct custom (`#rrggbb`) badge colours of compiled menus, sorted: what the token stylesheet needs a class for.
     *
     * @param  mixed  $menus  menu key => items
     * @return list<string>
     */
    public static function customColors(mixed $menus): array
    {
        $found = [];
        $walk = function (mixed $items) use (&$walk, &$found): void {
            foreach (is_array($items) ? $items : [] as $item) {
                $color = is_array($item) ? ($item['badge']['color'] ?? null) : null;

                if (is_string($color) && AppearanceSettings::isHex($color)) {
                    $found[strtolower($color)] = true;
                }

                $walk(is_array($item) ? ($item['children'] ?? []) : []);
            }
        };

        foreach (is_array($menus) ? $menus : [] as $items) {
            $walk($items);
        }

        $colors = array_keys($found);
        sort($colors);

        return $colors;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function items(array $items, object $context, string $kind): array
    {
        $out = [];

        foreach ($items as $item) {
            $node = $this->node($item, $context, $kind);

            if ($node !== null) {
                $out[] = $node;
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function node(array $item, object $context, string $kind): ?array
    {
        if (! $item['visible']) {
            return null;
        }

        return $item['type'] === 'column' ? $this->column($item, $context) : $this->link($item, $context, $kind);
    }

    /** @return array<string, mixed>|null */
    private function link(array $item, object $context, string $kind): ?array
    {
        if ($item['type'] === 'link') {
            $label = (string) $item['label'];
            $url = (string) $item['url'];
        } elseif ($item['type'] === 'page') {
            $page = $context->pages[(int) $item['target_id']] ?? null;

            if ($page === null) {
                return null;
            }

            $label = $item['label'] ?: (string) $page->title;
            $url = PageReader::urlFor((string) $page->slug);
        } elseif ($item['type'] === 'product') {
            $product = $context->products[(int) $item['target_id']] ?? null;

            if ($product === null) {
                return null;
            }

            $label = $item['label'] ?: (string) $product->name;
            $url = $this->urls->product((string) $product->slug);
        } else {
            $id = (int) $item['target_id'];
            $row = $context->categories->row($id);

            if ($row === null || ! $context->categories->isShown($id)) {
                return null;
            }

            $label = $item['label'] ?: $row['name'];
            $url = $this->urls->category($context->categories->path($id));
        }

        $childKind = match ($kind) {
            'root' => $item['submenu'] === 'mega' ? 'mega' : 'dropdown',
            'dropdown' => 'nested',
            'mega' => 'column',
            default => null,
        };
        $children = $childKind === null || ($kind === 'root' && $item['submenu'] === 'none') ? [] : $this->items($item['children'], $context, $childKind);

        // A mega panel with nothing in any of its columns has nothing to show: the item is a plain link.
        if ($kind === 'root' && $item['submenu'] === 'mega' && array_filter($children, fn (array $column): bool => $column['children'] !== []) === []) {
            $children = [];
        }

        $submenu = $kind === 'root' && $children !== [] ? $item['submenu'] : 'none';
        $html = null;

        if ($submenu === 'mega' && $item['html_block_id'] !== null) {
            $rendered = HtmlBlockSanitizer::render($context->blocks[(int) $item['html_block_id']] ?? '');
            $html = $rendered === '' ? null : $rendered;
        }

        return [
            'type' => 'link',
            'label' => mb_substr($label, 0, 120),
            'url' => $url,
            'new_tab' => (bool) $item['new_tab'],
            'submenu' => $submenu,
            'badge' => $item['badge_text'] === null ? null : ['text' => $item['badge_text'], 'color' => $item['badge_color']],
            'html' => $html,
            'children' => $children,
        ];
    }

    /** @return array<string, mixed> a column of a mega item: kept even when nothing is left in it */
    private function column(array $item, object $context): array
    {
        $label = trim((string) $item['label']);

        return ['type' => 'column', 'label' => $label === '' ? null : mb_substr($label, 0, 60), 'children' => $this->items($item['children'], $context, 'column')];
    }
}
