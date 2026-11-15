<?php

namespace App\Storefront\Index;

use App\Storefront\ReadModels\CatalogSubjectKind;
use App\Storefront\Reader\CatalogReader;
use App\Storefront\Reader\ImageSetBuilder;
use App\Storefront\Url\StorefrontUrls;
use App\Storefront\Visibility\StorefrontVisibility;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Support\Facades\DB;

/**
 * SEO-1.1: the rows of the two index pages (`/brand`, `/collection`) and of the sitemap files that list the same things.
 *
 * WHICH ROWS: a brand or a collection is listed when it has at least one product its own listing would show (`StorefrontVisibility::listed()`, the rule of
 * the category listing). The subject page itself answers 200 for an empty subject too (`CatalogReader::subject()` only looks the slug up), so the index is
 * stricter than «answers 200»: empty subjects are left out of the index and the sitemap, not hidden from their own page. A collection is a row only while
 * the collections switch is on (`CatalogSubjectKind::COLLECTION->gated()`, inside the statement, as the detail page does): switch off = no rows = 404.
 *
 * COST: the row list is ONE statement; the pictures are at most two more (the media assets of the rows; then, for a collection without a usable picture, the
 * first usable image of its visible products). The count does not depend on the number of rows.
 */
final class SubjectIndex
{
    /** The `sizes` of a tile's picture: the tiles are 2, 3 or 4 to a row. */
    public const SIZES = '(min-width: 64rem) 20vw, (min-width: 40rem) 33vw, 50vw';

    public function __construct(
        private readonly StorefrontVisibility $visibility,
        private readonly StorefrontUrls $urls,
        private readonly ImageSetBuilder $images,
    ) {
    }

    /**
     * The listed rows of a kind (brand, collection, season, tag), sorted by name in Bulgarian collation, then by id.
     *
     * @return list<object{id: int, name: string, slug: string, updated_at: mixed, picture: ?int}>
     */
    public function rows(CatalogSubjectKind $kind): array
    {
        $table = $kind->table();
        $picture = match ($kind) {
            CatalogSubjectKind::BRAND => 'logo_media_asset_id',
            CatalogSubjectKind::COLLECTION => 'picture_media_asset_id',
            default => null,
        };

        $columns = ["{$table}.id", "{$table}.name", "{$table}.slug", "{$table}.updated_at"];
        $picture !== null && $columns[] = "{$table}.{$picture}";

        $query = DB::table($table)->select($columns);
        $query = $kind->gated($query)->whereExists($this->visibleProducts($kind, $table));

        $rows = $query->get()->map(static fn (object $row): object => (object) [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'slug' => (string) $row->slug,
            'updated_at' => $row->updated_at,
            'picture' => $picture === null || $row->{$picture} === null ? null : (int) $row->{$picture},
        ])->all();

        return self::sortByName($rows);
    }

    /**
     * The index tiles of a kind: a picture (its own, else for a collection the first usable image of one of its visible products), or none (a brand with no
     * logo: a name-only tile; a collection with no picture at all: the storefront's neutral placeholder, `$tile->placeholder`).
     *
     * @return list<SubjectTile>
     */
    public function tiles(CatalogSubjectKind $kind): array
    {
        $rows = $this->rows($kind);

        if ($rows === []) {
            return [];
        }

        $ownIds = array_values(array_unique(array_filter(array_map(static fn (object $row): ?int => $row->picture, $rows))));
        $own = $ownIds === [] ? collect() : MediaAssetModel::query()->whereIn('id', $ownIds)->get()->keyBy('id');
        $images = [];

        foreach ($rows as $row) {
            $asset = $row->picture === null ? null : ($own[$row->picture] ?? null);
            $images[$row->id] = $asset === null ? null : $this->images->build($asset, '', self::SIZES);
        }

        // a collection whose own picture is missing or not usable takes the first usable image of its visible products
        if ($kind === CatalogSubjectKind::COLLECTION) {
            $images = $this->withProductPictures($rows, $images);
        }

        $urls = $this->urls;
        $tiles = [];

        foreach ($rows as $row) {
            $url = $urls->subject($kind, $row->slug);
            [$letter, $anchor] = self::letter($row->name);
            $tiles[] = new SubjectTile(
                id: $row->id,
                name: $row->name,
                url: $url,
                image: $images[$row->id] ?? null,
                placeholder: $kind === CatalogSubjectKind::COLLECTION && ($images[$row->id] ?? null) === null,
                letter: $letter,
                anchor: $anchor,
            );
        }

        return $tiles;
    }

    /** The site-relative path of one row's own page (`/brand/{slug}`), percent-encoded. */
    public function path(CatalogSubjectKind $kind, string $slug): string
    {
        return $this->urls->subject($kind, $slug);
    }

    /**
     * The tiles grouped by their first letter, in the order of the list: `[['letter' => 'А', 'anchor' => 'а', 'tiles' => [...]], …]`; the names that do not
     * start with a letter form one last group `#` / `other`.
     *
     * @param  list<SubjectTile>  $tiles
     * @return list<array{letter: string, anchor: string, tiles: list<SubjectTile>}>
     */
    public static function groups(array $tiles): array
    {
        $groups = [];

        foreach ($tiles as $tile) {
            $groups[$tile->anchor] ??= ['letter' => $tile->letter, 'anchor' => $tile->anchor, 'tiles' => []];
            $groups[$tile->anchor]['tiles'][] = $tile;
        }

        // the «other» group last, whatever the collation put first
        uasort($groups, static fn (array $a, array $b): int => ($a['anchor'] === 'other') <=> ($b['anchor'] === 'other'));

        return array_values($groups);
    }

    /** The anchor of a letter (`a`, `б`) and the letter itself; a name that starts with anything else is the group `#` / `other`. @return array{0: string, 1: string} */
    public static function letter(string $name): array
    {
        $first = mb_strtoupper(mb_substr(trim($name), 0, 1));

        return preg_match('/^\p{L}$/u', $first) === 1 ? [$first, mb_strtolower($first)] : ['#', 'other'];
    }

    /** The EXISTS part: a visible product (the listing's own rule) that belongs to the row of `$table`, by the product's column or by its pivot. */
    private function visibleProducts(CatalogSubjectKind $kind, string $table): \Illuminate\Database\Query\Builder
    {
        $products = $this->visibility->listed(DB::table('catalog_products'), 'catalog_products');
        $pivot = $kind->pivot();

        if ($pivot === null) {
            $products->whereColumn('catalog_products.'.$kind->productColumn(), $table.'.id');
        } else {
            $products->join($pivot[0].' as pv', 'pv.product_id', '=', 'catalog_products.id')->whereColumn('pv.'.$pivot[1], $table.'.id');
        }

        return $products->selectRaw('1');
    }

    /**
     * @param  list<object>  $rows
     * @param  array<int, ?\App\Storefront\ReadModels\ImageSet>  $images
     * @return array<int, ?\App\Storefront\ReadModels\ImageSet>
     */
    private function withProductPictures(array $rows, array $images): array
    {
        $missing = array_values(array_map(static fn (object $row): int => $row->id, array_filter($rows, static fn (object $row): bool => ($images[$row->id] ?? null) === null)));

        if ($missing === []) {
            return $images;
        }

        // the visible products of the missing collections, in id order: the first one with a usable image wins (one statement, one row per pair)
        $pairs = $this->visibility->listed(DB::table('catalog_product_collections as pc')->join('catalog_products', 'catalog_products.id', '=', 'pc.product_id'), 'catalog_products')
            ->whereIn('pc.collection_id', $missing)
            ->orderBy('catalog_products.id')
            ->select(['pc.collection_id', 'catalog_products.id as product_id'])
            ->addSelect(['first_media_id' => CatalogReader::firstImageIdSubquery()])
            ->get();

        $picked = [];

        foreach ($pairs as $pair) {
            $collection = (int) $pair->collection_id;

            if ($pair->first_media_id !== null && ! isset($picked[$collection])) {
                $picked[$collection] = (int) $pair->first_media_id;
            }
        }

        if ($picked === []) {
            return $images;
        }

        $assets = MediaAssetModel::query()->whereIn('id', array_values($picked))->get()->keyBy('id');

        foreach ($picked as $collection => $mediaId) {
            $asset = $assets[$mediaId] ?? null;

            if ($asset !== null) {
                $images[$collection] = $this->images->build($asset, '', self::SIZES);
            }
        }

        return $images;
    }

    /** Bulgarian collation (`Collator('bg_BG')`, ext-intl is required by composer.json); without it a case-insensitive byte order (documented fallback, Cyrillic after Latin). */
    private static function sortByName(array $rows): array
    {
        $collator = class_exists(\Collator::class) ? new \Collator('bg_BG') : null;

        usort($rows, static function (object $a, object $b) use ($collator): int {
            $order = $collator !== null
                ? (int) $collator->compare($a->name, $b->name)
                : strcmp(mb_strtolower($a->name), mb_strtolower($b->name));

            return $order !== 0 ? $order : $a->id <=> $b->id;
        });

        return $rows;
    }
}
