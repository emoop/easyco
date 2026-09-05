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

    public function countForPromotion(string $promotionId): int;

    public function countForPromotionAndAccount(string $promotionId, string $accountId): int;
}
