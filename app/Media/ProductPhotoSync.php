<?php

namespace App\Media;

use EasyCo\Media\Contracts\ProductMediaRepository;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\Exceptions\MediaLimitExceededException;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use EasyCo\Media\ProductMedia;
use EasyCo\Media\ProductMediaCountGuard;

/**
 * The photos of a product by MEDIA ID (ML-3; media-library-design.md §7, ML-D5). The product forms use the media picker, whose state is a list of ids in order — the main photo first (sort_order
 * 0), then the gallery — never a disk path, so no asset is created here: the picker's upload tab already created it. The ids are diffed against the product's current IMAGE pivots:
 *  - a pivot whose id is no longer submitted is DETACHED (`ProductMediaRepository::remove()`); the asset stays in «Медия» and only becomes «неизползвана» if nothing else uses it;
 *  - a kept pivot gets its new `sort_order`;
 *  - a new id is attached through `ProductMedia`, after `ProductMediaCountGuard::assertCanAttach()` (which counts EVERY pivot of the product, the video too; it throws
 *    MediaLimitExceededException, caught by the page, which sends the notification and halts).
 * Detaching comes BEFORE attaching, so the slots of removed photos are free for the new ones. The video pivot is never touched here (its own field and guard).
 */
final class ProductPhotoSync
{
    public function __construct(
        private readonly ProductMediaRepository $pivots,
        private readonly ProductMediaCountGuard $guard,
    ) {
    }

    /**
     * @param  list<int|string>  $mediaIds  main photo first, then the gallery
     *
     * @throws MediaLimitExceededException
     */
    public function sync(string $productId, array $mediaIds): void
    {
        $ids = array_values(array_map('intval', $mediaIds));
        $current = $this->pivots->findByProductId($productId);
        $types = $current === [] ? [] : MediaAssetModel::query()->whereIn('id', array_map(fn (ProductMedia $pivot): int => (int) $pivot->mediaId(), $current))->pluck('type', 'id')->all();

        $byMedia = [];

        foreach ($current as $pivot) {
            if (($types[(int) $pivot->mediaId()] ?? null) === MediaType::IMAGE->value) {
                $byMedia[(int) $pivot->mediaId()] = $pivot;
            }
        }

        foreach ($byMedia as $mediaId => $pivot) {
            if (! in_array($mediaId, $ids, true)) {
                $this->pivots->remove($pivot->id());
                unset($byMedia[$mediaId]);
            }
        }

        foreach ($ids as $sortOrder => $mediaId) {
            if (isset($byMedia[$mediaId])) {
                $pivot = $byMedia[$mediaId];

                if ($pivot->sortOrder() !== $sortOrder) {
                    $pivot->updateSortOrder($sortOrder);
                    $this->pivots->save($pivot);
                }

                continue;
            }

            $this->guard->assertCanAttach($productId);

            $this->pivots->save(new ProductMedia(id: null, productId: $productId, mediaId: (string) $mediaId, sortOrder: $sortOrder));
        }
    }

    /**
     * The form state of a product's photos: [main id or null, gallery ids] — the first image pivot (lowest sort_order) is the main photo, as everywhere else.
     *
     * @return array{0: int|null, 1: list<int>}
     */
    public function current(string $productId): array
    {
        $pivots = $this->pivots->findByProductId($productId);
        $types = $pivots === [] ? [] : MediaAssetModel::query()->whereIn('id', array_map(fn (ProductMedia $pivot): int => (int) $pivot->mediaId(), $pivots))->pluck('type', 'id')->all();

        $main = null;
        $gallery = [];

        foreach ($pivots as $pivot) {
            if (($types[(int) $pivot->mediaId()] ?? null) !== MediaType::IMAGE->value) {
                continue;
            }

            if ($main === null) {
                $main = (int) $pivot->mediaId();
            } else {
                $gallery[] = (int) $pivot->mediaId();
            }
        }

        return [$main, $gallery];
    }
}
