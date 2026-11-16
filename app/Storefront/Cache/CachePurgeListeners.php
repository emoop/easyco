<?php

namespace App\Storefront\Cache;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Content\Models\PageModel;
use EasyCo\Catalog\Persistence\Eloquent\BrandModel;
use EasyCo\Catalog\Persistence\Eloquent\CategoryModel;
use EasyCo\Catalog\Persistence\Eloquent\CollectionModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductCategoryModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductCollectionModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductTagModel;
use EasyCo\Catalog\Persistence\Eloquent\SeasonModel;
use EasyCo\Catalog\Persistence\Eloquent\TagModel;
use EasyCo\Catalog\Persistence\Eloquent\VariationModel;
use EasyCo\Extensibility\Hook;
use EasyCo\Media\Events\MediaAssetReady;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use EasyCo\Media\Persistence\Eloquent\ProductMediaModel;
use EasyCo\Media\Persistence\Eloquent\VariationMediaModel;
use EasyCo\Pricing\Persistence\Eloquent\PriceListItemModel;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;

/**
 * P8b: the PURGE MAP (packages/EasyCo/documents/storefront-cache-contract.md). Every change that alters what a cacheable page shows ends in a
 * `CachePurgeCollector::add*()` call; nothing is purged from here, the collector flushes once per request / command / job.
 *
 * Triggers, in the same spirit as MenuRecompileListeners / HomeTilesListeners / SortPriceTriggers: the Eloquent model events of the models every writer already
 * uses (they run inside the write's transaction; collecting during a transaction that rolls back only costs an extra purge). The writes that BYPASS model events
 * are collected by hand where they happen (see the contract document): EloquentVariationRepository::updateSortOrders (an event), the measurement template
 * repository (an event), CatalogDeletion, ProductMassEditor, ProductSortPriceRefresher, SectionTexts, the stock decorator, and the settings repository.
 *
 * EVERY handler returns void: Laravel stops the remaining listeners of a model event when one returns `false`.
 */
final class CachePurgeListeners
{
    public static function register(): void
    {
        $c = static fn (): CachePurgeCollector => app(CachePurgeCollector::class);

        // ---- products ------------------------------------------------------------------------------------------------------------------------------
        ProductModel::saved(static function (ProductModel $product) use ($c): void {
            $c()->addProducts([$product->id]);

            // the OLD brand / season too: a product that moves out of a brand or a season shifts that page's pagination (the pivots give the old categories, tags, collections)
            foreach (['brand_id' => 'b', 'season_id' => 's'] as $column => $prefix) {
                if ($product->wasChanged($column)) {
                    $old = $product->getOriginal($column);
                    $new = $product->getAttribute($column);
                    $old === null || $c()->add($prefix.$old);
                    $new === null || $c()->add($prefix.$new);
                }
            }
        });

        // a real delete takes the pivot rows with it: their subjects are read BEFORE (three grouped statements, admin path only)
        ProductModel::deleting(static function (ProductModel $product) use ($c): void {
            $c()->addSubjectsOf([$product->id]);
        });

        foreach (['deleted', 'restored'] as $event) {
            ProductModel::{$event}(static function (ProductModel $product) use ($c): void {
                $c()->addProducts([$product->id]);
                $product->brand_id === null || $c()->add('b'.$product->brand_id);
                $product->season_id === null || $c()->add('s'.$product->season_id);
            });
        }

        foreach (['saved', 'deleted'] as $event) {
            VariationModel::{$event}(static function (VariationModel $variation) use ($c): void {
                $c()->addProducts([$variation->product_id]);
            });

            ProductCategoryModel::{$event}(static function (ProductCategoryModel $row) use ($c): void {
                $c()->addProducts([$row->product_id]);
                $c()->addCategories([$row->category_id]);
            });

            ProductTagModel::{$event}(static function (ProductTagModel $row) use ($c): void {
                $c()->addProducts([$row->product_id]);
                $c()->add('t'.$row->tag_id);
            });

            ProductCollectionModel::{$event}(static function (ProductCollectionModel $row) use ($c): void {
                $c()->addProducts([$row->product_id]);
                $c()->add('col'.$row->collection_id);
            });

            // a price row: the product rule for its product (a variation's product is resolved at flush: no query here)
            PriceListItemModel::{$event}(static function (PriceListItemModel $item) use ($c): void {
                $item->target_type === 'product' ? $c()->addProducts([$item->target_id]) : $c()->addVariationProducts([$item->target_id]);
            });

            // product media: the product's page and the cards that show its picture
            ProductMediaModel::{$event}(static function (ProductMediaModel $row) use ($c): void {
                $c()->add('p'.$row->product_id);
            });

            VariationMediaModel::{$event}(static function (VariationMediaModel $row) use ($c): void {
                $c()->addVariations([$row->variation_id]);
            });
        }

        // ---- subjects: their NAMES are printed in the sidebar filters, the menus and the tiles of EVERY page, so a save or a delete purges `all` too ----------------
        foreach (['saved', 'deleted'] as $event) {
            CategoryModel::{$event}(static function (CategoryModel $category) use ($c): void {
                $c()->addCategories([$category->id]);
                $c()->add('sitemap', 'home', 'all');
            });

            BrandModel::{$event}(static function (BrandModel $brand) use ($c): void {
                $c()->add('b'.$brand->id, 'idx-brand', 'sitemap', 'home', 'all');
            });

            SeasonModel::{$event}(static function (SeasonModel $season) use ($c): void {
                $c()->add('s'.$season->id, 'sitemap', 'home', 'all');
            });

            TagModel::{$event}(static function (TagModel $tag) use ($c): void {
                $c()->add('t'.$tag->id, 'sitemap', 'home', 'all');
            });

            CollectionModel::{$event}(static function (CollectionModel $collection) use ($c): void {
                $c()->add('col'.$collection->id, 'idx-collection', 'sitemap', 'home', 'all');
            });

            // an info page: publish, unpublish, rename, delete
            PageModel::{$event}(static function (PageModel $page) use ($c): void {
                $c()->add('pg'.$page->id, 'sitemap', 'all');
            });

            // an HTML block can sit in the menus, the footer, a listing template or a product slot: its scope is not one product
            HtmlBlockModel::{$event}(static function () use ($c): void {
                $c()->add('all');
            });
        }

        // ---- media ---------------------------------------------------------------------------------------------------------------------------------
        Event::listen(MediaAssetReady::class, static function (MediaAssetReady $event) use ($c): void {
            $c()->addMedia([$event->mediaAssetId]);
        });

        MediaAssetModel::deleted(static function (MediaAssetModel $asset) use ($c): void {
            $c()->addMedia([$asset->getKey()]);
        });

        // ---- the writes that bypass model events (the repositories dispatch plain events; the writers fire hooks) ------------------------------------
        Event::listen('catalog.variation.order_changed', static function (mixed $productId) use ($c): void {
            $c()->addProducts([$productId]);
        });

        Event::listen('catalog.product.measurement_template_changed', static function (mixed $productId) use ($c): void {
            $c()->add('p'.$productId);
        });

        // the size grid and the product's template (MeasureWriter & co. fire this after their commit)
        Hook::action('catalog.product.measurements.saved', static function (mixed $productId) use ($c): void {
            $c()->add('p'.$productId);
        });

        // measures and measurement templates: their names are printed in the size tables of every product that uses them
        foreach (['catalog.measure.saved', 'catalog.measure.archived', 'catalog.measure.deleted', 'catalog.measurement_template.saved', 'catalog.measurement_template.archived', 'catalog.measurement_template.deleted'] as $hook) {
            Hook::action($hook, static function () use ($c): void {
                $c()->add('all');
            });
        }

        // ---- flush points: once per queued job (a worker never terminates the app between jobs), once per request / command (ONE terminating callback, registered below) ----
        // (the `sync` queue runs a job INSIDE the request that dispatched it: that request flushes once, at its end)
        Event::listen(JobProcessed::class, static function (JobProcessed $event) use ($c): void {
            $event->connectionName === 'sync' || $c()->flush();
        });
        Event::listen(JobFailed::class, static function (JobFailed $event) use ($c): void {
            $event->connectionName === 'sync' || $c()->flush();
        });

        // ONE terminating callback per process (not one per scoped collector): it flushes whichever collector is current, and ends the badge memo
        app()->terminating(static function () use ($c): void {
            $c()->flush();
            app(\App\Storefront\Badges\BadgeMatchMemo::class)->end();
        });
    }
}
