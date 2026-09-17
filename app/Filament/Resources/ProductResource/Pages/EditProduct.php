<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Services\ActivityLogger;
use App\Services\ArchiveProductMediaCleaner;
use App\Services\ProductPricingAndStock;
use EasyCo\Catalog\Contracts\ProductCategoryRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\ProductTagRepository;
use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Persistence\Eloquent\CategoryModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Persistence\Eloquent\TagModel;
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
use EasyCo\Staff\Enums\Permission;
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

        // Phase 2 — Price + Stock: regular_price/sale_price/cost read as
        // display decimal strings (null when unset — a missing
        // PriceListItem/ProductCost is a real, legitimate "not priced
        // yet" state, not zero). stockQuantity() never returns null
        // (StockLevelRepository::findByVariationId()'s own contract).
        $pricingAndStock = app(ProductPricingAndStock::class);
        $priceableId = $universal->priceableId();
        $data['regular_price'] = $pricingAndStock->regularPriceDisplay($priceableId);
        $data['sale_price'] = $pricingAndStock->salePriceDisplay($priceableId);
        $data['cost'] = $pricingAndStock->costDisplay($priceableId);
        $data['stock_quantity'] = $pricingAndStock->stockQuantity($priceableId);

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

        $logger = app(ActivityLogger::class);

        if ($product->name() !== $data['name']) {
            $logger->logFieldChanged('product', $product->id(), 'name', $product->name(), $data['name']);
            $product->rename($data['name']);
        }

        if ($product->slug() !== $data['slug']) {
            $logger->logFieldChanged('product', $product->id(), 'slug', $product->slug(), $data['slug']);
            $product->changeSlug($data['slug']);
        }

        if ($product->baseSku() !== $data['base_sku']) {
            $logger->logFieldChanged('product', $product->id(), 'base_sku', $product->baseSku(), $data['base_sku']);
            $product->changeBaseSku($data['base_sku']);
        }

        $newDescription = filled($data['description'] ?? null) ? $data['description'] : null;
        if ($product->description() !== $newDescription) {
            $logger->logFieldChanged('product', $product->id(), 'description', $product->description(), $newDescription);
            $product->changeDescription($newDescription);
        }

        $newStatus = $data['status'] ?? ProductStatus::DRAFT->value;
        $oldStatus = $product->status()->value;
        // Detected here (this "if changed" block already guarantees
        // $oldStatus !== $newStatus when true; re-saving an
        // already-archived product never re-enters this block at all,
        // so this is naturally a one-time, no-op-on-repeat condition
        // without any extra guard needed) — but NOT ACTED ON until
        // after syncMedia() runs, near the end of this method. Acting
        // on it here, immediately, would be a real ordering bug: the
        // submitted $data['main_photo']/gallery_photos/video values
        // seeded by mutateFormDataBeforeFill() still reflect the OLD,
        // pre-cleanup media state (the admin didn't touch those fields
        // this request), so syncMedia() running AFTER an early cleanup
        // would see its own freshly-deleted pivots as "missing" and the
        // stale submitted paths as "new uploads" — re-creating
        // MediaAsset rows that point at files cleanup just deleted from
        // disk. See ArchiveProductMediaCleaner's own docblock for what
        // "cleanup" really does — real deletion, not detach-only,
        // confirmed by the domain owner.
        $shouldCleanArchivedMedia = $oldStatus !== $newStatus && $newStatus === ProductStatus::ARCHIVED->value;

        if ($oldStatus !== $newStatus) {
            $logger->logFieldChanged('product', $product->id(), 'status', $oldStatus, $newStatus);
            match ($newStatus) {
                ProductStatus::ACTIVE->value => $product->publish(),
                ProductStatus::ARCHIVED->value => $product->archive(),
                default => $product->markAsDraft(),
            };
        }

        $newVisibility = CatalogVisibility::from($data['catalog_visibility'] ?? CatalogVisibility::HIDDEN->value);
        if ($product->catalogVisibility() !== $newVisibility) {
            $logger->logFieldChanged('product', $product->id(), 'catalog_visibility', $product->catalogVisibility()->value, $newVisibility->value);
            $product->setCatalogVisibility($newVisibility);
        }

        $newBrandId = $data['brand_id'] ?? null;
        if ($product->brandId() !== $newBrandId) {
            $logger->logFieldChanged('product', $product->id(), 'brand_id', $product->brandId(), $newBrandId);
            $product->assignBrand($newBrandId);
        }

        $newSeasonId = $data['season_id'] ?? null;
        if ($product->seasonId() !== $newSeasonId) {
            $logger->logFieldChanged('product', $product->id(), 'season_id', $product->seasonId(), $newSeasonId);
            $product->assignSeason($newSeasonId);
        }

        $newProductGroupId = $data['product_group_id'] ?? null;
        if ($product->productGroupId() !== $newProductGroupId) {
            $logger->logFieldChanged('product', $product->id(), 'product_group_id', $product->productGroupId(), $newProductGroupId);
            $product->assignProductGroup($newProductGroupId);
        }

        $universal = $product->universalVariation();

        $newBarcode = filled($data['barcode'] ?? null) ? $data['barcode'] : null;
        if ($universal->barcode() !== $newBarcode) {
            $logger->logFieldChanged('product', $product->id(), 'barcode', $universal->barcode(), $newBarcode);
            $barcode = Hook::apply('catalog.variation.barcode', $newBarcode ?? '', $universal);
            $universal->setBarcode($barcode !== '' ? $barcode : null);
        }

        $newIsPurchasable = (bool) ($data['is_purchasable'] ?? true);
        if ($universal->isPurchasable() !== $newIsPurchasable) {
            $logger->logFieldChanged('product', $product->id(), 'is_purchasable', $universal->isPurchasable() ? '1' : '0', $newIsPurchasable ? '1' : '0');
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

            $logger->logFieldChanged('product', $product->id(), $definitionModel->code, $currentNormalized, $submittedNormalized);

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

        // ONLY after the normal media sync above has already reconciled
        // the submitted (unchanged) media fields against the DB — see
        // $shouldCleanArchivedMedia's own comment for why running this
        // any earlier would be a real ordering bug.
        if ($shouldCleanArchivedMedia) {
            app(ArchiveProductMediaCleaner::class)->clean($product->id());
        }

        $this->updatePricingAndStock($universal->priceableId(), $data, $logger, $product->id());

        return ProductModel::find($product->id());
    }

    /**
     * Diff-then-write, matching every other field in this class's own
     * established pattern — but gated FIRST by PRICE_MANAGE/COST_MANAGE,
     * independent of whatever the submitted $data actually contains: a
     * REAL, CONFIRMED GAP found while building this (see
     * ProductResource::priceStockTabComponents()'s own docblock) means a
     * merely-disabled field's value still dehydrates into $data on
     * submit — so a value present in $data from a non-PRICE_MANAGE/
     * COST_MANAGE staff member (the field was disabled, never hidden)
     * must never be trusted, let alone diffed and written. Checking the
     * permission BEFORE even reading $data['regular_price'] etc. is
     * what actually enforces this, not the diff itself.
     *
     * Stock has no dedicated inventory permission (PRODUCT_MANAGE is
     * the closest fit — see priceStockTabComponents()'s own docblock);
     * PRODUCT_MANAGE is already this whole page's base edit permission,
     * so in practice this branch is always taken whenever this method
     * runs at all — checked explicitly anyway, not assumed.
     */
    private function updatePricingAndStock(string $priceableId, array $data, ActivityLogger $logger, string $productId): void
    {
        $pricingAndStock = app(ProductPricingAndStock::class);

        if (ProductResource::staffHasPermission(Permission::PRICE_MANAGE)) {
            $currentRegularPrice = $pricingAndStock->regularPriceDisplay($priceableId);
            // normalizeDecimalDisplay(), not a raw (string) cast — a
            // REAL, CONFIRMED GAP found while testing: TextInput::
            // numeric() registers a NumberStateCast on the field
            // (installed v5.8.1 source), which reformats a submitted
            // "80.00" down to "80" before it ever reaches $data —
            // comparing that raw value directly against
            // regularPriceDisplay()'s own fixed "80.00" format would
            // treat an UNCHANGED price as changed. See
            // ProductPricingAndStock::normalizeDecimalDisplay()'s own
            // docblock.
            $newRegularPrice = $pricingAndStock->normalizeDecimalDisplay($data['regular_price'] ?? null);
            if ($currentRegularPrice !== $newRegularPrice) {
                $logger->logFieldChanged('product', $productId, 'regular_price', $currentRegularPrice, $newRegularPrice);
                $pricingAndStock->writeRegularPrice($priceableId, $newRegularPrice);
            }

            $currentSalePrice = $pricingAndStock->salePriceDisplay($priceableId);
            $newSalePrice = $pricingAndStock->normalizeDecimalDisplay($data['sale_price'] ?? null);
            if ($currentSalePrice !== $newSalePrice) {
                $logger->logFieldChanged('product', $productId, 'sale_price', $currentSalePrice, $newSalePrice);
                $pricingAndStock->writeSalePrice($priceableId, $newSalePrice);
            }
        }

        if (ProductResource::staffHasPermission(Permission::COST_MANAGE)) {
            $currentCost = $pricingAndStock->costDisplay($priceableId);
            $newCost = $pricingAndStock->normalizeDecimalDisplay($data['cost'] ?? null);

            // writeCost() itself is a no-op on a blank value (see
            // ProductPricingAndStock's own docblock — ProductCostRepository
            // has no removal path), so the diff-guard here only needs to
            // cover the "genuinely different, non-blank" case.
            if ($newCost !== null && $currentCost !== $newCost) {
                $logger->logFieldChanged('product', $productId, 'cost', $currentCost, $newCost);
                $pricingAndStock->writeCost($priceableId, $newCost);
            }
        }

        if (ProductResource::staffHasPermission(Permission::PRODUCT_MANAGE)) {
            $currentStock = $pricingAndStock->stockQuantity($priceableId);
            $newStock = (int) ($data['stock_quantity'] ?? 0);
            if ($currentStock !== $newStock) {
                $logger->logFieldChanged('product', $productId, 'stock_quantity', (string) $currentStock, (string) $newStock);
                $pricingAndStock->writeStockQuantity($priceableId, $newStock);
            }
        }
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

                $categoryName = CategoryModel::find($categoryId)?->name;
                app(ActivityLogger::class)->logFieldChanged('product', $productId, 'categories', null, $categoryName);
            }
        }

        foreach ($currentByCategoryId as $categoryId => $pivot) {
            // A REAL, PRE-EXISTING BUG FOUND WHILE ADDING THIS TASK'S OWN
            // LOGGING (not introduced by it — this diffing logic itself
            // is otherwise unchanged): PHP always silently casts a
            // numeric-string array key (categoryId() returns e.g. '1')
            // to an int — this happens at the array itself, so casting
            // the value BEFORE using it as a key (further up) cannot
            // prevent it. $categoryId here therefore comes back as an
            // INT, and the strict in_array($categoryId, $submitted, true)
            // below — $submitted is always strings, via
            // array_map('strval', ...) above — never matched, so an
            // already-attached category was silently detached on every
            // edit that also added a DIFFERENT one. Casting back to
            // string HERE, on read, is the actual fix.
            $categoryId = (string) $categoryId;

            if (! in_array($categoryId, $submitted, true)) {
                $repository->remove($pivot->id());

                $categoryName = CategoryModel::find($categoryId)?->name;
                app(ActivityLogger::class)->logFieldChanged('product', $productId, 'categories', $categoryName, null);
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

                $tagName = TagModel::find($tagId)?->name;
                app(ActivityLogger::class)->logFieldChanged('product', $productId, 'tags', null, $tagName);
            }
        }

        foreach ($currentByTagId as $tagId => $pivot) {
            // Same real, pre-existing bug as syncCategories() above, same
            // fix — see that method's own inline note for the full
            // explanation.
            $tagId = (string) $tagId;

            if (! in_array($tagId, $submitted, true)) {
                $repository->remove($pivot->id());

                $tagName = TagModel::find($tagId)?->name;
                app(ActivityLogger::class)->logFieldChanged('product', $productId, 'tags', $tagName, null);
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
