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
        $model->released_at = $redemption->releasedAt();

        $model->save();

        if ($redemption->id() === null) {
            $redemption->assignId((string) $model->id);
        }
    }

    /** Excludes a released redemption (order-lifecycle-design.md §7.4, R11). */
    public function countForPromotion(string $promotionId): int
    {
        return PromotionRedemptionModel::where('promotion_id', $promotionId)
            ->whereNull('released_at')
            ->count();
    }

    /** Excludes a released redemption — same rule as countForPromotion(). */
    public function countForPromotionAndAccount(string $promotionId, string $accountId): int
    {
        return PromotionRedemptionModel::where('promotion_id', $promotionId)
            ->where('account_id', $accountId)
            ->whereNull('released_at')
            ->count();
    }

    /**
     * order-editing-design.md §7 (stage 3b): an edit that REPLACES a code
     * releases the old redemption and writes a new one, so one order can now
     * own several rows. This returns the CURRENT one — the unreleased row if
     * there is one, otherwise the most recent — so a later removal or a
     * cancellation releases the row that is actually still counting, never
     * an already-released predecessor. For the single-row orders that
     * existed before editing, the answer is unchanged.
     */
    public function findByOrderId(string $orderId): ?PromotionRedemption
    {
        $model = PromotionRedemptionModel::where('order_id', $orderId)
            ->orderByRaw('CASE WHEN released_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('id')
            ->first();

        if ($model === null) {
            return null;
        }

        return PromotionRedemption::reconstituteFromStorage(
            id: (string) $model->id,
            promotionId: (string) $model->promotion_id,
            orderId: (string) $model->order_id,
            accountId: $model->account_id !== null ? (string) $model->account_id : null,
            redeemedAt: $model->redeemed_at->toDateTimeImmutable(),
            releasedAt: $model->released_at?->toDateTimeImmutable(),
        );
    }
}
