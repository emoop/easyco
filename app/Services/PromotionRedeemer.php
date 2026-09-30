<?php

namespace App\Services;

use App\Services\Exceptions\PromotionNoLongerValidException;
use DateTimeImmutable;
use EasyCo\Promotions\Contracts\PromotionRedemptionRepository;
use EasyCo\Promotions\Promotion;
use EasyCo\Promotions\PromotionRedemption;
use Illuminate\Support\Facades\DB;

/**
 * The authoritative usage-limit enforcement, per checkout-domain-design.md
 * §7: locks the Promotion row, re-counts existing redemptions against both
 * limits, and only inserts if both still hold — a weaker guarantee than a
 * true DB constraint (depends on every caller using this inside its own
 * transaction), stated plainly, matching §7's own posture.
 *
 * EXTRACTED VERBATIM FROM CheckoutOrchestrator (stage 3b) so that placement
 * and an order EDIT that applies a new code share ONE implementation of the
 * locked re-check rather than two copies that could drift — the same reason
 * PromotionUsageContextAssembler exists. Behaviour, exception and lock are
 * unchanged for checkout.
 *
 * ASSUMES IT IS ALREADY INSIDE AN OPEN TRANSACTION: the row lock is only
 * meaningful there, and this class opens none of its own.
 */
final class PromotionRedeemer
{
    public function __construct(
        private readonly PromotionRedemptionRepository $promotionRedemptions,
    ) {}

    /**
     * @throws PromotionNoLongerValidException With reason `usage_limit_reached` or `usage_limit_per_customer_reached`.
     */
    public function redeemAtomically(
        Promotion $promotion,
        string $orderId,
        ?string $accountId,
        DateTimeImmutable $redeemedAt,
    ): void {
        DB::table('promotions')->where('id', $promotion->id())->lockForUpdate()->first();

        if ($promotion->usageLimitTotal() !== null) {
            $count = $this->promotionRedemptions->countForPromotion($promotion->id());

            if ($count >= $promotion->usageLimitTotal()) {
                throw new PromotionNoLongerValidException($promotion->code(), 'usage_limit_reached');
            }
        }

        if ($promotion->usageLimitPerCustomer() !== null && $accountId !== null) {
            $countForAccount = $this->promotionRedemptions->countForPromotionAndAccount($promotion->id(), $accountId);

            if ($countForAccount >= $promotion->usageLimitPerCustomer()) {
                throw new PromotionNoLongerValidException($promotion->code(), 'usage_limit_per_customer_reached');
            }
        }

        $redemption = new PromotionRedemption(
            id: null,
            promotionId: $promotion->id(),
            orderId: $orderId,
            accountId: $accountId,
            redeemedAt: $redeemedAt,
        );

        $this->promotionRedemptions->save($redemption);
    }
}
