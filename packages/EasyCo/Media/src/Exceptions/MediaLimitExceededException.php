<?php

namespace EasyCo\Media\Exceptions;

use RuntimeException;

/**
 * Thrown by ProductMediaCountGuard/VariationMediaCountGuard/
 * VideoCountGuard when attaching another MediaAsset would exceed a
 * configured (or, for forProductVideo(), fixed) media limit — see
 * media-domain-design.md §6 and its retroactive video/autoplay
 * addendum. One exception class covers all three guards: the
 * exception itself carries no entity-specific state beyond its
 * message, unlike the domain classes (ProductMedia/VariationMedia),
 * which stay deliberately separate.
 */
final class MediaLimitExceededException extends RuntimeException
{
    public static function forProduct(string $productId, int $currentCount, int $maxCount): self
    {
        return new self(
            "Cannot attach more media to product \"{$productId}\": ".
            "it already has {$currentCount} of a maximum {$maxCount}."
        );
    }

    public static function forVariation(string $variationId, int $currentCount, int $maxCount): self
    {
        return new self(
            "Cannot attach more media to variation \"{$variationId}\": ".
            "it already has {$currentCount} of a maximum {$maxCount}."
        );
    }

    /**
     * No currentCount/maxCount params, unlike the two factories above —
     * VideoCountGuard's own "at most one video" limit is fixed (§ the
     * retroactive video/autoplay addendum), not a configured number a
     * merchant could change, so there is nothing variable to report
     * beyond the product id itself.
     */
    public static function forProductVideo(string $productId): self
    {
        return new self(
            "Cannot attach another video to product \"{$productId}\": ".
            'a product may have at most one video.'
        );
    }
}
