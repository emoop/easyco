<?php

namespace EasyCo\Media\Exceptions;

use RuntimeException;

/**
 * Thrown by ProductMediaCountGuard/VariationMediaCountGuard when
 * attaching another MediaAsset would exceed the configured max-media-
 * per-product/variation limit — see media-domain-design.md §6. One
 * exception class covers both guards (a Product limit and a Variation
 * limit): the exception itself carries no entity-specific state beyond
 * its message, unlike the domain classes (ProductMedia/VariationMedia),
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
}
