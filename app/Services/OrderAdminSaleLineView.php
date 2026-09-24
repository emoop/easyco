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
 */
final class OrderAdminSaleLineView
{
    public function __construct(
        public readonly ?string $productName,
        public readonly ?string $sku,
        public readonly int $quantity,
        public readonly Money $lineTotal,
        public readonly ?Money $unitPrice,
    ) {
    }
}
