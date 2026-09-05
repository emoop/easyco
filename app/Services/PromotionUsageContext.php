<?php

namespace App\Services;

/**
 * Cross-domain usage facts PromotionValidator needs but never queries
 * itself — assembled by the caller (CartController today,
 * CheckoutOrchestrator later) from EasyCo\Order\Contracts\
 * OrderRepository::hasAnyForAccount() and EasyCo\Promotions\Contracts\
 * PromotionRedemptionRepository's count methods. PromotionValidator
 * itself never queries anything, per its own documented posture.
 *
 * A CALLER MAY LEGITIMATELY SKIP A QUERY IT KNOWS IS UNCONSUMED — e.g.
 * CartController::resolvePromotion() only calls hasAnyForAccount()/the
 * count methods when the Promotion actually has the corresponding
 * setting (newCustomersOnly()/usageLimitTotal()/usageLimitPerCustomer())
 * — so false/0 here can mean either "genuinely false/zero" or "not
 * queried because nothing would have read it." Never read a value out
 * of this DTO in isolation and assume it reflects a real query; only
 * PromotionValidator's own gated reads are guaranteed correct.
 */
final class PromotionUsageContext
{
    public function __construct(
        private readonly bool $customerHasPreviousOrders,
        private readonly int $redemptionsTotal,
        private readonly int $redemptionsForAccount,
    ) {
    }

    /** false for a guest, always. */
    public function customerHasPreviousOrders(): bool
    {
        return $this->customerHasPreviousOrders;
    }

    /** Existing redemptions of this Promotion, across all customers. */
    public function redemptionsTotal(): int
    {
        return $this->redemptionsTotal;
    }

    /** Existing redemptions by THIS account; always 0 for a guest. */
    public function redemptionsForAccount(): int
    {
        return $this->redemptionsForAccount;
    }
}
