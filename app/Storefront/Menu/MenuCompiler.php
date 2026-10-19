<?php

namespace App\Storefront\Menu;

use App\Content\Menus\MenuTree;
use App\Content\Models\PageModel;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Pages\PageReader;
use App\Storefront\Reader\CategoryIndex;
use App\Storefront\Reader\ImageSetBuilder;
use App\Storefront\Url\StorefrontUrls;
use App\Storefront\Visibility\StorefrontVisibility;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Support\Facades\DB;

/**
 * Builds every menu's tree for the storefront (D3, storefront-customization-design.md 5.3 and 14) and stores ALL of them in ONE settings key
 * (`menus.compiled`), which rides the appearance read: no page pays a query for its menus.
 *
 *  - Labels are resolved (a category / page item with no label shows its target's own name); links come from StorefrontUrls and PageReader::urlFor.
 *  - A hidden item, a category that is not shown (StorefrontVisibility: no visible product in it or below), a page that is not published and a
 *    deleted target are SKIPPED WITH THEIR SUBTREE. A column with nothing left disappears; a top-level item whose submenu has nothing left becomes a
 *    plain link.
 *  - `with_children` lists the shown sub-categories of a category (one level, in the order the catalog sorts them), cut at the item's limit and
 *    followed by a "see all" link to the category.
 *  - An image is resolved to its renditions (ImageSetBuilder) and only when the asset is READY; anything else drops the image.
 *
 * The result is refused above MAX_BYTES (the save of a menu fails with a message, nothing is half-saved).
 */
class MenuCompiler
{
    public const MAX_BYTES = 262_144;

    /** The alt text and sizes of a menu picture. */
    public const IMAGE_SIZES = '(min-width: 1024px) 25vw, 50vw';

    public function __construct(
        private readonly SiteSettingsRepository $settings,
        private readonly StorefrontUrls $urls,
        private readonly StorefrontVisibility $visibility,
        private readonly ImageSetBuilder $images,
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
        };
        // Fresh readers: the compile may run at the END of a request that has already loaded (and changed) the catalog.
        $context->categories = new CategoryIndex($this->visibility, $this->urls);
        $context->pages = PageModel::query()->where('status', PageModel::PUBLISHED)->get(['id', 'slug', 'title'])->keyBy('id')->all();

        $out = [];

        foreach (MenuTree::all() as $key => $entry) {
            $out[$key] = $this->items($entry['items'], $context, 'root');
        }

        return $out;
    }

    /**
     * Compiles and stores; returns whether the stored value changed.
     *
     * @throws MenuCompileException when the result is over MAX_BYTES
     */
    public function compileAndStore(): bool
    {
        $menus = $this->compile();
        $json = (string) json_encode(['menus' => $menus], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (strlen($json) > static::MAX_BYTES) {
            throw new MenuCompileException(__('menus.errors.too_large', ['max' => number_format(static::MAX_BYTES / 1024, 0)]));
        }

        $current = $this->settings->get(AppearanceSettings::MENUS_COMPILED_KEY);
        $empty = array_filter($menus, fn (array $items): bool => $items !== []) === [];

        if ($current === $json || ($current === null && $empty)) {
            return false;
        }

        $this->settings->set(AppearanceSettings::MENUS_COMPILED_KEY, $json);
        app(StorefrontAppearance::class)->refresh();

        return true;
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

        return match ($item['type']) {
            'column' => $this->column($item, $context),
            'image' => $this->image($item),
            default => $this->link($item, $context, $kind),
        };
    }

    /** @return array<string, mixed>|null */
    private function link(array $item, object $context, string $kind): ?array
    {
        $category = null;

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
        } else {
            $id = (int) $item['target_id'];
            $row = $context->categories->row($id);

            if ($row === null || ! $context->categories->isShown($id)) {
                return null;
            }

            $category = $id;
            $label = $item['label'] ?: $row['name'];
            $url = $this->urls->category($context->categories->path($id));
        }

        $childKind = $kind === 'root' ? ($item['submenu'] === 'mega' ? 'mega' : 'dropdown') : 'nested';
        $children = $this->items($item['children'], $context, $childKind);

        if ($category !== null && $item['with_children'] && ! ($kind === 'root' && $item['submenu'] === 'mega')) {
            array_push($children, ...$this->subCategories($category, (int) $item['children_limit'], $url, $context));
        }

        $submenu = $kind === 'root' && $children !== [] ? $item['submenu'] : 'none';

        return [
            'type' => 'link',
            'label' => mb_substr($label, 0, 120),
            'url' => $url,
            'new_tab' => (bool) $item['new_tab'],
            'submenu' => $submenu,
            'children' => $submenu === 'none' && $kind === 'root' ? [] : $children,
        ];
    }

    /**
     * The shown sub-categories of a category, cut at the limit, then a "see all" link.
     *
     * @return list<array<string, mixed>>
     */
    private function subCategories(int $id, int $limit, string $url, object $context): array
    {
        $nodes = $context->categories->shownChildren($id);
        $out = [];

        foreach (array_slice($nodes, 0, $limit) as $node) {
            $out[] = ['type' => 'link', 'label' => mb_substr($node->name, 0, 120), 'url' => $node->url, 'new_tab' => false, 'submenu' => 'none', 'children' => []];
        }

        if (count($nodes) > $limit) {
            $out[] = ['type' => 'link', 'label' => null, 'url' => $url, 'new_tab' => false, 'submenu' => 'none', 'children' => [], 'see_all' => true];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function column(array $item, object $context): ?array
    {
        $children = $this->items($item['children'], $context, 'column');

        return $children === [] ? null : ['type' => 'column', 'label' => $item['label'], 'children' => $children];
    }

    /** @return array<string, mixed>|null */
    private function image(array $item): ?array
    {
        $asset = MediaAssetModel::query()->find((int) $item['media_asset_id']);

        if ($asset === null) {
            return null;
        }

        $alt = $item['image_alt'] ?: ($item['caption'] ?: (string) config('app.name'));
        $set = $this->images->build($asset, $alt, self::IMAGE_SIZES);

        if ($set === null) {
            return null;
        }

        return [
            'type' => 'image',
            'label' => $item['caption'],
            'url' => $item['url'],
            'new_tab' => (bool) $item['new_tab'],
            'alt' => mb_substr($alt, 0, 200),
            'src' => $set->src,
            'srcset' => $set->srcset,
            'width' => $set->width,
            'height' => $set->height,
        ];
    }

    /** True when any item of the stored menus points at this media asset (a cheap check for the media listener). */
    public static function usesAsset(int $assetId): bool
    {
        return DB::table('menu_items')->where('media_asset_id', $assetId)->exists();
    }
}
