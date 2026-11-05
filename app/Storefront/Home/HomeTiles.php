<?php

namespace App\Storefront\Home;

use App\Content\HtmlBlocks\HtmlBlockBackground;
use App\Content\HtmlBlocks\HtmlBlockPictures;
use App\Storefront\Reader\CategoryIndex;
use App\Storefront\Reader\ImageSetBuilder;
use App\Storefront\Url\StorefrontUrls;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Support\Facades\DB;

/**
 * HP-3: what the «Плочки» sections of the home layout show, resolved at PUBLISH time into the compiled copy (so the page costs no query for them): for every tile the caption (its own, else the entity's name),
 * the link (its own, else a category's page) and the picture (its own, else the entity's: a category's, a season's, a brand's logo) as the renditions of our media. ONE batched read per kind of entity and one
 * for all the pictures. A tile that cannot be shown (a category with no visible product, an entity that is gone, no usable picture, no caption) is simply not in the result.
 *
 * The result is validated AGAIN when the layout is read (clean()): text is cut and escaped by the view, a link goes through the shop's link rule and every picture address must be one of our own storage.
 */
final class HomeTiles
{
    /**
     * @param  array{spacing: string, sections: list<array{type: string, settings: array<string, mixed>}>}  $layout  VALIDATED
     * @return array{tiles: array<string, list<array<string, mixed>>>, assets: list<int>} the tiles by the index of their section, and every picture (ready or not) the tiles depend on
     */
    public static function resolve(array $layout): array
    {
        $wanted = ['category' => [], 'season' => [], 'tag' => [], 'brand' => []];

        foreach ($layout['sections'] as $section) {
            if ($section['type'] !== 'tiles') {
                continue;
            }

            foreach ($section['settings']['tiles'] ?? [] as $tile) {
                is_int($tile['entity'] ?? null) && isset($wanted[$tile['source']]) && $wanted[$tile['source']][$tile['entity']] = true;
            }
        }

        $entities = self::entities(array_map('array_keys', $wanted));
        $assetIds = [];

        foreach ($layout['sections'] as $section) {
            if ($section['type'] === 'tiles') {
                foreach ($section['settings']['tiles'] ?? [] as $tile) {
                    $id = $tile['picture'] ?? ($entities[$tile['source']][$tile['entity'] ?? 0]['picture'] ?? null);
                    is_int($id) && $assetIds[$id] = true;
                }
            }
        }

        $assets = $assetIds === [] ? [] : MediaAssetModel::query()->whereIn('id', array_keys($assetIds))->get()->keyBy('id')->all();
        $builder = app(ImageSetBuilder::class);
        $out = [];

        foreach ($layout['sections'] as $index => $section) {
            if ($section['type'] !== 'tiles') {
                continue;
            }

            $list = [];

            foreach ($section['settings']['tiles'] ?? [] as $tile) {
                $entity = $tile['source'] === 'custom' ? null : ($entities[$tile['source']][$tile['entity']] ?? null);

                // an entity that is gone, or a category the shop does not show, is not a tile
                if ($tile['source'] !== 'custom' && ($entity === null || ($entity['hidden'] ?? false))) {
                    continue;
                }

                $label = $tile['label'] !== '' ? $tile['label'] : (string) ($entity['name'] ?? '');
                $url = $tile['link'] ?? ($entity['url'] ?? null);
                $asset = $assets[$tile['picture'] ?? ($entity['picture'] ?? 0)] ?? null;
                $set = $asset instanceof MediaAssetModel ? $builder->build($asset, '', '') : null;

                if ($label === '' || $url === null || $set === null) {
                    continue;
                }

                $one = self::tileOf($label, $url, $set->src, array_map(static fn (array $row): array => ['url' => $row['url'], 'width' => $row['width']], $set->srcset), $set->width, $set->height);
                $one === null || $list[] = $one;
            }

            $out[(string) $index] = $list;
        }

        return ['tiles' => $out, 'assets' => array_map('intval', array_keys($assetIds))];
    }

    /**
     * HP-5: what the `images` and `brands` carousels of the layout show, resolved at PUBLISH time like the tiles (the page then costs no query): an images carousel's pictures (the renditions of our media, its own
     * alt and link), and for a brands carousel EVERY brand that has a logo, by name, at most 24 (the logo is the picture, the brand's name the alt text and the link the brand's page, /brand/{slug}: P2). ONE read of the
     * brands and ONE of all the pictures. An item whose picture cannot be shown (not ready, gone) is not in the result.
     *
     * @param  array{spacing: string, sections: list<array{type: string, settings: array<string, mixed>}>}  $layout  VALIDATED
     * @return array{carousels: array<string, list<array<string, mixed>>>, assets: list<int>} the items by the index of their section, and every picture (ready or not) they depend on
     */
    public static function carousels(array $layout): array
    {
        $wanted = [];
        $brands = [];
        $assetIds = [];

        foreach ($layout['sections'] as $index => $section) {
            if ($section['type'] !== 'carousel') {
                continue;
            }

            $kind = $section['settings']['kind'] ?? null;

            if ($kind === 'images') {
                foreach ($section['settings']['images'] ?? [] as $item) {
                    $assetIds[(int) $item['picture']] = true;
                }

                $wanted[$index] = 'images';
            } elseif ($kind === 'brands') {
                $wanted[$index] = 'brands';
            }
        }

        if ($wanted === []) {
            return ['carousels' => [], 'assets' => []];
        }

        if (in_array('brands', $wanted, true)) {
            foreach (DB::table('catalog_brands')->whereNotNull('logo_media_asset_id')->orderBy('name')->orderBy('id')->limit(HomeCatalog::CAROUSEL_BRANDS_MAX)->get(['id', 'name', 'slug', 'logo_media_asset_id']) as $row) {
                // P2: the logo links to the brand's page (the slug is read with the brand: no query per logo)
                $brands[] = ['name' => (string) $row->name, 'logo' => (int) $row->logo_media_asset_id, 'link' => app(\App\Storefront\Url\StorefrontUrls::class)->brand((string) $row->slug)];
                $assetIds[(int) $row->logo_media_asset_id] = true;
            }
        }

        $assets = $assetIds === [] ? [] : MediaAssetModel::query()->whereIn('id', array_keys($assetIds))->get()->keyBy('id')->all();
        $builder = app(ImageSetBuilder::class);
        $set = static function (int $id) use ($assets, $builder): ?\App\Storefront\ReadModels\ImageSet {
            $asset = $assets[$id] ?? null;

            return $asset instanceof MediaAssetModel ? $builder->build($asset, '', '') : null;
        };
        $out = [];

        foreach ($wanted as $index => $kind) {
            $list = [];
            $source = $kind === 'brands' ? array_map(static fn (array $b): array => ['picture' => $b['logo'], 'alt' => $b['name'], 'link' => $b['link']], $brands) : $layout['sections'][$index]['settings']['images'];

            foreach ($source as $item) {
                $picture = $set((int) $item['picture']);
                $one = $picture === null ? null : self::pictureOf((string) $item['alt'], $item['link'] ?? null, $picture->src, array_map(static fn (array $row): array => ['url' => $row['url'], 'width' => $row['width']], $picture->srcset), $picture->width, $picture->height);
                $one === null || $list[] = $one;
            }

            $out[(string) $index] = $list;
        }

        return ['carousels' => $out, 'assets' => array_map('intval', array_keys($assetIds))];
    }

    /**
     * HP-5: what a stored (compiled) list of carousel items is worth, every item checked again (our own picture paths only, the shop's link rule, the alt text); an item that is not is dropped.
     *
     * @return array<string, list<array{alt: string, url: string|null, src: string, srcset: list<array{url: string, width: int}>, width: int, height: int}>>
     */
    public static function cleanCarousels(mixed $stored): array
    {
        $out = [];

        foreach (is_array($stored) ? $stored : [] as $index => $list) {
            if (! is_array($list) || ! array_is_list($list)) {
                continue;
            }

            $clean = [];

            foreach (array_slice($list, 0, HomeCatalog::CAROUSEL_IMAGES_MAX) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $one = self::pictureOf(
                    is_string($item['alt'] ?? null) ? $item['alt'] : '',
                    is_string($item['url'] ?? null) ? $item['url'] : null,
                    is_string($item['src'] ?? null) ? $item['src'] : '',
                    is_array($item['srcset'] ?? null) ? $item['srcset'] : [],
                    is_int($item['width'] ?? null) ? $item['width'] : 0,
                    is_int($item['height'] ?? null) ? $item['height'] : 0,
                );
                $one === null || $clean[] = $one;
            }

            $out[(string) $index] = $clean;
        }

        return $out;
    }

    /**
     * One carousel picture as it is kept: the checks of both the compiler and the reader. The alt text is plain and at most 120 characters (empty only without a link); a link must pass the shop's rule (an item with
     * a link that does not is dropped, not printed without it); the picture is one of our own storage paths.
     *
     * @param  list<array<string, mixed>>  $srcset
     * @return array{alt: string, url: string|null, src: string, srcset: list<array{url: string, width: int}>, width: int, height: int}|null
     */
    private static function pictureOf(string $alt, ?string $url, string $src, array $srcset, int $width, int $height): ?array
    {
        $alt = trim($alt);
        $link = $url === null || trim($url) === '' ? null : HtmlBlockPictures::safeUrl($url);
        $src = self::ours($src);

        if (mb_strlen($alt) > HomeCatalog::CAROUSEL_ALT_MAX || $alt !== strip_tags($alt) || ($url !== null && trim($url) !== '' && $link === null) || ($link !== null && $alt === '') || $src === null || $width < 1 || $height < 1) {
            return null;
        }

        $rows = [];

        foreach ($srcset as $row) {
            $path = is_array($row) && is_string($row['url'] ?? null) ? self::ours($row['url']) : null;

            if ($path !== null && is_int($row['width'] ?? null) && $row['width'] > 0) {
                $rows[] = ['url' => $path, 'width' => $row['width']];
            }
        }

        return ['alt' => $alt, 'url' => $link, 'src' => $src, 'srcset' => $rows, 'width' => $width, 'height' => $height];
    }

    /**
     * What a stored (compiled) list of tiles is worth: every tile checked again. A tile with a caption that is empty or over 60 characters, a link the shop's rule refuses, or a picture address that is not one of
     * our own storage paths is dropped; a rendition that is not is dropped from the srcset.
     *
     * @return array<string, list<array{label: string, url: string, src: string, srcset: list<array{url: string, width: int}>, width: int, height: int}>>
     */
    public static function clean(mixed $stored): array
    {
        $out = [];

        foreach (is_array($stored) ? $stored : [] as $index => $list) {
            if (! is_array($list) || ! array_is_list($list)) {
                continue;
            }

            $clean = [];

            foreach (array_slice($list, 0, HomeCatalog::TILES_MAX) as $tile) {
                if (! is_array($tile)) {
                    continue;
                }

                $one = self::tileOf(
                    is_string($tile['label'] ?? null) ? $tile['label'] : '',
                    is_string($tile['url'] ?? null) ? $tile['url'] : '',
                    is_string($tile['src'] ?? null) ? $tile['src'] : '',
                    is_array($tile['srcset'] ?? null) ? $tile['srcset'] : [],
                    is_int($tile['width'] ?? null) ? $tile['width'] : 0,
                    is_int($tile['height'] ?? null) ? $tile['height'] : 0,
                );
                $one === null || $clean[] = $one;
            }

            $out[(string) $index] = $clean;
        }

        return $out;
    }

    /**
     * One tile as it is kept: the checks of both the compiler and the reader, in one place.
     *
     * @param  list<array<string, mixed>>  $srcset
     * @return array{label: string, url: string, src: string, srcset: list<array{url: string, width: int}>, width: int, height: int}|null
     */
    private static function tileOf(string $label, string $url, string $src, array $srcset, int $width, int $height): ?array
    {
        $label = trim($label);
        $url = HtmlBlockPictures::safeUrl($url);
        $src = self::ours($src);

        if ($label === '' || mb_strlen($label) > HomeCatalog::TILE_LABEL_MAX || $label !== strip_tags($label) || $url === null || $src === null || $width < 1 || $height < 1) {
            return null;
        }

        $rows = [];

        foreach ($srcset as $row) {
            $path = is_array($row) && is_string($row['url'] ?? null) ? self::ours($row['url']) : null;

            if ($path !== null && is_int($row['width'] ?? null) && $row['width'] > 0) {
                $rows[] = ['url' => $path, 'width' => $row['width']];
            }
        }

        return ['label' => $label, 'url' => $url, 'src' => $src, 'srcset' => $rows, 'width' => $width, 'height' => $height];
    }

    /** An address of our own storage as the path it is served at (`/storage/…`), or null: nothing else is ever printed in a picture's `src`. */
    private static function ours(string $url): ?string
    {
        $path = preg_match('#^https?://[^/]+(/storage/.*)$#i', $url, $m) === 1 ? $m[1] : $url;

        return HtmlBlockBackground::isLibraryPath($path) ? $path : null;
    }

    /**
     * The entities of the tiles: ONE read per kind (and the category tree, which the storefront reads as a whole anyway).
     *
     * @param  array<string, list<int>>  $ids
     * @return array<string, array<int, array{name: string, picture: ?int, url?: string, hidden?: bool}>>
     */
    private static function entities(array $ids): array
    {
        $out = ['category' => [], 'season' => [], 'tag' => [], 'brand' => []];

        if ($ids['category'] !== []) {
            $index = app(CategoryIndex::class);
            $urls = app(StorefrontUrls::class);
            $pictures = DB::table('catalog_categories')->whereIn('id', $ids['category'])->pluck('picture_media_asset_id', 'id');

            foreach ($ids['category'] as $id) {
                $row = $index->row($id);

                if ($row !== null) {
                    $out['category'][$id] = ['name' => $row['name'], 'picture' => ($pictures[$id] ?? null) === null ? null : (int) $pictures[$id], 'url' => $urls->category($index->path($id)), 'hidden' => ! $index->isShown($id)];
                }
            }
        }

        if ($ids['season'] !== []) {
            foreach (DB::table('catalog_seasons')->whereIn('id', $ids['season'])->get(['id', 'name', 'slug', 'picture_media_asset_id']) as $row) {
                // P3: the tile links to the season's page (the slug is read with the season: no query per tile)
                $out['season'][(int) $row->id] = ['name' => (string) $row->name, 'picture' => $row->picture_media_asset_id === null ? null : (int) $row->picture_media_asset_id, 'url' => app(StorefrontUrls::class)->season((string) $row->slug)];
            }
        }

        if ($ids['tag'] !== []) {
            foreach (DB::table('catalog_tags')->whereIn('id', $ids['tag'])->get(['id', 'name', 'slug']) as $row) {
                $out['tag'][(int) $row->id] = ['name' => (string) $row->name, 'picture' => null, 'url' => app(StorefrontUrls::class)->tag((string) $row->slug)];
            }
        }

        if ($ids['brand'] !== []) {
            foreach (DB::table('catalog_brands')->whereIn('id', $ids['brand'])->get(['id', 'name', 'slug', 'logo_media_asset_id']) as $row) {
                $out['brand'][(int) $row->id] = ['name' => (string) $row->name, 'picture' => $row->logo_media_asset_id === null ? null : (int) $row->logo_media_asset_id, 'url' => app(StorefrontUrls::class)->brand((string) $row->slug)];
            }
        }

        return $out;
    }
}
