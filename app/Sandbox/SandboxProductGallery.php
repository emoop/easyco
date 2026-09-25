<?php

namespace App\Sandbox;

use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Media\Contracts\ProductMediaRepository;
use EasyCo\Media\Contracts\VariationMediaRepository;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\MediaAsset;
use EasyCo\Media\ProductMedia;
use EasyCo\Media\VariationMedia;

/**
 * Assembles the sandbox product page's image list — D5: "images (product
 * media, then variation media if present)".
 *
 * THE ORDER IS LITERAL AND MATTERS: every product-level attachment
 * first (in the pivot's own sort_order, the order the merchant arranged
 * them in — ProductMediaRepository::findByProductId() is contractually
 * ordered that way), then the attachments of the given variations. The
 * variations are passed in already filtered to D3's listed set, so a
 * hidden/draft variation's photos can never appear on a page a customer
 * is looking at.
 *
 * ONE ASSET, ONE TILE: the same MediaAsset attached both to the product
 * and to a variation is rendered once (the first occurrence wins). Real
 * merchants do attach the same photo to both levels, and showing it
 * twice would look like a bug to the person doing the preview.
 *
 * WHAT IS SKIPPED, AND WHY (fail-soft, D8): a pivot pointing at a
 * missing MediaAsset (a deleted asset whose pivot survived — the Media
 * cleanup work in media-cleanup-and-storage-optimization-note.md exists
 * precisely because that state is real), a non-IMAGE asset (videos are
 * NOT rendered in stage 1 — D5 asks for images, and D8's own scope has
 * no player in it), and an asset that is not READY (a pending/failed
 * asset has no usable file yet). None of these is an error: the page
 * renders the images it can.
 *
 * THE URL comes from MediaStorageAdapter::url() — the Media domain's own
 * existing infrastructure boundary — never from a second, sandbox-local
 * Storage:: call, and it uses each asset's OWN disk() (the disk it was
 * actually written to, which is what MediaAsset::disk() exists for)
 * rather than assuming the configured default.
 */
final class SandboxProductGallery
{
    public function __construct(
        private readonly ProductMediaRepository $productMedia,
        private readonly VariationMediaRepository $variationMedia,
        private readonly MediaAssetRepository $mediaAssets,
        private readonly MediaStorageAdapter $storage,
    ) {
    }

    /**
     * @param string[] $listedVariationIds D3's own listed set, in the order the page shows them
     * @return array<int, SandboxImageView>
     */
    public function forProduct(string $productId, array $listedVariationIds): array
    {
        $mediaIds = [];

        foreach ($this->productMedia->findByProductId($productId) as $pivot) {
            $mediaIds[] = $pivot->mediaId();
        }

        foreach ($listedVariationIds as $variationId) {
            foreach ($this->variationMedia->findByVariationId($variationId) as $pivot) {
                $mediaIds[] = $pivot->mediaId();
            }
        }

        $images = [];
        $seenMediaIds = [];

        foreach ($mediaIds as $mediaId) {
            if (isset($seenMediaIds[$mediaId])) {
                continue;
            }

            $asset = $this->mediaAssets->findById($mediaId);

            if ($asset === null || $asset->type() !== MediaType::IMAGE || ! $asset->isReady()) {
                continue;
            }

            $seenMediaIds[$mediaId] = true;
            $images[] = new SandboxImageView(
                url: $this->storage->url($asset->disk(), $asset->path()),
                altText: $asset->altText(),
            );
        }

        return $images;
    }
}
