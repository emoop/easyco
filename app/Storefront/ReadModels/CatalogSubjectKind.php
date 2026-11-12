<?php

namespace App\Storefront\ReadModels;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * P6e-2: the READ-SIDE registry of the catalog's «subject kinds» — the things a product can belong to and that have a page of their own: a brand, a season, a tag, a collection. One place says, per kind, its table, its
 * URL prefix, its listing scope, its «Съдържание» kind, its filter key, its crumb and how it narrows a product query: by a COLUMN of the product (`brand_id`, `season_id`: `whereIn` / `where`) or by a PIVOT (`tag`,
 * `collection`: `whereExists` / `whereIn (select product_id)`). The storefront's per-kind `match` arms and arrays read it (CatalogReader, FilterConstraint, SidebarFilters, ContentTemplate, ListingQuery, StorefrontUrls,
 * the routes), so a new kind is one case here instead of a hunt through a dozen files.
 *
 * READ-ONLY by design: the write side (the aggregate's `assignSeason`, the pivot repositories, the admin resources, the mass edit) is NOT here — a column and a pivot are different things to write. Every query this
 * class builds has the SAME SQL text the hand-written arm it replaced had (ListingScope tests compare `toSql()`); the categories are not a subject kind (a tree, with its own index).
 *
 * A kind can be GATED by a Catalog switch (`gate()`): the collection is on only for the literal `'1'` of `catalog.collections_enabled` (a missing key = off). The switch is never read through the appearance payload
 * and never costs a query: it rides INSIDE the statement that needs it as `EXISTS (select 1 from site_settings where key = … and value = '1')` (the P6c `soldOutShownSetting` shape) — see `gated()`.
 */
enum CatalogSubjectKind: string
{
    case BRAND = 'brand';
    case SEASON = 'season';
    case TAG = 'tag';
    case COLLECTION = 'collection';

    /** The `site_settings` key of the collections switch (`'1'` = on, a missing key = off). */
    public const COLLECTIONS_SWITCH = 'catalog.collections_enabled';

    /** The reserved filter keys of the built-in filters, in the default order. Aliased by FilterConstraint::BUILTIN and SidebarFilters::BUILTIN. */
    public const FILTER_KEYS = ['brand', 'season', 'tag', 'collection'];

    /** «Съдържание» kind => the scope of its pages (the subject kinds; the categories are added by ContentTemplate). */
    public const CONTENT_KINDS = ['brands' => ListingScope::BRAND, 'seasons' => ListingScope::SEASON, 'tags' => ListingScope::TAG, 'collections' => ListingScope::COLLECTION];

    /** The request key that selects the scope => the scope. Aliased by ListingQuery. */
    public const SCOPE_KEYS = ['brand' => ListingScope::BRAND, 'tag' => ListingScope::TAG, 'season' => ListingScope::SEASON, 'collection' => ListingScope::COLLECTION];

    /** The «Каталог» fields that hold the look of each filter (`off` | list | links | buttons): the «Сайдбар» zone carries them. */
    public const CATALOG_FIELDS = ['filterBrand', 'filterSeason', 'filterTag', 'filterCollection'];

    /** @return list<self> */
    public static function filterOrder(): array
    {
        return array_map(static fn (string $key): self => self::from($key), self::FILTER_KEYS);
    }

    public static function fromScope(ListingScope $scope): ?self
    {
        return self::tryFrom($scope->value);
    }

    public function scope(): ListingScope
    {
        return ListingScope::from($this->value);
    }

    public function table(): string
    {
        return match ($this) {
            self::BRAND => 'catalog_brands',
            self::SEASON => 'catalog_seasons',
            self::TAG => 'catalog_tags',
            self::COLLECTION => 'catalog_collections',
        };
    }

    /** The public page's path prefix, `/brand/`. */
    public function urlPrefix(): string
    {
        return match ($this) {
            self::BRAND => '/brand/',
            self::SEASON => '/season/',
            self::TAG => '/product-tag/',
            self::COLLECTION => '/collection/',
        };
    }

    /** The key of the «Съдържание» templates of this kind's pages (`seasons`). */
    public function contentKind(): string
    {
        return (string) array_search($this->scope(), self::CONTENT_KINDS, true);
    }

    /** The lang key of the breadcrumb's first (plain text) step. */
    public function crumbKey(): string
    {
        return 'storefront.crumb_'.$this->value;
    }

    /** The lang key of the filter's name. */
    public function filterNameKey(): string
    {
        return 'storefront.filter_'.$this->value;
    }

    /** The «Каталог» field that holds the look of this kind's filter (`filterSeason`). */
    public function catalogField(): string
    {
        return 'filter'.ucfirst($this->value);
    }

    /** The product's own column for a COLUMN kind (`brand_id`), null for a PIVOT kind. */
    public function productColumn(): ?string
    {
        return match ($this) {
            self::BRAND => 'brand_id',
            self::SEASON => 'season_id',
            default => null,
        };
    }

    /** For a PIVOT kind: [the pivot table, its column that holds this kind's id]; null for a COLUMN kind. @return array{0: string, 1: string}|null */
    public function pivot(): ?array
    {
        return match ($this) {
            self::TAG => ['catalog_product_tags', 'tag_id'],
            self::COLLECTION => ['catalog_product_collections', 'collection_id'],
            default => null,
        };
    }

    /** Is this kind switched by a Catalog setting (the collection)? The brand, the season and the tag are not: their pages work whatever the admin's switches say. */
    public function gate(): ?string
    {
        return $this === self::COLLECTION ? self::COLLECTIONS_SWITCH : null;
    }

    /** Is the collections switch on (the literal `'1'`)? A read for the ADMIN screens (memoised by the settings repository); the storefront reads the switch inside its statements (`gated()`). */
    public static function collectionsEnabled(): bool
    {
        return app(\App\Settings\Contracts\SiteSettingsRepository::class)->get(self::COLLECTIONS_SWITCH) === '1';
    }

    /** `select 1 from site_settings where key = 'catalog.collections_enabled' and value = '1'`: the shape that rides INSIDE another statement (no query of its own). */
    public static function collectionsSwitchedOn(string $select = '1'): QueryBuilder
    {
        return DB::table('site_settings')->selectRaw($select)->where('key', self::COLLECTIONS_SWITCH)->where('value', '1');
    }

    /** Adds «this kind is switched on» to a query of this kind's table (or of a select that is only meaningful when it is): nothing for an ungated kind. @template T of QueryBuilder|EloquentBuilder @param T $query @return T */
    public function gated($query)
    {
        return $this->gate() === null ? $query : $query->whereExists(self::collectionsSwitchedOn());
    }

    /**
     * «The product has THIS subject» as the FIXED part of a listing (a subject page): `where product.column = id`, or `whereIn product.id (select product_id from pivot where fk = id)`. Also the cloud's constraint.
     *
     * @param  EloquentBuilder|QueryBuilder  $products
     */
    public function constrainScope($products, int $id): void
    {
        $pivot = $this->pivot();

        $pivot === null
            ? $products->where('catalog_products.'.$this->productColumn(), $id)
            : $products->whereIn('catalog_products.id', DB::table($pivot[0])->where($pivot[1], $id)->select('product_id'));
    }

    /**
     * «The product has one of these subjects» as a FILTER the visitor chose (OR inside): `whereIn column`, or `whereExists (pivot as pt …)`.
     *
     * @param  EloquentBuilder|QueryBuilder  $query
     * @param  list<int>  $ids
     */
    public function constrainFilter($query, array $ids, string $productTable = 'catalog_products'): void
    {
        $pivot = $this->pivot();

        $pivot === null
            ? $query->whereIn($productTable.'.'.$this->productColumn(), $ids)
            : $query->whereExists(DB::table($pivot[0].' as pt')->selectRaw('1')->whereColumn('pt.product_id', $productTable.'.id')->whereIn('pt.'.$pivot[1], $ids));
    }

    /**
     * The ids of this kind that the products of `$products` (a query on `catalog_products`) have: the facet of the filter.
     *
     * @return \Illuminate\Support\Collection<int, mixed>
     */
    public function facetIds(QueryBuilder $products)
    {
        $pivot = $this->pivot();

        if ($pivot === null) {
            $column = 'catalog_products.'.$this->productColumn();

            return $products->whereNotNull($column)->distinct()->pluck($column);
        }

        return $products->join($pivot[0].' as pt', 'pt.product_id', '=', 'catalog_products.id')->distinct()->pluck('pt.'.$pivot[1]);
    }

    /** The scalar «does the shop have at least one of this kind?» of the listing query (`has_brand_filter`): one uncorrelated subselect, the switch inside it for a gated kind. */
    public function hasAnySubselect(): QueryBuilder
    {
        return $this->gated(DB::table($this->table())->selectRaw('1')->limit(1));
    }
}
