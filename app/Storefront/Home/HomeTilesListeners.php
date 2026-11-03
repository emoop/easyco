<?php

namespace App\Storefront\Home;

use Closure;
use EasyCo\Catalog\Persistence\Eloquent\BrandModel;
use EasyCo\Catalog\Persistence\Eloquent\CategoryModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductCategoryModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Persistence\Eloquent\SeasonModel;
use EasyCo\Catalog\Persistence\Eloquent\TagModel;
use EasyCo\Media\Events\MediaAssetReady;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;

/**
 * HP-3: the triggers of HomeTilesRecompiler, on the Eloquent model events every writer already uses (the pattern of MenuRecompileListeners) and the ready event of the media pipeline (HB-4.1):
 *
 *  - CategoryModel / SeasonModel / TagModel / BrandModel saved or deleted: renamed, a new picture or logo, gone;
 *  - ProductModel (its status, visibility or deletion) and ProductCategoryModel: which categories the shop shows;
 *  - a media asset that becomes READY (the event, dispatched by ProcessMediaAssetJob in the queue worker) or is deleted.
 */
final class HomeTilesListeners
{
    public static function register(): void
    {
        $entity = static fn (string $source): Closure => static function ($model) use ($source): void {
            app(HomeTilesRecompiler::class)->entity($source, (int) $model->getKey());
        };

        foreach (['category' => CategoryModel::class, 'season' => SeasonModel::class, 'tag' => TagModel::class, 'brand' => BrandModel::class] as $source => $class) {
            $class::saved($entity($source));
            $class::deleted($entity($source));
        }

        $categories = static fn () => app(HomeTilesRecompiler::class)->anyCategory();

        ProductModel::saved(static function (ProductModel $changed) use ($categories): void {
            if ($changed->wasRecentlyCreated || $changed->wasChanged(['status', 'catalog_visibility', 'deleted_at'])) {
                $categories();
            }
        });
        ProductModel::deleted($categories);
        ProductModel::restored($categories);
        ProductCategoryModel::saved($categories);
        ProductCategoryModel::deleted($categories);

        MediaAssetModel::deleted(static fn (MediaAssetModel $asset) => app(HomeTilesRecompiler::class)->asset((int) $asset->getKey()));
        Event::listen(MediaAssetReady::class, static fn (MediaAssetReady $event) => app(HomeTilesRecompiler::class)->asset((int) $event->mediaAssetId));

        // the worker never terminates the app between jobs: the pending request is flushed after each one
        Event::listen(JobProcessed::class, static fn () => app(HomeTilesRecompiler::class)->flush());
        Event::listen(JobFailed::class, static fn () => app(HomeTilesRecompiler::class)->flush());
    }
}
