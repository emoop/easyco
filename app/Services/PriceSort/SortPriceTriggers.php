<?php

namespace App\Services\PriceSort;

use EasyCo\Catalog\Persistence\Eloquent\ProductCategoryModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductTagModel;
use EasyCo\Catalog\Persistence\Eloquent\VariationModel;
use EasyCo\Pricing\Persistence\Eloquent\PriceListItemModel;
use EasyCo\Pricing\Persistence\Eloquent\PriceListModel;
use EasyCo\Pricing\Persistence\Eloquent\PriceListScopeModel;
use Illuminate\Support\Facades\DB;

/**
 * P6a, the FAST PATH: the events after which a product's stored sort price (`catalog_products.price_from_minor`) can be wrong, wired where the app already has ONE place per change — the persistence models' own
 * `saved` / `deleted` events (every repository write of these goes through `$model->save()` / `->delete()`). Each event queues a refresh (App\Jobs\RefreshProductSortPrices, unique per set of products, after commit).
 *
 *  - ProductModel saved: ANY product save (the admin's product form saves through the repository, so every admin save refreshes the product: its brand, season and status are on this row);
 *  - VariationModel saved / deleted: a variation added, removed, activated, deactivated or its attribute values changed;
 *  - ProductTagModel / ProductCategoryModel saved / deleted: tags and categories are scope inputs of price lists;
 *  - PriceListItemModel saved / deleted: a regular or sale price (product or variation level);
 *  - PriceListModel saved / deleted: a list created, edited (percentage, priority, mode, dates), activated, deactivated or deleted → the products the list can touch (ALL when it has no scope);
 *  - PriceListScopeModel saved / deleted: a scope attached or detached → the products the list's OTHER scopes touch (ALL when it has no other).
 * The stock levels are not Eloquent writes (atomic increment / decrement): StockRefreshingRepository decorates the repository instead. A write that bypasses all of these (an import, a direct database change)
 * is repaired by the nightly `prices:refresh-from`.
 *
 * The refresher itself writes with the query builder, so none of this can loop.
 */
final class SortPriceTriggers
{
    public static function register(): void
    {
        $dispatcher = static fn (): SortPriceRefreshDispatcher => app(SortPriceRefreshDispatcher::class);

        ProductModel::saved(static fn (ProductModel $product) => $dispatcher()->product($product->id));

        foreach (['saved', 'deleted'] as $event) {
            VariationModel::{$event}(static fn (VariationModel $variation) => $dispatcher()->product($variation->product_id));
            ProductTagModel::{$event}(static fn (ProductTagModel $row) => $dispatcher()->product($row->product_id));
            ProductCategoryModel::{$event}(static fn (ProductCategoryModel $row) => $dispatcher()->product($row->product_id));

            PriceListItemModel::{$event}(static function (PriceListItemModel $item) use ($dispatcher): void {
                $productId = $item->target_type === 'product'
                    ? $item->target_id
                    : DB::table('catalog_variations')->where('id', $item->target_id)->value('product_id');

                $productId === null || $dispatcher()->product($productId);
            });

            PriceListModel::{$event}(static fn (PriceListModel $list) => $dispatcher()->priceList((string) $list->id));
            PriceListScopeModel::{$event}(static fn (PriceListScopeModel $scope) => $dispatcher()->priceList((string) $scope->price_list_id, (string) $scope->id));
        }
    }
}
