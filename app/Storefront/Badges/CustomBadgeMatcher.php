<?php

namespace App\Storefront\Badges;

use App\Storefront\Conditions\ProductConditions;
use App\Storefront\Conditions\ProductContext;
use App\Storefront\ReadModels\CatalogSubjectKind;
use App\Storefront\ReadModels\PriceBlock;
use Illuminate\Support\Facades\DB;

/**
 * P7-2: which custom badges (BadgeSet::CUSTOM rows) each product of a listing page earns, worked out ONCE for the whole page (badges-research.md, «As built (P7-2)»). The conditions are the product-page blocks' own
 * (ProductConditions, the same predicates over a ProductContext); this class only FILLS the contexts of all the page's products together, so the predicates never read a thing:
 *   - brand and season ride on the product rows the listing already loaded (0 statements); `on_sale` is the price block the card prints (0);
 *   - categories: ONE grouped read of the pivot (+ the category tree, once per request: it is the same read `ProductContext` makes), tags: ONE, collections: ONE (the collections switch rides INSIDE the statement:
 *     while it is OFF nothing comes back, so a collections condition is false and the badge is hidden).
 * Only the condition kinds an ENABLED custom row actually uses are read, so a page with no custom badge costs nothing, and the number of statements never depends on the number of cards.
 */
final class CustomBadgeMatcher
{
    /**
     * @param  list<array<string, mixed>>  $rows  the enabled custom rows (BadgeSet::fromJson()['custom'])
     * @param  list<array{id: int, brandId: ?int, seasonId: ?int, price: ?PriceBlock}>  $products
     * @return array<int, array<string, true>> product id => the ids of the custom rows it matches
     */
    public static function match(array $rows, array $products): array
    {
        if ($rows === [] || $products === []) {
            return [];
        }

        $used = [];

        foreach ($rows as $row) {
            foreach (array_keys((array) $row['conditions']) as $key) {
                $used[$key] = true;
            }
        }

        $ids = array_map(static fn (array $p): int => $p['id'], $products);
        $categories = isset($used['categories']) ? self::grouped(DB::table('catalog_product_categories'), $ids, 'category_id') : [];
        $tags = isset($used['tags']) ? self::grouped(DB::table('catalog_product_tags'), $ids, 'tag_id') : [];
        $collections = isset($used['collections']) ? self::grouped(CatalogSubjectKind::COLLECTION->gated(DB::table('catalog_product_collections')), $ids, 'collection_id') : [];
        $out = [];

        foreach ($products as $product) {
            $context = ProductContext::prefilled(
                $product['id'],
                $product['price'],
                $product['brandId'],
                isset($used['categories']) ? ProductContext::withAncestors($categories[$product['id']] ?? []) : [],
                $tags[$product['id']] ?? [],
                $collections[$product['id']] ?? [],
                $product['seasonId'],
            );

            foreach ($rows as $row) {
                if (ProductConditions::matches($context, (array) $row['conditions'])) {
                    $out[$product['id']][(string) $row['id']] = true;
                }
            }
        }

        return $out;
    }

    /** @param list<int> $productIds @return array<int, list<int>> product id => the ids of the `$column` */
    private static function grouped($query, array $productIds, string $column): array
    {
        $out = [];

        foreach ($query->whereIn('product_id', $productIds)->get(['product_id', $column]) as $row) {
            $out[(int) $row->product_id][] = (int) $row->{$column};
        }

        return $out;
    }
}
