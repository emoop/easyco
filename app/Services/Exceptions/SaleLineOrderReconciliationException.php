<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * Thrown by CheckoutOrchestrator::placeWithinTransaction() when the
 * built SaleLines don't reconcile with the Order they belong to —
 * operational-sales-domain-design.md §3.13's own Invariants section
 * (Σ promotionDiscountShare == order discount, Σ netPaidAmount == order
 * total). Aborts the whole checkout transaction, same as any other
 * invariant-violation exception in this flow — this should never
 * actually fire in production if the Promotion allocation rule
 * (Money::allocate()) is implemented correctly; it exists as a cheap,
 * always-on corruption detector, not routine defensive programming.
 */
final class SaleLineOrderReconciliationException extends RuntimeException
{
}
