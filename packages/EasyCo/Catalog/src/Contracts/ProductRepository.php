<?php

namespace EasyCo\Catalog\Contracts;

use EasyCo\Catalog\Product;
use EasyCo\Catalog\Variation;

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

    /**
     * Force-deletes one variation row, and nothing else — the physical
     * half of catalog-domain-design.md §3.19's variation deletion (G-D2).
     *
     * DELIBERATELY NARROW: it touches `catalog_variations` only, letting
     * the database cascade that row's own catalog children
     * (`catalog_variation_attribute_values`, `catalog_variation_media`).
     * It must NOT touch `stock_levels`, `cart_lines`,
     * `pricing_price_list_items` or `pricing_product_costs` — those are
     * other domains' tables, and Catalog may never reach into them
     * (CLAUDE.md rule 1); App\Services\CatalogDeletion removes them in the
     * same transaction, before calling this.
     *
     * The caller also owns the transaction. There is no transaction here
     * on purpose: this is one statement, and the surrounding delete must
     * be atomic across four other domains' rows plus this one.
     *
     * withTrashed() + force-delete — never a plain delete(): VariationModel
     * uses SoftDeletes, so a soft delete would leave the row occupying
     * `sku` / `barcode` / `(product_id, attribute_signature)` forever (the
     * opposite of §3.19.11's freed identifiers) and would still block
     * `catalog_variations.product_id`'s restrict FK.
     */
    public function deleteVariation(Variation $variation): void;

    /**
     * Force-deletes every `catalog_variations` row of this product —
     * withTrashed(), so ARCHIVED (a real row) and soft-deleted rows go
     * alike — and then the `catalog_products` row itself: the physical half
     * of catalog-domain-design.md §3.19.4's steps 5-6, and the reason the
     * product path cannot reuse deleteVariation() for them: a SOFT-DELETED
     * variation has no domain object, so no `Variation` to pass.
     *
     * WHY THE VARIATION SWEEP LIVES HERE AND NOT IN THE SERVICE: every
     * table this touches is Catalog-owned (`catalog_*`), which is exactly
     * the boundary §3.19.3 draws for this contract. It must NOT touch
     * `stock_levels`, `cart_lines`, `pricing_price_list_items`,
     * `pricing_product_costs`, `pricing_price_list_scopes` or
     * `promotion_scopes` — App\Services\CatalogDeletion removes those in
     * the same transaction, before calling this, along with the
     * activity-log snapshot (§3.19.10).
     *
     * THE ORDER IS LOAD-BEARING: `catalog_variations.product_id` is
     * `restrictOnDelete()`, so the product row cannot go first. The
     * `catalog_products` force-delete then cascades
     * `catalog_product_attributes`, `_axis_values`, `_categories`, `_tags`
     * and `_media`.
     *
     * The caller also owns the transaction — there is none here on purpose,
     * same as deleteVariation().
     */
    public function delete(Product $product): void;

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
