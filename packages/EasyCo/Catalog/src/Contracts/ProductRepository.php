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

    /**
     * Deliberately narrow, not a batched findById(): a full Product
     * aggregate load is roughly 4 queries per product (the model itself,
     * plus loadVariationAxes()/loadDescriptiveAttributes(), plus every
     * one of its Variations' own attribute-assignment load) — for a
     * caller that only needs brand_id for scope matching across a whole
     * product listing (App\Services\CatalogScopeResolver::forVariations()),
     * that would be 4N queries for something a single, plain column read
     * answers. One whereIn against catalog_products instead.
     *
     * @param string[] $productIds
     * @return array<string, ?string> keyed by product id; a product with
     *   no brand assigned maps to null (present in the array, not
     *   omitted — distinct from a productId that doesn't resolve at all,
     *   which the caller must treat identically to "no brand").
     */
    public function findBrandIdsByProductIds(array $productIds): array;
}
