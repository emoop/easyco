<?php

namespace App\Services;

use EasyCo\Pricing\Money;

/**
 * One rendered row of an Order's admin View page — admin-panel-design.md
 * §14, D3. Built entirely from operational_sales_sale_lines' own snapshot
 * columns (never re-resolved through Catalog/Pricing — D2), so a renamed
 * product, a changed SKU, or a changed price after the order was placed
 * never changes what this shows.
 *
 * unitPrice IS NULLABLE, NEVER SILENTLY ROUNDED — D3: it is
 * amount_minor / quantity, which is exact by construction
 * (CheckoutLinePricer builds amount as unitPrice->multiply($quantity), a
 * plain integer multiplication — see Money::multiply()), but
 * OrderAdminReader still verifies the division is exact before setting
 * this rather than assuming it; a non-exact division (would need
 * corrupted or hand-inserted data to occur at all) logs a warning and
 * leaves this null, rendered as '—' by the View page, never a silently
 * rounded figure.
 *
 * STAGE 5 — THE FULL §3.13 SNAPSHOT, READ STRAIGHT FROM THE SALE LINE:
 * regularUnitPrice/finalUnitPrice/promotionDiscountShare/
 * discretionaryDiscount/netPaidAmount/unitCost and soldAttributes are the
 * sale line's own stored snapshot columns (operational-sales-domain-
 * design.md §3.13), never re-resolved through Pricing or Catalog (D2).
 * All six Money fields are NULLABLE: a line written before §3.13's stage
 * 2 migration has NEITHER half of every pair (legacy), and even a fresh
 * line's unitCost is legitimately NULL when the cost is genuinely unknown
 * (§3.13 Q2).
 *
 * isLegacy IS DERIVED, NOT STORED — D1: true exactly when netPaidAmount
 * is NULL. §3.13's stage-4a write path always sets every snapshot field
 * together, so "no net paid amount" is the one reliable marker of "this
 * row predates the full snapshot" (see OrderAdminReader::buildLineView()
 * for where a HALF-populated pair — corruption, not legacy — is made to
 * fail loudly instead, via SaleLineMapper's own rule).
 */
final class OrderAdminSaleLineView
{
    /**
     * @param array<int, array{definitionId: string, definitionCode: string, definitionName: string, valueId: string, value: string}> $soldAttributes
     *   The line's sold variation attributes in their stored order — []
     *   for a SIMPLE line and for a legacy line, never null.
     */
    public function __construct(
        public readonly ?string $productName,
        public readonly ?string $sku,
        public readonly int $quantity,
        public readonly Money $lineTotal,
        public readonly ?Money $unitPrice,
        public readonly ?Money $regularUnitPrice,
        public readonly ?Money $finalUnitPrice,
        public readonly ?Money $promotionDiscountShare,
        public readonly ?Money $discretionaryDiscount,
        public readonly ?Money $netPaidAmount,
        public readonly ?Money $unitCost,
        public readonly array $soldAttributes,
        public readonly bool $isLegacy,
    ) {
    }
}
