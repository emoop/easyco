<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * Thrown by CheckoutOrchestrator::place() when the Cart's applied
 * promotion code fails live revalidation at finalization time.
 *
 * DOMAIN-OWNER DECISION, recorded here verbatim: at checkout, an applied
 * code that fails live revalidation ABORTS the whole order — it is NOT
 * silently dropped the way a cart read drops it. In the cart,
 * revalidation is informational: the customer just sees the code is
 * invalid. At finalization, silently dropping it would mean the customer
 * clicks "Pay" seeing one total and gets charged another. The customer
 * must always know the price they will actually pay; no silent
 * discrepancies. A clear "this code is no longer valid, please review
 * your cart" rejection is the correct behavior. This supersedes
 * checkout-domain-design.md §8.3 step 2's looser "or none" wording,
 * which contradicted step 11's own abort-on-usage-limit rule.
 */
final class PromotionNoLongerValidException extends RuntimeException
{
    public function __construct(
        private readonly string $promotionCode,
        private readonly ?string $reason,
    ) {
        parent::__construct("Promotion code \"{$promotionCode}\" is no longer valid".($reason !== null ? " ({$reason})" : '').'.');
    }

    /**
     * The code and the validator's reason as VALUES (order-editing stage
     * 4b-i): the admin edit dialog words this refusal itself, in the
     * merchant's language, instead of showing the English message. Additive —
     * the constructor and the message are unchanged, so checkout is unaffected.
     */
    public function promotionCode(): string
    {
        return $this->promotionCode;
    }

    /** One of PromotionValidator's reason codes (`expired`, `minimum_spend_not_met`, ...), `not_found`, or null. */
    public function reason(): ?string
    {
        return $this->reason;
    }
}
