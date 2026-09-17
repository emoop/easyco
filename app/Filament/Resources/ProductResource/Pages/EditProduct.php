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
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\Exceptions\MediaLimitExceededException;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use EasyCo\Media\ProductMedia;
use EasyCo\Media\ProductMediaCountGuard;
use EasyCo\Media\VideoCountGuard;
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
     * Seeds categories/tags/descriptive_attributes/main_photo/
     * gallery_photos/video/video_autoplay with their real current state
     * — mirrors EditBrand::mutateFormDataBeforeFill()'s "FileUpload's
     * own state is always a disk path" reasoning, extended to an array
     * of paths for the gallery field.
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

        // findByProductId() returns every attached pivot regardless of
        // the underlying MediaAsset's type, ordered by sort_order asc.
        // ProductMedia's own class docblock: "NO is_primary field...
        // the item at sortOrder = 0 is implicitly the primary photo" —
        // so the FIRST image pivot encountered here (the lowest
        // sort_order) is main_photo, every image pivot after it is
        // gallery_photos. Only one video pivot is ever expected (this
        // task's own single-video-per-product scope); its own
        // autoplay() is seeded alongside it.
        $data['main_photo'] = null;
        $data['gallery_photos'] = [];
        $data['video'] = null;
        $data['video_autoplay'] = false;

        foreach (app(ProductMediaRepository::class)->findByProductId($productId) as $pivot) {
            $asset = MediaAssetModel::find($pivot->mediaId());

            if ($asset === null) {
                continue;
            }

            if ($asset->type === MediaType::IMAGE->value) {
                if ($data['main_photo'] === null) {
                    $data['main_photo'] = $asset->path;
                } else {
                    $data['gallery_photos'][] = $asset->path;
                }
            } elseif ($asset->type === MediaType::VIDEO->value) {
                $data['video'] = $asset->path;
                $data['video_autoplay'] = $pivot->autoplay();
            }
        }

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

        // main_photo always sort_order 0, gallery_photos fill in after
        // — see mutateFormDataBeforeFill()'s own comment for why (no
        // separate "is primary" concept; these are still ONE ordered
        // MediaType::IMAGE collection underneath the two Filament
        // fields).
        $photoPaths = [];
        if (filled($data['main_photo'] ?? null)) {
            $photoPaths[] = $data['main_photo'];
        }
        foreach ($data['gallery_photos'] ?? [] as $path) {
            $photoPaths[] = $path;
        }
        $this->syncMedia($product->id(), $photoPaths, MediaType::IMAGE);

        $videoPaths = filled($data['video'] ?? null) ? [$data['video']] : [];
        $this->syncMedia($product->id(), $videoPaths, MediaType::VIDEO, (bool) ($data['video_autoplay'] ?? false));

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
     * Diffs the submitted array of stored paths (main_photo +
     * gallery_photos merged into one, or the single video wrapped in a
     * 0-or-1-element array — see updateProduct()'s own comment) against
     * the currently-attached pivots of THIS SAME $type's own resolved
     * paths — scoped to $type, not every pivot the product has, since
     * findByProductId() returns photos and the video together
     * (media-domain-design.md §2.1/§8: one generic pivot, not
     * photo-specific) and a video path must never be diffed against the
     * photos submission or vice versa: a path present in both is KEPT
     * (its sort_order and/or autoplay may still need updating to match
     * the submission); a submitted path with no matching existing pivot
     * is a NEW upload (attached exactly like Create's own
     * attachMedia()); an existing pivot whose path is no longer
     * anywhere in the submission was REMOVED by the user — detached via
     * ProductMediaRepository::remove(), confirmed the real method
     * (ProductMediaController::destroy()'s own call), never touching
     * the underlying MediaAsset row itself (mirrors BrandResource's own
     * "never touch the old asset" posture). $autoplay is only ever
     * meaningful for the video call — see ProductMedia's own class
     * docblock for why passing it for photos too is harmless.
     *
     * ORPHANS ARE DETACHED BEFORE ANY NEW ATTACH IS ATTEMPTED — not
     * after, and this ordering is a real correctness requirement, not
     * cosmetic: once VideoCountGuard's own "at most one video" invariant
     * exists, replacing the single video (a new path submitted while
     * the old one is dropped) would otherwise transiently look like
     * attaching a SECOND video — the old pivot still present in the DB
     * at the moment the guard checks — and be wrongly rejected. Freeing
     * the slot first, then attaching, is also simply the more correct
     * causal order for the combined photo/video guard too, even though
     * that one has enough headroom (default 10) that the ordering bug
     * was never actually observable there.
     */
    protected function syncMedia(string $productId, array $submittedPaths, MediaType $type, bool $autoplay = false): void
    {
        $repository = app(ProductMediaRepository::class);
        $current = $repository->findByProductId($productId);

        $pivotByPath = [];
        foreach ($current as $pivot) {
            $asset = MediaAssetModel::find($pivot->mediaId());
            if ($asset !== null && $asset->type === $type->value) {
                $pivotByPath[$asset->path] = $pivot;
            }
        }

        $submittedValues = array_values($submittedPaths);

        foreach ($pivotByPath as $path => $pivot) {
            if (! in_array($path, $submittedValues, true)) {
                $repository->remove($pivot->id());
                unset($pivotByPath[$path]);
            }
        }

        $guard = app(ProductMediaCountGuard::class);
        // Only for the video call — see VideoCountGuard's own class
        // docblock for why this is a separate guard, not a branch
        // inside ProductMediaCountGuard.
        $videoGuard = $type === MediaType::VIDEO ? app(VideoCountGuard::class) : null;

        foreach ($submittedValues as $sortOrder => $path) {
            if (isset($pivotByPath[$path])) {
                $pivot = $pivotByPath[$path];
                $changed = false;

                if ($pivot->sortOrder() !== $sortOrder) {
                    $pivot->updateSortOrder($sortOrder);
                    $changed = true;
                }

                if ($pivot->autoplay() !== $autoplay) {
                    $pivot->updateAutoplay($autoplay);
                    $changed = true;
                }

                if ($changed) {
                    $repository->save($pivot);
                }

                continue;
            }

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

            $asset = ProductResource::createMediaAsset($path, $type);

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
