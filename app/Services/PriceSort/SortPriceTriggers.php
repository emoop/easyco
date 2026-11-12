<?php

namespace App\Services\PriceSort;

use EasyCo\Catalog\Persistence\Eloquent\ProductCategoryModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductCollectionModel;
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
 *  - ProductTagModel / ProductCategoryModel / ProductCollectionModel (P6e-1) saved / deleted: tags and categories are scope inputs of price lists, collections will be;
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
    /** How many writers are inside muted(): while > 0 the model events below queue nothing. */
    private static int $muted = 0;

    /**
     * P6b: runs $write with the model-event triggers MUTED. A writer that saves a price list AND its scope rows in one transaction (PriceListWriter) must not let the first save queue the «unscoped list → every
     * product» pass in the middle of the write (the list row is saved before its scope rows); it mutes the triggers for its own saves and queues ONE explicit refresh after the commit instead. Nothing else mutes.
     *
     * @template T
     * @param callable(): T $write
     * @return T
     */
    public static function muted(callable $write): mixed
    {
        self::$muted++;

        try {
            return $write();
        } finally {
            self::$muted--;
        }
    }

    public static function register(): void
    {
        $dispatcher = static fn (): SortPriceRefreshDispatcher => app(SortPriceRefreshDispatcher::class);

        // EVERY handler returns void on purpose: Laravel stops the remaining listeners of a model event when one of them returns `false`, and other listeners (the menus, the filters) listen to the same events.
        $product = static function (callable $productId) use ($dispatcher): callable {
            return static function ($model) use ($productId, $dispatcher): void {
                if (self::$muted === 0) {
                    $dispatcher()->product($productId($model));
                }
            };
        };

        ProductModel::saved($product(static fn (ProductModel $model) => $model->id));

        foreach (['saved', 'deleted'] as $event) {
            VariationModel::{$event}($product(static fn (VariationModel $model) => $model->product_id));
            ProductTagModel::{$event}($product(static fn (ProductTagModel $model) => $model->product_id));
            // P6e-1: a collection is not a price-list scope yet (P6e-3 makes it one); the trigger is registered now so the refresh needs no second look then
            ProductCollectionModel::{$event}($product(static fn (ProductCollectionModel $model) => $model->product_id));
            ProductCategoryModel::{$event}($product(static fn (ProductCategoryModel $model) => $model->product_id));

            PriceListItemModel::{$event}(static function (PriceListItemModel $item) use ($dispatcher): void {
                if (self::$muted !== 0) {
                    return;
                }

                $productId = $item->target_type === 'product'
                    ? $item->target_id
                    : DB::table('catalog_variations')->where('id', $item->target_id)->value('product_id');

                if ($productId !== null) {
                    $dispatcher()->product($productId);
                }
            });

            PriceListModel::{$event}(static function (PriceListModel $list) use ($dispatcher): void {
                if (self::$muted === 0) {
                    $dispatcher()->priceList((string) $list->id);
                }
            });

            PriceListScopeModel::{$event}(static function (PriceListScopeModel $scope) use ($dispatcher): void {
                if (self::$muted === 0) {
                    $dispatcher()->priceListScopeChanged((string) $scope->price_list_id, (string) $scope->id, (string) $scope->scope_type, (string) $scope->scope_reference_id);
                }
            });
        }
    }
}
