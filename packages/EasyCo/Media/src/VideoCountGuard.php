<?php

namespace EasyCo\Media;

use EasyCo\Media\Contracts\ProductMediaRepository;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\Exceptions\MediaLimitExceededException;

/**
 * Write-time check for the "a product may have at most one video"
 * domain invariant — see media-domain-design.md's retroactive
 * video/autoplay addendum. A SEPARATE guard from ProductMediaCountGuard,
 * not a branch inside it: ProductMediaCountGuard's own combined
 * photo/video slot count (a merchant-configurable limit, §6) is a
 * genuinely different invariant from this one (a fixed rule, not
 * configurable) — mirrors this package's existing
 * ProductMediaCountGuard/VariationMediaCountGuard precedent of one
 * small, single-purpose guard class per invariant rather than a single
 * guard parameterized by type/entity.
 *
 * No injected max, unlike its two siblings: "at most one" isn't a
 * merchant-configurable number for MediaServiceProvider to read from
 * config() — it's fixed, so there's nothing to inject.
 */
final class VideoCountGuard
{
    private const MAX_VIDEOS_PER_PRODUCT = 1;

    public function __construct(
        private readonly ProductMediaRepository $productMediaRepository,
    ) {}

    /**
     * >= is deliberate, not > : mirrors ProductMediaCountGuard's own
     * identical reasoning — at exactly MAX_VIDEOS_PER_PRODUCT already
     * attached, the next attach is rejected.
     */
    public function assertCanAttach(string $productId): void
    {
        $currentCount = $this->productMediaRepository->countByProductIdAndType($productId, MediaType::VIDEO);

        if ($currentCount >= self::MAX_VIDEOS_PER_PRODUCT) {
            throw MediaLimitExceededException::forProductVideo($productId);
        }
    }
}
