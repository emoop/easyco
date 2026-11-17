<?php

namespace App\Storefront\NotFound;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Cache\CacheVersion;
use App\Storefront\Home\HomeCatalog;
use App\Storefront\Home\HomeTiles;
use App\Storefront\Home\HomeView;
use App\Storefront\Reader\CatalogReader;
use App\Storefront\Reader\CategoryIndex;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * NF-1: «Съдържание отдолу» of the 404 page, rendered ONCE and kept as a Laravel-cached HTML FRAGMENT (the 404 is never cached by the page cache, so a warm 404 must cost no catalog query).
 *
 * The key holds the `all` version AND the `sitemap` version (CacheVersion, P8b): `all` moves on any «Дизайн» publish, menu, filter or setting change; `sitemap` on any product or subject membership change (a new
 * product, a hidden one, a category with its first product). It also holds a hash of what the fragment shows, so the admin's publish is visible at once. TTL 24 h. The admin's signed preview never uses the cache.
 *
 * It is the HOME page's own machinery: the same section engine (HomeView::prepare / fill), the same partials (`storefront.partials.home.*`), the same tile resolver (HomeTiles::resolve: a category's own picture, a
 * category with no visible product or no usable picture is not a tile), the same HTML block sanitiser. The «Показвай, когато…» conditions of an HTML block are about a PRODUCT: like the home page, the footer and the
 * menus, the 404 ignores them (a block is printed when it exists and is not empty; one that is gone prints nothing).
 *
 * @phpstan-import-type Set from NotFoundSet
 */
final class NotFoundFragment
{
    public const TTL = 86_400;

    /** the tiles' columns by their count: 4 → one row of 4; 6 → 2 rows of 3; 8 → 2 rows of 4; 12 → 3 rows of 4 (the phone shows 2 a row) */
    private const COLUMNS = ['4' => '4', '6' => '3', '8' => '4', '12' => '4'];

    /** @param  Set  $set */
    public static function html(array $set, bool $cache = true): string
    {
        if ($set['bottom'] === 'none') {
            return '';
        }

        if (! $cache) {
            return self::render($set);
        }

        $shown = ['bottom' => $set['bottom'], 'tiles' => $set['tiles'], 'categories' => $set['categories'], 'products' => $set['products'], 'block' => $set['block']];
        $key = 'storefront.notfound.fragment.v'.CacheVersion::get('all').'-'.CacheVersion::get('sitemap').'.'.substr(sha1((string) json_encode($shown)), 0, 16);

        try {
            return (string) Cache::remember($key, self::TTL, static fn (): string => self::render($set));
        } catch (Throwable) {
            // a cache store that is down: the page still renders, only not from the cache
            return self::render($set);
        }
    }

    /** @param  Set  $set */
    private static function render(array $set): string
    {
        $sections = match ($set['bottom']) {
            'tiles' => self::tiles($set),
            'products' => self::products($set),
            'block' => self::block($set),
            default => null,
        };

        if ($sections === null || $sections['items'] === []) {
            return '';
        }

        return view('storefront.partials.notfound-bottom', ['sections' => $sections])->render();
    }

    /**
     * @param  Set  $set
     * @return array{gap: string, items: list<array<string, mixed>>}|null
     */
    private static function tiles(array $set): ?array
    {
        // NF-1.1: the chosen categories (in the order chosen; a category without a picture or without a visible product is skipped by HomeTiles::resolve); nothing chosen = the top-level ones, as before
        $chosen = $set['categories'] ?? [];
        $ids = $chosen !== [] ? $chosen : array_map(static fn ($node): int => (int) $node->id, app(CategoryIndex::class)->tree());

        if ($ids === []) {
            return null;
        }

        $settings = ['columns' => self::COLUMNS[$set['tiles']] ?? '4', 'columns_phone' => '2', 'tiles' => array_map(static fn (int $id): array => ['source' => 'category', 'entity' => $id, 'label' => '', 'picture' => null, 'link' => null], $ids)] + HomeCatalog::defaultsFor('tiles');
        $layout = ['spacing' => HomeCatalog::DEFAULT_SPACING, 'sections' => [['type' => 'tiles', 'settings' => $settings]]];
        // with a choice every chosen category may be shown (up to the maximum); without one the count of the panel applies
        $limit = $chosen !== [] ? NotFoundSet::CATEGORIES_MAX : (int) $set['tiles'];
        $list = array_slice(HomeTiles::resolve($layout)['tiles']['0'] ?? [], 0, $limit);

        if ($list === []) {
            return null;
        }

        // NF-1.1: the columns follow the tiles actually SHOWN: 1–4 → that many, 5–6 → 3, 7–12 → 4 (the phone keeps 2)
        $shown = count($list);
        $columns = $shown <= 4 ? (string) $shown : ($shown <= 6 ? '3' : '4');
        $html = HomeView::prepare(['layout' => $layout, 'blocks' => [], 'tiles' => ['0' => $list], 'carousels' => []]);

        if ($html !== null) {
            $html['items'][0]['tiles']['class'] = (string) preg_replace('/sf-hp-tc-\d+/', 'sf-hp-tc-'.$columns, (string) $html['items'][0]['tiles']['class']).' sf-nf-tiles';
        }

        return $html;
    }

    /**
     * @param  Set  $set
     * @return array{gap: string, items: list<array<string, mixed>>}|null
     */
    private static function products(array $set): ?array
    {
        $layout = ['spacing' => HomeCatalog::DEFAULT_SPACING, 'sections' => [['type' => 'product_grid', 'settings' => $set['products'] + HomeCatalog::defaultsFor('product_grid')]]];
        $sections = HomeView::prepare(['layout' => $layout, 'blocks' => [], 'tiles' => [], 'carousels' => []]);

        if ($sections === null) {
            return null;
        }

        $reader = app(CatalogReader::class);
        $appearance = app(StorefrontAppearance::class);

        return HomeView::fill($sections, static fn (string $source, ?int $entity, string $sort, int $count, string $sizes): array => $reader->homeProducts($source, $entity, $sort, $count, $sizes), $appearance->catalogGapX(), $appearance->catalogColumns()['phone']);
    }

    /**
     * @param  Set  $set
     * @return array{gap: string, items: list<array<string, mixed>>}|null
     */
    private static function block(array $set): ?array
    {
        $id = NotFoundSet::blockId($set);

        if ($id === null) {
            return null;
        }

        // the block's HTML is read now and cleaned again by HomeView::prepare (HtmlBlockSanitizer): a block that is gone or empty prints nothing
        $html = HtmlBlockModel::renderMany([$id])[$id] ?? '';
        $layout = ['spacing' => HomeCatalog::DEFAULT_SPACING, 'sections' => [['type' => 'html_block', 'settings' => ['html_block' => $id] + HomeCatalog::defaultsFor('html_block')]]];

        return HomeView::prepare(['layout' => $layout, 'blocks' => [(string) $id => $html], 'tiles' => [], 'carousels' => []]);
    }
}
