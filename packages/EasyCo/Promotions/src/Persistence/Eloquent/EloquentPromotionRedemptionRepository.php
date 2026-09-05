<?php

namespace EasyCo\Promotions\Persistence\Eloquent;

use EasyCo\Promotions\Contracts\PromotionRedemptionRepository;
use EasyCo\Promotions\PromotionRedemption;

/**
 * Maps the PromotionRedemption entity onto `promotion_redemptions`. No
 * unique-constraint collision handling — nothing about this entity is
 * unique (an account can plausibly redeem more than one Promotion, and
 * this repository never enforces usage_limit_total/
 * usage_limit_per_customer itself — see the contract's own docblock).
 */
final class EloquentPromotionRedemptionRepository implements PromotionRedemptionRepository
{
    public function save(PromotionRedemption $redemption): void
    {
        $model = $redemption->id() !== null
            ? PromotionRedemptionModel::findOrFail($redemption->id())
            : new PromotionRedemptionModel();

        $model->promotion_id = $redemption->promotionId();
        $model->order_id = $redemption->orderId();
        $model->account_id = $redemption->accountId();
        $model->redeemed_at = $redemption->redeemedAt();

        $model->save();

        if ($redemption->id() === null) {
            $redemption->assignId((string) $model->id);
        }
    }

    public function countForPromotion(string $promotionId): int
    {
        return PromotionRedemptionModel::where('promotion_id', $promotionId)->count();
    }

    public function countForPromotionAndAccount(string $promotionId, string $accountId): int
    {
        return PromotionRedemptionModel::where('promotion_id', $promotionId)
            ->where('account_id', $accountId)
            ->count();
    }
}
