<?php

namespace App\Media;

use EasyCo\Media\Contracts\ProductMediaRepository;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\Exceptions\MediaLimitExceededException;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use EasyCo\Media\ProductMedia;
use EasyCo\Media\ProductMediaCountGuard;
use EasyCo\Media\VideoCountGuard;

/**
 * The single video of a product by MEDIA ID (ML-3 §3), next to ProductPhotoSync. The video shares `catalog_product_media` with the photos (media-domain-design.md §2.1 / §8) and is told apart by
 * the asset's type; its `autoplay` is stored on the pivot (ProductMedia.autoplay). A video no longer submitted is DETACHED (the asset stays in «Медия»); the same one only gets its autoplay
 * updated; a new one goes through VideoCountGuard (at most one) and ProductMediaCountGuard (every pivot counts). The old pivot is detached BEFORE the new one is attached, so replacing the
 * video is not mistaken for adding a second.
 */
final class ProductVideoSync
{
    public function __construct(
        private readonly ProductMediaRepository $pivots,
        private readonly ProductMediaCountGuard $countGuard,
        private readonly VideoCountGuard $videoGuard,
    ) {
    }

    /** @throws MediaLimitExceededException */
    public function sync(string $productId, int|string|null $videoId, bool $autoplay): void
    {
        $videoId = filled($videoId) ? (int) $videoId : null;
        $current = $this->pivots->findByProductId($productId);
        $types = $current === [] ? [] : MediaAssetModel::query()->whereIn('id', array_map(fn (ProductMedia $pivot): int => (int) $pivot->mediaId(), $current))->pluck('type', 'id')->all();
        $kept = null;

        foreach ($current as $pivot) {
            if (($types[(int) $pivot->mediaId()] ?? null) !== MediaType::VIDEO->value) {
                continue;
            }

            if ($videoId !== null && (int) $pivot->mediaId() === $videoId) {
                $kept = $pivot;

                continue;
            }

            $this->pivots->remove($pivot->id());
        }

        if ($videoId === null) {
            return;
        }

        if ($kept !== null) {
            if ($kept->autoplay() !== $autoplay) {
                $kept->updateAutoplay($autoplay);
                $this->pivots->save($kept);
            }

            return;
        }

        $this->countGuard->assertCanAttach($productId);
        $this->videoGuard->assertCanAttach($productId);

        $this->pivots->save(new ProductMedia(id: null, productId: $productId, mediaId: (string) $videoId, sortOrder: 0, autoplay: $autoplay));
    }

    /** @return array{0: int|null, 1: bool} the video's media id and its autoplay */
    public function current(string $productId): array
    {
        $pivots = $this->pivots->findByProductId($productId);
        $types = $pivots === [] ? [] : MediaAssetModel::query()->whereIn('id', array_map(fn (ProductMedia $pivot): int => (int) $pivot->mediaId(), $pivots))->pluck('type', 'id')->all();

        foreach ($pivots as $pivot) {
            if (($types[(int) $pivot->mediaId()] ?? null) === MediaType::VIDEO->value) {
                return [(int) $pivot->mediaId(), $pivot->autoplay()];
            }
        }

        return [null, false];
    }
}
