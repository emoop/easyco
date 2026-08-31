<?php

namespace EasyCo\Media\Exceptions;

use RuntimeException;

/**
 * Thrown by EloquentProductMediaRepository::save() /
 * EloquentVariationMediaRepository::save() when the underlying
 * UNIQUE(product_id, media_id) / UNIQUE(variation_id, media_id)
 * constraint (2026_08_23_000012_create_catalog_media_tables.php) is
 * violated — the same MediaAsset attached twice to the same
 * Product/Variation. Same shape as MediaLimitExceededException: one
 * exception class covers both repositories, a forProduct()/forVariation()
 * pair of named constructors.
 */
final class MediaAlreadyAttachedException extends RuntimeException
{
    public static function forProduct(string $productId, string $mediaId): self
    {
        return new self(
            "Media \"{$mediaId}\" is already attached to product \"{$productId}\"."
        );
    }

    public static function forVariation(string $variationId, string $mediaId): self
    {
        return new self(
            "Media \"{$mediaId}\" is already attached to variation \"{$variationId}\"."
        );
    }
}
