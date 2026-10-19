<?php

namespace App\Storefront\Menu;

use App\Content\Models\PageModel;
use EasyCo\Catalog\Persistence\Eloquent\CategoryModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductCategoryModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;

/**
 * The triggers of the menu rebuild (D3). They are the Eloquent model events of the models every writer already uses (there is no separate domain
 * event for these changes; none had to be added):
 *
 *  - PageModel saved / deleted when its status, slug or title changed (or it was just created): publish, unpublish, archive, rename, new slug;
 *  - CategoryModel saved / deleted: created, renamed, moved (a category is "hidden / shown" by its products, below);
 *  - ProductModel saved when its status or catalog visibility changed, deleted, restored; ProductCategoryModel saved / deleted: these change which
 *    categories StorefrontVisibility shows. A save that touches none of those fields (a price, a stock figure, a name) does NOT ask for a rebuild;
 *  - MediaAssetModel saved / deleted when a menu item points at it (a picture that became READY is picked up, a deleted one is dropped).
 *
 * Writes that bypass Eloquent (raw DB::table inserts in tests or a console script) fire no event: `MenuRecompiler::now()` or saving the menu
 * repairs that. After a queue job the pending request is flushed (the worker never terminates the app between jobs).
 */
final class MenuRecompileListeners
{
    public static function register(): void
    {
        $pages = static fn () => app(MenuRecompiler::class)->request('page');
        $catalog = static fn () => app(MenuRecompiler::class)->request('category');

        PageModel::saved(static function (PageModel $page) use ($pages): void {
            if ($page->wasRecentlyCreated || $page->wasChanged(['status', 'slug', 'title'])) {
                $pages();
            }
        });
        PageModel::deleted($pages);

        CategoryModel::saved($catalog);
        CategoryModel::deleted($catalog);

        ProductModel::saved(static function (ProductModel $product) use ($catalog): void {
            if ($product->wasRecentlyCreated || $product->wasChanged(['status', 'catalog_visibility', 'deleted_at'])) {
                $catalog();
            }
        });
        ProductModel::deleted($catalog);
        ProductModel::restored($catalog);
        ProductCategoryModel::saved($catalog);
        ProductCategoryModel::deleted($catalog);

        MediaAssetModel::saved(static function (MediaAssetModel $asset): void {
            if (MenuCompiler::usesAsset((int) $asset->id)) {
                app(MenuRecompiler::class)->request();
            }
        });
        MediaAssetModel::deleted(static function (MediaAssetModel $asset): void {
            if (MenuCompiler::usesAsset((int) $asset->id)) {
                app(MenuRecompiler::class)->request();
            }
        });

        Event::listen(JobProcessed::class, static fn () => app(MenuRecompiler::class)->flush());
        Event::listen(JobFailed::class, static fn () => app(MenuRecompiler::class)->flush());
    }
}
