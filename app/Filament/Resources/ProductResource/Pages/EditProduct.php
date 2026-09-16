<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use EasyCo\Catalog\Contracts\ProductCategoryRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\ProductTagRepository;
use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\ProductCategory;
use EasyCo\Catalog\ProductTag;
use EasyCo\Extensibility\Hook;
use EasyCo\Media\Contracts\ProductMediaRepository;
use EasyCo\Media\Exceptions\MediaLimitExceededException;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use EasyCo\Media\ProductMedia;
use EasyCo\Media\ProductMediaCountGuard;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Write-interception per admin-panel-design.md §5, same fail-loud
 * "reload the real domain object or throw" pattern every prior EditX
 * page uses. Every mutator is only called if the value actually
 * changed; categories/tags/descriptive-attributes/media all use a
 * diff-then-save()/remove() approach against the current persisted
 * state, never a blind full replace.
 *
 * WRAPPED IN ITS OWN DB::transaction() — see CreateProduct's identical
 * wrap for why: Filament's own Halt-triggered rollback is a real no-op
 * in this Panel (databaseTransactions() was never enabled), so a
 * media-limit rejection during an edit needs this class's own explicit
 * transaction to actually undo whatever was already written this
 * request, not just stop early.
 */
class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * Seeds categories/tags/descriptive_attributes/photos with their
     * real current state — mirrors EditBrand::mutateFormDataBeforeFill()'s
     * "FileUpload's own state is always a disk path" reasoning, extended
     * to an array of paths for the multi-photo field.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $productId = (string) $this->record->id;

        $data['categories'] = array_map(
            fn ($c) => (string) $c->categoryId(),
            app(ProductCategoryRepository::class)->findByProductId($productId)
        );

        $data['tags'] = array_map(
            fn ($t) => (string) $t->tagId(),
            app(ProductTagRepository::class)->findByProductId($productId)
        );

        // findByIdWithVariations(), not findById(): universalVariation()
        // below needs the real $variations array loaded — findById()
        // alone always leaves it empty, which would make
        // universalVariation() return null unconditionally (not just
        // for a non-SIMPLE product) and crash the very seeding this
        // method exists to do.
        $product = app(ProductRepository::class)->findByIdWithVariations($productId);

        $descriptive = [];
        foreach ($product->descriptiveAttributes() as $definitionId => $value) {
            $descriptive[$definitionId] = ProductResource::normalizeCurrentDescriptiveValue($value);
        }
        $data['descriptive_attributes'] = $descriptive;

        $data['photos'] = array_map(
            fn ($pivot) => MediaAssetModel::find($pivot->mediaId())?->path,
            app(ProductMediaRepository::class)->findByProductId($productId)
        );

        // barcode/is_purchasable live on the universal Variation, not
        // ProductModel — without this, every Edit form open silently
        // fell back to the field's own ->default() regardless of the
        // real saved value (the real bug this fixes).
        $universal = $product->universalVariation();
        $data['is_purchasable'] = $universal->isPurchasable();
        $data['barcode'] = $universal->barcode();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(fn (): Model => $this->updateProduct($record, $data));
    }

    private function updateProduct(Model $record, array $data): Model
    {
        $product = app(ProductRepository::class)->findByIdWithVariations((string) $record->id);

        if ($product === null) {
            throw new RuntimeException("Product \"{$record->id}\" could not be reloaded from the domain layer.");
        }

        if ($product->name() !== $data['name']) {
            $product->rename($data['name']);
        }

        if ($product->slug() !== $data['slug']) {
            $product->changeSlug($data['slug']);
        }

        if ($product->baseSku() !== $data['base_sku']) {
            $product->changeBaseSku($data['base_sku']);
        }

        $newDescription = filled($data['description'] ?? null) ? $data['description'] : null;
        if ($product->description() !== $newDescription) {
            $product->changeDescription($newDescription);
        }

        $newStatus = $data['status'] ?? ProductStatus::DRAFT->value;
        if ($product->status()->value !== $newStatus) {
            match ($newStatus) {
                ProductStatus::ACTIVE->value => $product->publish(),
                ProductStatus::ARCHIVED->value => $product->archive(),
                default => $product->markAsDraft(),
            };
        }

        $newVisibility = CatalogVisibility::from($data['catalog_visibility'] ?? CatalogVisibility::HIDDEN->value);
        if ($product->catalogVisibility() !== $newVisibility) {
            $product->setCatalogVisibility($newVisibility);
        }

        $newBrandId = $data['brand_id'] ?? null;
        if ($product->brandId() !== $newBrandId) {
            $product->assignBrand($newBrandId);
        }

        $newSeasonId = $data['season_id'] ?? null;
        if ($product->seasonId() !== $newSeasonId) {
            $product->assignSeason($newSeasonId);
        }

        $newProductGroupId = $data['product_group_id'] ?? null;
        if ($product->productGroupId() !== $newProductGroupId) {
            $product->assignProductGroup($newProductGroupId);
        }

        $universal = $product->universalVariation();

        $newBarcode = filled($data['barcode'] ?? null) ? $data['barcode'] : null;
        if ($universal->barcode() !== $newBarcode) {
            $barcode = Hook::apply('catalog.variation.barcode', $newBarcode ?? '', $universal);
            $universal->setBarcode($barcode !== '' ? $barcode : null);
        }

        $newIsPurchasable = (bool) ($data['is_purchasable'] ?? true);
        if ($universal->isPurchasable() !== $newIsPurchasable) {
            $universal->setPurchasable($newIsPurchasable);
        }

        foreach (ProductResource::descriptiveAttributeDefinitions() as $definitionModel) {
            $definitionId = (string) $definitionModel->id;
            $currentRaw = $product->descriptiveAttributes()[$definitionId] ?? null;
            $currentNormalized = ProductResource::normalizeCurrentDescriptiveValue($currentRaw);

            $submittedRaw = $data['descriptive_attributes'][$definitionId] ?? null;
            $submittedNormalized = ProductResource::normalizeSubmittedDescriptiveValue($definitionModel, $submittedRaw);

            if ($submittedNormalized === $currentNormalized) {
                continue;
            }

            $definition = ProductResource::toDomainAttributeDefinition($definitionModel);

            if ($submittedNormalized === null) {
                $product->removeDescriptiveAttribute($definition);

                continue;
            }

            ProductResource::applyDescriptiveAttribute($product, $definitionModel, $submittedRaw);
        }

        app(ProductRepository::class)->save($product);

        $this->syncCategories($product->id(), $data['categories'] ?? []);
        $this->syncTags($product->id(), $data['tags'] ?? []);
        $this->syncPhotos($product->id(), $data['photos'] ?? []);

        return ProductModel::find($product->id());
    }

    protected function syncCategories(string $productId, array $submittedCategoryIds): void
    {
        $repository = app(ProductCategoryRepository::class);
        $current = $repository->findByProductId($productId);

        $submitted = array_map('strval', $submittedCategoryIds);
        $currentByCategoryId = [];
        foreach ($current as $pivot) {
            $currentByCategoryId[$pivot->categoryId()] = $pivot;
        }

        foreach ($submitted as $categoryId) {
            if (! isset($currentByCategoryId[$categoryId])) {
                $repository->save(new ProductCategory(id: null, productId: $productId, categoryId: $categoryId));
            }
        }

        foreach ($currentByCategoryId as $categoryId => $pivot) {
            if (! in_array($categoryId, $submitted, true)) {
                $repository->remove($pivot->id());
            }
        }
    }

    protected function syncTags(string $productId, array $submittedTagIds): void
    {
        $repository = app(ProductTagRepository::class);
        $current = $repository->findByProductId($productId);

        $submitted = array_map('strval', $submittedTagIds);
        $currentByTagId = [];
        foreach ($current as $pivot) {
            $currentByTagId[$pivot->tagId()] = $pivot;
        }

        foreach ($submitted as $tagId) {
            if (! isset($currentByTagId[$tagId])) {
                $repository->save(new ProductTag(id: null, productId: $productId, tagId: $tagId));
            }
        }

        foreach ($currentByTagId as $tagId => $pivot) {
            if (! in_array($tagId, $submitted, true)) {
                $repository->remove($pivot->id());
            }
        }
    }

    /**
     * Diffs the submitted `photos` array of stored paths against the
     * currently-attached pivots' own resolved paths: a path present in
     * both is a KEPT photo (only its sort_order may need updating to
     * match the submitted order); a submitted path with no matching
     * existing pivot is a NEW upload (attached exactly like Create's
     * own attachPhotos()); an existing pivot whose path is no longer
     * anywhere in the submission was REMOVED by the user — detached via
     * ProductMediaRepository::remove(), confirmed the real method
     * (ProductMediaController::destroy()'s own call), never touching
     * the underlying MediaAsset row itself (mirrors BrandResource's own
     * "never touch the old asset" posture).
     */
    protected function syncPhotos(string $productId, array $submittedPaths): void
    {
        $repository = app(ProductMediaRepository::class);
        $current = $repository->findByProductId($productId);

        $pivotByPath = [];
        foreach ($current as $pivot) {
            $path = MediaAssetModel::find($pivot->mediaId())?->path;
            if ($path !== null) {
                $pivotByPath[$path] = $pivot;
            }
        }

        $guard = app(ProductMediaCountGuard::class);
        $keptPaths = [];

        foreach (array_values($submittedPaths) as $sortOrder => $path) {
            if (isset($pivotByPath[$path])) {
                $pivot = $pivotByPath[$path];
                $keptPaths[] = $path;

                if ($pivot->sortOrder() !== $sortOrder) {
                    $pivot->updateSortOrder($sortOrder);
                    $repository->save($pivot);
                }

                continue;
            }

            try {
                $guard->assertCanAttach($productId);
            } catch (MediaLimitExceededException $e) {
                Notification::make()
                    ->title($e->getMessage())
                    ->danger()
                    ->send();

                throw (new Halt)->rollBackDatabaseTransaction();
            }

            $asset = ProductResource::createMediaAsset($path);

            $repository->save(new ProductMedia(
                id: null,
                productId: $productId,
                mediaId: $asset->id(),
                sortOrder: $sortOrder,
            ));

            $keptPaths[] = $path;
        }

        foreach ($pivotByPath as $path => $pivot) {
            if (! in_array($path, $keptPaths, true)) {
                $repository->remove($pivot->id());
            }
        }
    }
}
