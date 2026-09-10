<?php

namespace App\Services;

use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Promotions\Contracts\PromotionRedemptionRepository;
use EasyCo\Promotions\Promotion;

/**
 * Assembles PromotionUsageContext for a given Promotion/accountId pair —
 * the single place both CartController::resolvePromotion() and
 * CheckoutOrchestrator::resolvePromotion() call, instead of each hand-
 * rolling an identical inline assembly. Extracted specifically because
 * that duplication already existed once, guarded only by a comment
 * ("mirrors CartController::resolvePromotion() exactly — do not write a
 * second, differently-guarded assembly") that could not actually prevent
 * the very divergence it warned about — a comment is not a mechanism.
 *
 * WHY THIS IS ITS OWN CLASS, NOT A PRIVATE METHOD ON EITHER CALLER — the
 * per-setting query guards below are not cosmetic, and getting one wrong
 * is a real, silent correctness bug, not just wasted effort:
 * - Each fact is queried ONLY when the Promotion actually carries the
 *   setting that consumes it (newCustomersOnly() / usageLimitTotal() /
 *   usageLimitPerCustomer()), because PromotionValidator reads each
 *   corresponding getter exclusively inside a branch already gated by
 *   that same setting (see its own class docblock/checks). A plain
 *   Promotion with none of these flags therefore costs zero extra
 *   queries here.
 * - A caller that hand-assembled this and got a guard wrong would
 *   either issue pointless queries on every cart read (a Promotion with
 *   no usage limits still querying `promotion_redemptions`), or — worse
 *   — pass a real `0`/`false` in a case where a query was actually
 *   needed, silently admitting a customer/redemption count PromotionValidator
 *   would have rejected had it been asked for real.
 *
 * A false/0 value on the returned PromotionUsageContext can therefore
 * mean either "genuinely false/zero" or "not queried because nothing
 * would have read it" — never read a value off it in isolation and
 * assume it reflects a real query; see PromotionUsageContext's own
 * docblock, which this class does not repeat further.
 */
final class PromotionUsageContextAssembler
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly PromotionRedemptionRepository $promotionRedemptions,
    ) {
    }

    public function assemble(Promotion $promotion, ?string $accountId): PromotionUsageContext
    {
        return new PromotionUsageContext(
            customerHasPreviousOrders: $promotion->newCustomersOnly()
                && $accountId !== null
                && $this->orders->hasAnyForAccount($accountId),
            redemptionsTotal: $promotion->usageLimitTotal() !== null
                ? $this->promotionRedemptions->countForPromotion($promotion->id())
                : 0,
            redemptionsForAccount: $promotion->usageLimitPerCustomer() !== null && $accountId !== null
                ? $this->promotionRedemptions->countForPromotionAndAccount($promotion->id(), $accountId)
                : 0,
        );
    }
}
