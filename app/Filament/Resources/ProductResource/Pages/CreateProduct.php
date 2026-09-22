<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Services\ActivityLogger;
use App\Services\ProductPricingAndStock;
use EasyCo\Catalog\Contracts\ProductCategoryRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\ProductTagRepository;
use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\ProductCategory;
use EasyCo\Catalog\ProductTag;
use EasyCo\Extensibility\Hook;
use EasyCo\Media\Contracts\ProductMediaRepository;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\Exceptions\MediaLimitExceededException;
use EasyCo\Media\ProductMedia;
use EasyCo\Media\ProductMediaCountGuard;
use EasyCo\Media\VideoCountGuard;
use EasyCo\Staff\Enums\Permission;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Write-interception per admin-panel-design.md §5 — every write goes
 * through the real domain Product + ProductRepository, never a raw
 * Eloquent ::create(). Mirrors ProductController::store()'s own
 * base_sku/slug/barcode Hook sequence exactly.
 *
 * SINGLE save(), not the two-save sequence a first read of the task
 * might suggest: setDescriptiveAttribute() is purely in-memory (no
 * product id involved — keyed by attribute_definition_id), and
 * EloquentProductRepository::save() itself persists the Product row,
 * the universal Variation, AND descriptive attributes together inside
 * one DB::transaction() (confirmed by reading save()'s real body).
 * Building the whole aggregate in memory first, then calling save()
 * exactly once, is both simpler and more correct than saving twice.
 * Categories/tags/media genuinely do need the real post-save product
 * id (they reference it by a real foreign key), so those three steps
 * come after the single save() call, not before.
 *
 * WRAPPED IN ITS OWN DB::transaction() — a real, confirmed gap found
 * while testing the media-limit rejection: Filament's own
 * beginDatabaseTransaction()/rollBackDatabaseTransaction() (which a
 * thrown Halt normally triggers) are real no-ops here, because
 * Panel::hasDatabaseTransactions() defaults to false and
 * AdminPanelProvider never opts in. Without this explicit wrap, a
 * media-limit rejection on photo N+1 left the Product and its first N
 * photos fully committed — exactly the half-created state this method
 * is supposed to prevent. Scoped to this Resource only, not a
 * panel-wide ->databaseTransactions(true) change, which would alter
 * every other write-interception page's behavior well outside this
 * task's scope.
 */
class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(fn (): Model => $this->createProduct($data));
    }

    private function createProduct(array $data): Model
    {
        $baseSku = Hook::apply('catalog.product.base_sku', $data['base_sku'] ?? '');
        $slug = Hook::apply('catalog.product.slug', $data['slug'] ?? '', $data['name']);

        $product = Product::createSimple($data['name'], $baseSku, $slug);

        if (filled($data['description'] ?? null)) {
            $product->changeDescription($data['description']);
        }

        match ($data['status'] ?? ProductStatus::DRAFT->value) {
            ProductStatus::ACTIVE->value => $product->publish(),
            ProductStatus::ARCHIVED->value => $product->archive(),
            default => $product->markAsDraft(),
        };

        $product->setCatalogVisibility(CatalogVisibility::from($data['catalog_visibility'] ?? CatalogVisibility::HIDDEN->value));
        $product->assignBrand($data['brand_id'] ?? null);
        $product->assignSeason($data['season_id'] ?? null);
        $product->assignProductGroup($data['product_group_id'] ?? null);

        $universal = $product->universalVariation();

        $barcode = Hook::apply('catalog.variation.barcode', $data['barcode'] ?? '', $universal);
        if ($barcode !== '') {
            $universal->setBarcode($barcode);
        }

        $universal->setPurchasable((bool) ($data['is_purchasable'] ?? true));

        // Must run BEFORE save() below — setDescriptiveAttribute() is
        // purely in-memory (see this class's own "single save()" docblock).
        // $product->id() is still null here; ProductResource::
        // syncDescriptiveAttributesFromPickerRows() itself skips its own
        // logFieldChanged() calls in that case (a real TypeError found
        // and fixed while testing this — see that method's own
        // docblock), which also correctly preserves this page's
        // original behavior: no per-field descriptive-attribute log
        // entries on creation, only logCreated() below.
        ProductResource::syncDescriptiveAttributesFromPickerRows($product, $data['descriptive_attributes_picker'] ?? [], app(ActivityLogger::class));

        app(ProductRepository::class)->save($product);

        $productId = $product->id();

        foreach ($data['categories'] ?? [] as $categoryId) {
            app(ProductCategoryRepository::class)->save(
                new ProductCategory(id: null, productId: $productId, categoryId: (string) $categoryId)
            );
        }

        foreach ($data['tags'] ?? [] as $tagId) {
            app(ProductTagRepository::class)->save(
                new ProductTag(id: null, productId: $productId, tagId: (string) $tagId)
            );
        }

        // main_photo (if any) is always sort_order 0, gallery_photos
        // fill in after it — ProductMedia's own class docblock: "NO
        // is_primary field... the item at sortOrder = 0 is implicitly
        // the primary photo." These are two separate Filament fields
        // for a cleaner admin UI (this task's own WooCommerce-style
        // split), but still just ONE ordered MediaType::IMAGE
        // collection underneath — never a second, competing "is
        // primary" concept.
        $photoPaths = [];
        if (filled($data['main_photo'] ?? null)) {
            $photoPaths[] = $data['main_photo'];
        }
        foreach ($data['gallery_photos'] ?? [] as $path) {
            $photoPaths[] = $path;
        }
        $this->attachMedia($productId, $photoPaths, MediaType::IMAGE);

        // Single video per product (this task's own explicit scope) —
        // wrapped in a 0-or-1-element array so it can still go through
        // the same generalized attachMedia() as photos, rather than a
        // parallel single-item code path.
        $videoPaths = filled($data['video'] ?? null) ? [$data['video']] : [];
        $this->attachMedia($productId, $videoPaths, MediaType::VIDEO, (bool) ($data['video_autoplay'] ?? false));

        // Phase 2 — Price + Stock, keyed by the universal Variation's
        // own priceableId(), which only exists now that save() above
        // has run. Presence-based, not diff-based (there is no "current
        // value" to diff against on a brand-new product) — mirrors how
        // descriptive attributes are handled above: submit-if-present.
        // PRICE_MANAGE/COST_MANAGE re-checked here explicitly, not
        // trusted from the form's own ->disabled() state alone — see
        // ProductResource::priceStockTabComponents()'s own docblock for
        // the real, confirmed reason a merely-disabled Filament field
        // still dehydrates its submitted value.
        $priceableId = $universal->priceableId();
        $pricingAndStock = app(ProductPricingAndStock::class);

        if (ProductResource::staffHasPermission(Permission::PRICE_MANAGE)) {
            $pricingAndStock->writeRegularPrice($priceableId, $data['regular_price'] ?? null);
            $pricingAndStock->writeSalePrice($priceableId, $data['sale_price'] ?? null);
        }

        if (ProductResource::staffHasPermission(Permission::COST_MANAGE)) {
            $pricingAndStock->writeCost($priceableId, $data['cost'] ?? null);
        }

        if (filled($data['stock_quantity'] ?? null)) {
            $pricingAndStock->writeStockQuantity($priceableId, (int) $data['stock_quantity']);
        }

        app(ActivityLogger::class)->logCreated('product', $productId);

        return ProductModel::find($productId);
    }

    /**
     * Shared by Create and Edit's "newly added photo/video" branch, and
     * by every media field (main photo, gallery photos, video —
     * media-domain-design.md §2.1/§8: ProductMedia is one generic
     * pivot, not photo-specific — a video attaches through it exactly
     * like a photo, distinguished only by the underlying MediaAsset's
     * own type). Each stored path becomes a real MediaAsset (mirroring
     * ProductResource::createMediaAsset()'s BrandResource-derived
     * sequence) and a real ProductMedia pivot row, sort_order following
     * the array's own order — independently per type, so photos and the
     * video each have their own 0-based sort_order sequence rather than
     * sharing one combined ordering (there is no unique constraint on
     * sort_order itself, only on (parent_id, media_id), so this is
     * safe). $autoplay is only ever meaningful for the video call —
     * see ProductMedia's own class docblock for why passing it for
     * photos too is harmless, not a real cross-concern. Guarded
     * per-attach via the real ProductMediaCountGuard (both calls) and,
     * for the video call only, also VideoCountGuard's own separate "at
     * most one video" invariant — on a genuine limit breach from
     * either, surfaces the exact same MediaLimitExceededException
     * message the API gives, via a Filament notification, then throws
     * Halt to stop processing. The actual rollback of everything
     * already written this request (the Product, its universal
     * Variation, any already-attached media) is done by this class's
     * own DB::transaction() wrap in handleRecordCreation() — see that
     * method's docblock for why Halt's own
     * rollBackDatabaseTransaction() alone cannot be relied on here.
     */
    protected function attachMedia(string $productId, array $storedPaths, MediaType $type, bool $autoplay = false): void
    {
        $guard = app(ProductMediaCountGuard::class);
        // Only for the video call — see VideoCountGuard's own class
        // docblock for why this is a separate guard, not a branch
        // inside ProductMediaCountGuard. Nothing to reorder around here
        // unlike EditProduct::syncMedia() — a brand-new product never
        // has pre-existing pivots to worry about detaching first.
        $videoGuard = $type === MediaType::VIDEO ? app(VideoCountGuard::class) : null;
        $repository = app(ProductMediaRepository::class);

        foreach (array_values($storedPaths) as $sortOrder => $storedPath) {
            try {
                $guard->assertCanAttach($productId);
                $videoGuard?->assertCanAttach($productId);
            } catch (MediaLimitExceededException $e) {
                Notification::make()
                    ->title($e->getMessage())
                    ->danger()
                    ->send();

                throw (new Halt)->rollBackDatabaseTransaction();
            }

            $asset = ProductResource::createMediaAsset($storedPath, $type);

            $repository->save(new ProductMedia(
                id: null,
                productId: $productId,
                mediaId: $asset->id(),
                sortOrder: $sortOrder,
                autoplay: $autoplay,
            ));
        }
    }
}
