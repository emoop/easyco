<?php

namespace App\Storefront\Filters;

use App\Storefront\ReadModels\CatalogSubjectKind;
use App\Storefront\Visibility\StorefrontVisibility;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * CT-3: the SQL of a selection. Parameterised only: identifiers and values are whole numbers that FilterSelection already resolved against the shop's own filters.
 *
 * A product matches a selection (`attribute => values`) when EVERY filter is satisfied, and for each filter:
 *   - a PRODUCT-level value (`catalog_product_attributes`, not a variation axis) satisfies it by itself (stock is no part of it: the product has the value), or
 *   - a VARIATION-level value (`catalog_variation_attribute_values`) satisfies it on a variation that is shown to customers (StorefrontVisibility::variations) AND in stock (`stock_levels.quantity > 0`,
 *     the storefront's own «in stock» fact) — and ALL the variation-level filters of the selection are satisfied by the SAME variation (red AND M means ONE red-M variation, not a red-S and a blue-M one).
 * A selection of product-level values only needs no in-stock variation. Inside one filter the values are OR.
 */
final class FilterConstraint
{
    /**
     * CT-4: the reserved keys of the built-in filters. A built-in is a fact of the PRODUCT (its brand, its season, its tags), so it narrows the products directly: no variation, no stock and no «same
     * variation» rule is involved; it is ANDed with the attribute filters.
     */
    public const BUILTIN = CatalogSubjectKind::FILTER_KEYS;

    /** P2: the switches (`featured`): in the constraint map like a built-in, but never a `filter[...]` key of the URL. */
    public const SWITCHES = ['featured'];

    /**
     * Restricts a products query (`$productTable` is its alias or name) to the products that match the selection, leaving out the filter `$except` (a filter's own choices never narrow its own list).
     *
     * @param  \Illuminate\Database\Eloquent\Builder|QueryBuilder  $query
     * @param  array<int|string, list<int>>  $map
     */
    public static function apply($query, array $map, int|string|null $except = null, string $productTable = 'catalog_products'): void
    {
        $map = array_diff_key($map, $except === null ? [] : [$except => 1]);
        [$builtin, $map] = self::split($map);

        self::builtin($query, $builtin, $productTable);

        if ($map === []) {
            return;
        }

        $query->where(function ($match) use ($map, $productTable): void {
            $match->where(function ($product) use ($map, $productTable): void {
                foreach ($map as $attribute => $values) {
                    $product->whereExists(self::productLevel($attribute, $values, $productTable));
                }
            })->orWhereExists(self::onOneVariation(self::shownInStockVariations($productTable), $map, $productTable));
        });
    }

    /**
     * CT-4: the selection split into its built-in part (brand / season / tag) and its attribute part.
     *
     * @param  array<int|string, list<int>>  $map
     * @return array{0: array<string, list<int>>, 1: array<int, list<int>>}
     */
    public static function split(array $map): array
    {
        $builtin = array_intersect_key($map, array_flip([...self::BUILTIN, ...self::SWITCHES]));

        return [$builtin, array_diff_key($map, $builtin)];
    }

    /**
     * CT-4: «the product has the brand / season / tag» for each built-in of the map (OR inside one, AND between). Parameterised: the ids are whole numbers FilterSelection resolved against the shop's own lists.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|QueryBuilder  $query
     * @param  array<string, list<int>>  $map
     */
    public static function builtin($query, array $map, string $productTable = 'catalog_products'): void
    {
        foreach ($map as $kind => $ids) {
            // P6e-2: a subject kind (brand | season | tag | collection) narrows through the registry; `featured` is a switch of the product itself
            $kind === 'featured'
                ? $query->where($productTable.'.is_featured', true)
                : CatalogSubjectKind::from($kind)->constrainFilter($query, $ids, $productTable);
        }
    }

    /** `catalog_variations as v` of the product (`$productTable.id`), shown to customers and in stock. */
    public static function shownInStockVariations(string $productTable): QueryBuilder
    {
        $variations = DB::table('catalog_variations as v')->selectRaw('1')->whereColumn('v.product_id', $productTable.'.id');
        app(StorefrontVisibility::class)->variations($variations, 'v');

        return self::inStock($variations);
    }

    /** Adds «the variation `v` has stock» to a query that has `catalog_variations as v`. */
    public static function inStock(QueryBuilder $query): QueryBuilder
    {
        return $query->whereExists(DB::table('stock_levels as s')->selectRaw('1')->whereColumn('s.variation_id', 'v.id')->where('s.quantity', '>', 0));
    }

    /**
     * Adds, for each filter of the map, «the product has the value OR the variation `v` has it»: the same `v` for all of them.
     *
     * @param  array<int, list<int>>  $map
     */
    public static function onOneVariation(QueryBuilder $variations, array $map, string $productTable): QueryBuilder
    {
        foreach ($map as $attribute => $values) {
            $variations->where(function ($either) use ($attribute, $values, $productTable): void {
                $either->whereExists(self::productLevel($attribute, $values, $productTable))->orWhereExists(self::variationLevel($attribute, $values));
            });
        }

        return $variations;
    }

    /** @param list<int> $values */
    private static function productLevel(int $attribute, array $values, string $productTable): QueryBuilder
    {
        return DB::table('catalog_product_attributes as pa')->selectRaw('1')
            ->whereColumn('pa.product_id', $productTable.'.id')
            ->where('pa.attribute_definition_id', $attribute)
            ->where('pa.is_variation_axis', false)
            ->whereIn('pa.attribute_value_id', $values);
    }

    /** @param list<int> $values */
    private static function variationLevel(int $attribute, array $values): QueryBuilder
    {
        return DB::table('catalog_variation_attribute_values as vv')->selectRaw('1')
            ->whereColumn('vv.variation_id', 'v.id')
            ->where('vv.attribute_definition_id', $attribute)
            ->whereIn('vv.attribute_value_id', $values);
    }
}
