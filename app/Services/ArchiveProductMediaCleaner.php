<?php

namespace App\Services;

use App\Media\MediaDeleter;
use App\Media\Usage\MediaUsageRegistry;
use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Media\Contracts\ProductMediaRepository;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\MediaAsset;
use EasyCo\Media\MediaVariant;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use EasyCo\Media\ProductMedia;
use Illuminate\Support\Facades\DB;

/**
 * What archiving a product does to its media — a deliberate, scoped exception to this project's "never touch the underlying MediaAsset on detach" posture (BrandResource, the normal media
 * sync): archiving is close to a final state, not a casual mid-edit removal, and the point is real disk space reclamation. Since ML-3 a picture can be SHARED (the same asset on several
 * products or variations, a brand logo, an HTML block), so nothing is deleted just because THIS product lets it go:
 *
 *  - Every pivot except the main photo (gallery photos, the video) is DETACHED, then `MediaDeleter::delete()` is asked about the asset. It re-checks every usage provider inside its own
 *    transaction and deletes the row (its files after the commit) ONLY if nothing else uses it. This class never deletes a row or a file of such an asset itself.
 *  - The main photo (the lowest sort_order IMAGE pivot — ProductMedia's "no is_primary field, sort_order 0 is implicitly primary" convention) survives, demoted to its already-generated
 *    'thumbnail' variant — unless the asset is used anywhere else (usage registry, minus this product's own pivot): then it is left exactly as it is, full size, and nothing is deleted.
 *  - The WHOLE clean is ONE DB transaction, and every file of a demoted original is deleted in `DB::afterCommit()`: if anything fails the transaction rolls back and no file was touched.
 *
 * App/ layer, not EasyCo\Media\* — mirrors App\Services\DetachProductFromCatalogLookup's own "cross-package composition lives in app/" precedent; this is app-specific business policy
 * ("archiving cleans up media"), not a Media-package domain concern.
 */
final class ArchiveProductMediaCleaner
{
    public function __construct(
        private readonly ProductMediaRepository $productMediaRepository,
        private readonly MediaAssetRepository $mediaAssets,
        private readonly MediaStorageAdapter $storage,
        private readonly MediaDeleter $deleter,
    ) {
    }

    public function clean(string $productId): void
    {
        DB::transaction(function () use ($productId): void {
            $pivots = $this->productMediaRepository->findByProductId($productId);

            if ($pivots === []) {
                return;
            }

            // findByProductId() is already ordered by sort_order ASC (its own contract), so the FIRST IMAGE pivot encountered here is genuinely the lowest-sort_order one — the main
            // photo. A product with no IMAGE pivot at all (e.g. a video-only attachment) has no main photo to keep: $mainPhotoPivot stays null and every pivot below is released.
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

                $this->releasePivot($pivot);
            }
        });
    }

    /** Detach, then let MediaDeleter decide about the asset (it keeps it when anything else still uses it). A missing asset row just loses its pivot. */
    private function releasePivot(ProductMedia $pivot): void
    {
        $this->productMediaRepository->remove($pivot->id());
        $this->deleter->delete((int) $pivot->mediaId());
    }

    /**
     * Demotes the main photo to its already-generated 'thumbnail' variant (400px) rather than deleting it outright.
     *
     * NO NEW IMAGE PROCESSING NEEDED — the thumbnail file already exists on disk, written by the original upload's own variant generation (ProcessMediaAssetJob). The new, lightweight
     * MediaAsset is built via MediaAsset::create() (id: null, exactly like a fresh upload) but then driven straight to READY with an empty variants() array via
     * markProcessing()+markReady([]) — NOT via reconstituteFromStorage(), which is documented PERSISTENCE-LAYER ONLY. Dispatching ProcessMediaAssetJob against it would be both wasteful and
     * wrong: the file is already final, and re-running the pipeline against an already-thumbnail-sized image would generate a degraded thumbnail-of-a-thumbnail.
     *
     * ProductMedia.mediaId is readonly (no updateMediaId() mutator exists, by design, same posture as SaleLine's own immutable facts) — "swapping" the pivot is therefore a real detach + a
     * fresh attach at the SAME sort_order, not an in-place mutation.
     *
     * If the main photo has no 'thumbnail' variant yet (still PENDING/PROCESSING/FAILED — no completed processing run to demote from), it is left untouched rather than guessed at: there
     * is no file to safely demote to without real data loss. The same when it is used anywhere else (ML-3): it is not this product's to shrink.
     */
    private function demoteMainPhoto(ProductMedia $pivot): void
    {
        $asset = $this->mediaAssets->findById($pivot->mediaId());

        if ($asset === null) {
            return;
        }

        // the row is locked while it is judged, so nobody attaches it elsewhere between this check and the swap
        MediaAssetModel::query()->whereKey((int) $asset->id())->lockForUpdate()->first();

        // The registry counts this product's own pivot too (one per product and asset), hence the minus one.
        if (((new MediaUsageRegistry)->counts([(int) $asset->id()])[(int) $asset->id()] ?? 0) - 1 > 0) {
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

        // The original full-size file, plus every OTHER variant (medium/large/admin_grid/...) — never the thumbnail file itself, which the new asset above now points at. Only after the
        // whole clean COMMITTED (a rollback forgets this): until then every file is still where its row says. (DeleteMediaFilesJob is not used here: it also sweeps the
        // `{base}-{tier}.webp` files next to the original, which would take the thumbnail too.)
        $disk = $asset->disk();
        $doomed = [$asset->path()];
        foreach ($asset->variants() as $variant) {
            $variant->tier === 'thumbnail' || $doomed[] = $variant->path;
        }

        $this->mediaAssets->delete($asset->id());

        DB::afterCommit(function () use ($disk, $doomed): void {
            foreach ($doomed as $path) {
                $this->storage->delete($disk, $path);
            }
        });
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
