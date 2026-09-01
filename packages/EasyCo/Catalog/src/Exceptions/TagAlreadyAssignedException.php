<?php

namespace EasyCo\Catalog\Exceptions;

use RuntimeException;

/**
 * Thrown by EloquentProductTagRepository::save() when the underlying
 * UNIQUE(product_id, tag_id) constraint
 * (catalog_product_tags_product_id_tag_id_unique, from
 * 2026_08_23_000013_create_catalog_product_taxonomy_pivots.php) is
 * violated — the same Tag assigned twice to the same Product. Mirrors
 * EasyCo\Media\Exceptions\MediaAlreadyAttachedException's exact shape.
 */
final class TagAlreadyAssignedException extends RuntimeException
{
    public static function forProduct(string $productId, string $tagId): self
    {
        return new self(
            "Tag \"{$tagId}\" is already assigned to product \"{$productId}\"."
        );
    }
}
