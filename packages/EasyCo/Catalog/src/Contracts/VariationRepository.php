<?php

namespace EasyCo\Catalog\Contracts;

use EasyCo\Catalog\Variation;

/**
 * Read-focused contract for the hot commerce lookup paths that don't need
 * the whole Product aggregate loaded — POS scanning a barcode, Cart/Orders
 * resolving a SKU, Pricing/Inventory resolving by priceableId. Backed by
 * the catalog_variations.sku and catalog_variations.barcode unique
 * indexes (see the migrations).
 *
 * Carries exactly ONE narrow write: updateSortOrders() below —
 * catalog_variations.sort_order, the merchant's own display order for
 * their variations. Deliberately not a Variation domain field (see the
 * 2026_09_23_000001 migration's own docblock / admin-panel-design.md
 * §13.6), the same "ordering is a merchandising concern the domain object
 * doesn't carry" precedent as the media pivots' sort_order.
 */
interface VariationRepository
{
    public function findById(string $id): ?Variation;

    public function findBySku(string $sku): ?Variation;

    public function findByBarcode(string $barcode): ?Variation;

    /** @return Variation[] */
    public function findByProductId(string $productId): array;

    /**
     * Rewrites this product's variation display order to exactly the
     * given sequence: array index becomes catalog_variations.sort_order.
     * A full-array replace, not a per-item position patch — the same
     * deliberate choice media-domain-design.md §8 already made for the
     * media pivots' own reorder (per-item patches can end up duplicated
     * or out of sync with the real drag order; one ordered array applied
     * inside a single transaction cannot).
     *
     * Rows already sitting at their target sort_order are left untouched
     * (no write, no updated_at bump), so calling this on every ordinary
     * save is cheap and side-effect-free. Variations NOT listed here —
     * archived ones in particular — keep whatever sort_order they
     * already have.
     *
     * Fails loud (\InvalidArgumentException) if any given id does not
     * belong to this product — a caller that builds this list from a
     * submitted form must filter it to the product's own variations
     * first; silently renumbering a foreign variation would be a real
     * cross-product write.
     *
     * @param array<int, string> $orderedVariationIds
     */
    public function updateSortOrders(string $productId, array $orderedVariationIds): void;
}
