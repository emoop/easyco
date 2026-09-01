<?php

namespace EasyCo\Catalog\Exceptions;

use RuntimeException;

/**
 * Thrown by EloquentProductCategoryRepository::save() when the
 * underlying UNIQUE(product_id, category_id) constraint
 * (catalog_product_categories_product_id_category_id_unique, from
 * 2026_08_23_000013_create_catalog_product_taxonomy_pivots.php) is
 * violated — the same Category assigned twice to the same Product.
 * Mirrors EasyCo\Media\Exceptions\MediaAlreadyAttachedException's exact
 * shape.
 */
final class CategoryAlreadyAssignedException extends RuntimeException
{
    public static function forProduct(string $productId, string $categoryId): self
    {
        return new self(
            "Category \"{$categoryId}\" is already assigned to product \"{$productId}\"."
        );
    }
}
