<?php

namespace EasyCo\Catalog\Contracts;

use EasyCo\Catalog\Product;

/**
 * Persistence contract for the Product aggregate. Implementations must
 * persist a Product and ALL of its Variations (including the child
 * catalog_variation_attribute_values / attribute_signature writes) inside
 * a single database transaction — see catalog-domain-design.md
 * §"Variation combination uniqueness" for why this matters for the
 * DB-level uniqueness guarantee, and translate a caught unique-constraint
 * violation on (product_id, attribute_signature) into
 * DuplicateVariationCombinationException::fromDatabaseConstraintViolation().
 */
interface ProductRepository
{
    public function save(Product $product): void;

    public function findById(string $id): ?Product;

    /**
     * Loads a Product together with every Variation needed to render a
     * complete catalog representation for one product — the
     * "product_id -> complete catalog representation" hot path from the
     * design doc. Implementations should eager-load in a bounded number
     * of queries (no N+1 across variations/attributes/media).
     */
    public function findByIdWithVariations(string $id): ?Product;

    public function findBySku(string $sku): ?Product;

    public function findByBarcode(string $barcode): ?Product;

    public function findByBaseSku(string $baseSku): ?Product;

    public function findBySlug(string $slug): ?Product;
}
