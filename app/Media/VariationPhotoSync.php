<?php

namespace App\Media;

use EasyCo\Media\Contracts\VariationMediaRepository;
use EasyCo\Media\Exceptions\MediaLimitExceededException;
use EasyCo\Media\VariationMedia;
use EasyCo\Media\VariationMediaCountGuard;

/**
 * The photos of ONE variation by MEDIA ID (ML-3 §2) — the per-variation counterpart of ProductPhotoSync: the same id diff against `catalog_variation_media`. A pivot no longer submitted is
 * DETACHED (the asset stays in «Медия»), a kept one gets its new `sort_order`, a new id is attached after `VariationMediaCountGuard::assertCanAttach()` (which throws
 * MediaLimitExceededException; the page turns it into a notification + Halt). Detaching comes first, so freed slots are usable. A variation has photos only: no type, no autoplay.
 */
final class VariationPhotoSync
{
    public function __construct(
        private readonly VariationMediaRepository $pivots,
        private readonly VariationMediaCountGuard $guard,
    ) {
    }

    /**
     * @param  list<int|string>  $mediaIds  in order
     *
     * @throws MediaLimitExceededException
     */
    public function sync(string $variationId, array $mediaIds): void
    {
        $ids = array_values(array_map('intval', $mediaIds));
        $byMedia = [];

        foreach ($this->pivots->findByVariationId($variationId) as $pivot) {
            $byMedia[(int) $pivot->mediaId()] = $pivot;
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

            $this->guard->assertCanAttach($variationId);

            $this->pivots->save(new VariationMedia(id: null, variationId: $variationId, mediaId: (string) $mediaId, sortOrder: $sortOrder));
        }
    }

    /** @return list<int> the media ids of a variation's photos, in order */
    public function current(string $variationId): array
    {
        return array_values(array_map(fn (VariationMedia $pivot): int => (int) $pivot->mediaId(), $this->pivots->findByVariationId($variationId)));
    }
}
