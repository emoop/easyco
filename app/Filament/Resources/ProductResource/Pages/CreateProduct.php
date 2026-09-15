<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
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
use EasyCo\Media\Exceptions\MediaLimitExceededException;
use EasyCo\Media\ProductMedia;
use EasyCo\Media\ProductMediaCountGuard;
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

        foreach (ProductResource::descriptiveAttributeDefinitions() as $definitionModel) {
            $rawValue = $data['descriptive_attributes'][$definitionModel->id] ?? null;
            $normalized = ProductResource::normalizeSubmittedDescriptiveValue($definitionModel, $rawValue);

            if ($normalized === null) {
                continue;
            }

            ProductResource::applyDescriptiveAttribute($product, $definitionModel, $rawValue);
        }

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

        $this->attachPhotos($productId, $data['photos'] ?? []);

        return ProductModel::find($productId);
    }

    /**
     * Shared by Create and Edit's "newly added photo" branch. Each
     * stored path becomes a real MediaAsset (mirroring
     * ProductResource::createMediaAsset()'s BrandResource-derived
     * sequence) and a real ProductMedia pivot row, sort_order following
     * the array's own order (the FileUpload field's ->reorderable()
     * state). Guarded per-attach via the real ProductMediaCountGuard —
     * on a genuine limit breach, surfaces the exact same
     * MediaLimitExceededException message the API gives, via a Filament
     * notification, then throws Halt to stop processing. The actual
     * rollback of everything already written this request (the Product,
     * its universal Variation, any already-attached photos) is done by
     * this class's own DB::transaction() wrap in handleRecordCreation()
     * — see that method's docblock for why Halt's own
     * rollBackDatabaseTransaction() alone cannot be relied on here.
     */
    protected function attachPhotos(string $productId, array $storedPaths): void
    {
        $guard = app(ProductMediaCountGuard::class);
        $repository = app(ProductMediaRepository::class);

        foreach (array_values($storedPaths) as $sortOrder => $storedPath) {
            try {
                $guard->assertCanAttach($productId);
            } catch (MediaLimitExceededException $e) {
                Notification::make()
                    ->title($e->getMessage())
                    ->danger()
                    ->send();

                throw (new Halt)->rollBackDatabaseTransaction();
            }

            $asset = ProductResource::createMediaAsset($storedPath);

            $repository->save(new ProductMedia(
                id: null,
                productId: $productId,
                mediaId: $asset->id(),
                sortOrder: $sortOrder,
            ));
        }
    }
}
