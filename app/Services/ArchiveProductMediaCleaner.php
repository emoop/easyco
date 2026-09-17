<?php

namespace App\Services;

use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Media\Contracts\ProductMediaRepository;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\MediaAsset;
use EasyCo\Media\MediaVariant;
use EasyCo\Media\ProductMedia;

/**
 * Real photo/video deletion when a product transitions INTO archived —
 * a deliberate, scoped exception to this project's established "never
 * touch the underlying MediaAsset on detach" posture (BrandResource,
 * EditProduct's own normal media sync via syncMedia()). Confirmed by
 * the domain owner: archiving is close to a final state, not a casual
 * mid-edit removal, and the whole point of this feature is real disk
 * space reclamation — a detach-only cleanup (leaving orphaned
 * MediaAsset rows and files behind forever) would not achieve that.
 *
 * App/ layer, not EasyCo\Media\* — mirrors App\Services\
 * DetachProductFromCatalogLookup's own "cross-package composition
 * lives in app/" precedent; this is app-specific business policy
 * ("archiving cleans up media"), not a Media-package domain concern.
 *
 * ONLY the main photo (lowest sort_order IMAGE pivot — the same
 * "no is_primary field, sort_order 0 is implicit" convention already
 * established by ProductMedia's own class docblock) survives, demoted
 * to its already-generated 'thumbnail' variant file — see
 * demoteMainPhoto()'s own docblock for why no new image processing is
 * needed. Every other pivot (gallery photos, the video) is detached
 * AND its MediaAsset row AND every one of its real files on disk
 * (original + every variant) are deleted.
 */
final class ArchiveProductMediaCleaner
{
    public function __construct(
        private readonly ProductMediaRepository $productMediaRepository,
        private readonly MediaAssetRepository $mediaAssets,
        private readonly MediaStorageAdapter $storage,
    ) {
    }

    public function clean(string $productId): void
    {
        $pivots = $this->productMediaRepository->findByProductId($productId);

        if ($pivots === []) {
            return;
        }

        // findByProductId() is already ordered by sort_order ASC (its
        // own contract), so the FIRST IMAGE pivot encountered here is
        // genuinely the lowest-sort_order one — the main photo. A
        // product with no IMAGE pivot at all (e.g. a video-only
        // attachment, an edge case nothing in this codebase currently
        // produces but not impossible) has no main photo to keep:
        // $mainPhotoPivot stays null and every pivot below is deleted.
        $mainPhotoPivot = null;
        foreach ($pivots as $pivot) {
            $asset = $this->mediaAssets->findById($pivot->mediaId());

            if ($asset !== null && $asset->type() === MediaType::IMAGE) {
                $mainPhotoPivot = $pivot;

                break;
            }
        }

        foreach ($pivots as $pivot) {
            if ($mainPhotoPivot !== null && $pivot->id() === $mainPhotoPivot->id()) {
                $this->demoteMainPhoto($pivot);

                continue;
            }

            $this->deletePivotAndAsset($pivot);
        }
    }

    /**
     * Real deletion, not detach-only — see this class's own docblock.
     * A missing MediaAsset row (data-integrity edge case; the pivot's
     * media_id FK guarantees it exists, so this should never actually
     * happen) is tolerated: the orphaned pivot is still removed, there
     * are simply no files to clean up.
     */
    private function deletePivotAndAsset(ProductMedia $pivot): void
    {
        $asset = $this->mediaAssets->findById($pivot->mediaId());

        $this->productMediaRepository->remove($pivot->id());

        if ($asset === null) {
            return;
        }

        $this->deleteAssetFiles($asset);
        $this->mediaAssets->delete($asset->id());
    }

    /**
     * Demotes the main photo to its already-generated 'thumbnail'
     * variant (400px) rather than deleting it outright.
     *
     * NO NEW IMAGE PROCESSING NEEDED — the thumbnail file already
     * exists on disk, written by the original upload's own variant
     * generation (ProcessMediaAssetJob). The new, lightweight
     * MediaAsset is built via MediaAsset::create() (id: null, exactly
     * like a fresh upload) but then driven straight to READY with an
     * empty variants() array via markProcessing()+markReady([]) —
     * NOT via reconstituteFromStorage(), which is documented
     * PERSISTENCE-LAYER ONLY and, more fundamentally, cannot even
     * apply here: it requires an already-real, non-null $id, but this
     * is a genuinely NEW row needing exactly the same id-assignment
     * dance as any other fresh MediaAsset. create()+markProcessing()+
     * markReady([]) is the real, correct mechanism using only this
     * class's already-public API — dispatching ProcessMediaAssetJob
     * against it would be both wasteful and wrong: the file is already
     * final, and re-running the pipeline against an already-thumbnail-
     * sized image would generate a degraded thumbnail-of-a-thumbnail.
     *
     * ProductMedia.mediaId is readonly (no updateMediaId() mutator
     * exists, by design, same posture as SaleLine's own immutable
     * facts) — "swapping" the pivot is therefore a real detach + a
     * fresh attach at the SAME sort_order, not an in-place mutation.
     *
     * If the main photo has no 'thumbnail' variant yet (still PENDING/
     * PROCESSING/FAILED — no completed processing run to demote from),
     * it is left untouched rather than guessed at: there is no file to
     * safely demote to without real data loss.
     */
    private function demoteMainPhoto(ProductMedia $pivot): void
    {
        $asset = $this->mediaAssets->findById($pivot->mediaId());

        if ($asset === null) {
            return;
        }

        $thumbnail = $this->findVariant($asset, 'thumbnail');

        if ($thumbnail === null) {
            return;
        }

        $newAsset = MediaAsset::create(MediaType::IMAGE, $asset->disk(), $thumbnail->path);
        $newAsset->markProcessing();
        $newAsset->markReady([]);
        $this->mediaAssets->save($newAsset);

        $this->productMediaRepository->remove($pivot->id());
        $this->productMediaRepository->save(new ProductMedia(
            id: null,
            productId: $pivot->productId(),
            mediaId: $newAsset->id(),
            sortOrder: $pivot->sortOrder(),
            autoplay: $pivot->autoplay(),
        ));

        // The original full-size file, plus every OTHER variant
        // (medium/large/admin_grid/...) — never the thumbnail file
        // itself, which the new asset above now points at.
        $this->storage->delete($asset->disk(), $asset->path());
        foreach ($asset->variants() as $variant) {
            if ($variant->tier === 'thumbnail') {
                continue;
            }

            $this->storage->delete($asset->disk(), $variant->path);
        }

        $this->mediaAssets->delete($asset->id());
    }

    private function deleteAssetFiles(MediaAsset $asset): void
    {
        $this->storage->delete($asset->disk(), $asset->path());

        foreach ($asset->variants() as $variant) {
            $this->storage->delete($asset->disk(), $variant->path);
        }
    }

    private function findVariant(MediaAsset $asset, string $tier): ?MediaVariant
    {
        foreach ($asset->variants() as $variant) {
            if ($variant->tier === $tier) {
                return $variant;
            }
        }

        return null;
    }
}
