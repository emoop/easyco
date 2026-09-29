<?php

namespace EasyCo\Promotions\Contracts;

use EasyCo\Promotions\PromotionRedemption;

/**
 * Plain COUNT queries only — countForPromotion()/
 * countForPromotionAndAccount() are read helpers, NOT the atomic
 * "lock the Promotion row, check counts, then insert" enforcement
 * transaction checkout-domain-design.md §7 describes. That enforcement
 * is Checkout orchestration's job, a later task; this repository only
 * provides the building blocks it will need.
 */
interface PromotionRedemptionRepository
{
    public function save(PromotionRedemption $redemption): void;

    /** Excludes a released redemption (order-lifecycle-design.md §7.4, R11) — a cancelled order's code no longer counts. */
    public function countForPromotion(string $promotionId): int;

    /** Excludes a released redemption — same rule as countForPromotion(). */
    public function countForPromotionAndAccount(string $promotionId, string $accountId): int;

    /**
     * The redemption written for $orderId, if any — order-lifecycle-
     * design.md §5.2's cancel path uses this to find the row to release.
     * An order without an applied promotion code has none: null, not an
     * error.
     */
    public function findByOrderId(string $orderId): ?PromotionRedemption;
}
