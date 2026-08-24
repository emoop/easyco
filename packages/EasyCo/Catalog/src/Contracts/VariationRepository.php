<?php

namespace EasyCo\Catalog\Contracts;

use EasyCo\Catalog\Variation;

/**
 * Read-focused contract for the hot commerce lookup paths that don't need
 * the whole Product aggregate loaded — POS scanning a barcode, Cart/Orders
 * resolving a SKU, Pricing/Inventory resolving by priceableId. Backed by
 * the catalog_variations.sku and catalog_variations.barcode unique
 * indexes (see the migrations).
 */
interface VariationRepository
{
    public function findById(string $id): ?Variation;

    public function findBySku(string $sku): ?Variation;

    public function findByBarcode(string $barcode): ?Variation;

    /** @return Variation[] */
    public function findByProductId(string $productId): array;
}
