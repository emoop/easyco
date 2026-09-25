<?php

namespace App\Sandbox;

/**
 * One row of the sandbox product page's variations table — prompt D, D5:
 * "attribute values (axis name + value), price per variation ..., stock
 * quantity, purchasable yes/no".
 *
 * $axisLabels is a list of `['name' => 'Size', 'value' => 'M']` pairs in
 * the order the variation's own attributeAssignments() reports them (the
 * domain's authoritative order, not a re-sorted copy). It is NOT
 * pre-formatted into 'Size: M' strings here: what separates the axis name
 * from its value on screen (a colon, a badge, a table cell) is a
 * presentation decision, and this object exists so the view makes it
 * without touching the database.
 *
 * $priceHtml is ALREADY HTML from App\Services\ProductPriceDisplay::
 * quoteHtml() — the same display rule the admin uses, and the same '—' a
 * variation with no configured price gets (never an error, D5).
 *
 * $stockQuantity is the real Inventory quantity (0 for a variation with
 * no stock row at all — StockLevelRepository::findByVariationId() never
 * returns null, its own contract), and $purchasable is the DOMAIN's own
 * Variation::isEffectivelyPurchasable(), never re-derived from the two
 * columns above.
 */
final readonly class SandboxVariationRow
{
    /**
     * @param array<int, array{name: string, value: string}> $axisLabels
     */
    public function __construct(
        public string $id,
        public string $sku,
        public array $axisLabels,
        public string $priceHtml,
        public int $stockQuantity,
        public bool $purchasable,
    ) {
    }
}
