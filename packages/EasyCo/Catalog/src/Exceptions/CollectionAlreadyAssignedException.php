<?php

namespace EasyCo\Catalog\Exceptions;

use RuntimeException;

/**
 * Thrown by EloquentProductCollectionRepository::save() when the underlying
 * UNIQUE(product_id, collection_id) constraint
 * (cpcol_product_collection_unique, from
 * 2026_11_12_000001_create_catalog_collections_tables.php) is
 * violated — the same Collection assigned twice to the same Product. Mirrors
 * EasyCo\Media\Exceptions\MediaAlreadyAttachedException's exact shape.
 */
final class CollectionAlreadyAssignedException extends RuntimeException
{
    public static function forProduct(string $productId, string $collectionId): self
    {
        return new self(
            "Collection \"{$collectionId}\" is already assigned to product \"{$productId}\"."
        );
    }
}
