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
 * unitCost IS NO LONGER RENDERED ANYWHERE — §14's own column pass removed
 * the cost column from the Order View Lines table for every role, margin
 * analysis being a future reports screen's job with its own permission
 * (admin-panel-design.md §14). It is kept here, and kept populated by
 * OrderAdminReader, because the snapshot is what a return reverses profit
 * from: a report reads this value, the order page does not. lineTotal is
 * kept for the same reason — the page stopped showing it, the read model
 * did not stop carrying it.
 *
 * isLegacy IS DERIVED, NOT STORED — D1: true exactly when netPaidAmount
 * is NULL. §3.13's stage-4a write path always sets every snapshot field
 * together, so "no net paid amount" is the one reliable marker of "this
 * row predates the full snapshot" (see OrderAdminReader::buildLineView()
 * for where a HALF-populated pair — corruption, not legacy — is made to
 * fail loudly instead, via SaleLineMapper's own rule).
 *
 * imagePath IS THE ONE LIVE VALUE ON THIS DTO, DELIBERATELY: §3.13's
 * snapshot stores no image at all, so there is nothing historical a line
 * could show — the thumbnail is the variation's (or, failing that, its
 * product's) CURRENT first READY photo, resolved in one batched read per
 * order by OrderAdminReader::imagePathsFor(). NULL means "no usable photo,
 * show none" and is the normal case for a variation with no media, never an
 * error.
 *
 * id IS THE LINE'S OWN PRIMARY KEY, AND IT IS THE ONLY WAY THIS ROW CAN BE
 * ADDRESSED (stage 7c-1). §8.4's cancel/return dialog is "one integer input
 * per SALE line (keyed by line id ...)" and "is filled from the id, never from
 * the name" — precisely because a legacy line HAS no name to fill it from
 * (productName is nullable for exactly that reason) while it still has an id
 * and a quantity, "the whole of what this form needs". This DTO carries it;
 * the form itself is the next sub-stage's consumer.
 *
 * remainingReturnable IS R7's NUMBER, READ — NEVER A COUNTER
 * (order-lifecycle-design.md §2.2 R7, §8.4): quantity minus the units already
 * returned against this line, summed across the REFUND lines sharing its own
 * originating_sale_line_id. It is the ceiling §8.4's form renders
 * (`minValue(0)`, up to it), and it is deliberately NOT the guard: R7's read
 * runs again, inside the locked transaction, in
 * OrderStatusChanger::recordReturn() before anything is written, so this value
 * being stale by one click can only produce a refusal, never an over-return.
 * CLAMPED AT 0, deliberately and only in this direction: no legitimate write
 * path can return more than a line's quantity (the same locked R7 read
 * refuses it), so a sum above the quantity means corrupted data — and "nothing
 * left to return" is the safe reading of that, whereas a negative capacity
 * would reach the form as a nonsense max.
 */
final class OrderAdminSaleLineView
{
    /**
     * @param array<int, array{definitionId: string, definitionCode: string, definitionName: string, valueId: string, value: string}> $soldAttributes
     *   The line's sold variation attributes in their stored order — []
     *   for a SIMPLE line and for a legacy line, never null.
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $productName,
        public readonly ?string $sku,
        public readonly int $quantity,
        public readonly int $remainingReturnable,
        public readonly Money $lineTotal,
        public readonly ?Money $unitPrice,
        public readonly ?Money $regularUnitPrice,
        public readonly ?Money $finalUnitPrice,
        public readonly ?Money $promotionDiscountShare,
        public readonly ?Money $discretionaryDiscount,
        public readonly ?Money $netPaidAmount,
        public readonly ?Money $unitCost,
        public readonly ?string $imagePath,
        public readonly array $soldAttributes,
        public readonly bool $isLegacy,
    ) {
    }
}
