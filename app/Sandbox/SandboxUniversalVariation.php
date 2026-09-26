<?php

namespace App\Sandbox;

/**
 * A SIMPLE product's one variation, reduced to what its page shows —
 * prompt D, D3's SIMPLE branch (see SandboxCatalogReader's own docblock
 * for the two-branch rule) and D5's catalog data.
 *
 * WHY THIS EXISTS INSTEAD OF A ONE-ROW SandboxVariationRow TABLE: a
 * SIMPLE product's Universal variation is not customer-selectable — the
 * domain forces isVisible = false for it and refuses setVisible(true) —
 * so rendering it as a table row would show a customer a "choice" that
 * does not exist. Its two facts a customer DOES need (how many are left,
 * and whether it can be bought right now) are shown under the price
 * instead, together with the add-to-cart control that needs its id —
 * $id is the variation the real POST /api/cart/lines accepts, and
 * nothing else about it is exposed.
 *
 * $stockQuantity is the real Inventory quantity (0 for a variation with
 * no stock row at all — StockLevelRepository::findByVariationId() never
 * returns null, its own contract). $purchasable is the DOMAIN's own
 * Variation::isEffectivelyPurchasable() (status ACTIVE + is_purchasable),
 * never re-derived here: the same rule Cart/POS use.
 *
 * A non-null instance means "the universal variation is ACTIVE"; a
 * non-ACTIVE one produces no instance at all, and the page renders
 * neither fact rather than a misleading zero.
 */
final readonly class SandboxUniversalVariation
{
    public function __construct(
        public string $id,
        public int $stockQuantity,
        public bool $purchasable,
    ) {
    }
}
