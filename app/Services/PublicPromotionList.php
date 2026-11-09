<?php

namespace App\Services;

use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Pricing\Money;
use EasyCo\Promotions\Contracts\PromotionRepository;
use EasyCo\Promotions\Enums\PromotionScopeMode;
use EasyCo\Promotions\Enums\PromotionScopeType;
use EasyCo\Promotions\Persistence\Eloquent\PromotionRedemptionModel;
use EasyCo\Promotions\Persistence\Eloquent\PromotionScopeModel;
use EasyCo\Promotions\Promotion;
use EasyCo\Promotions\PromotionScope;

/**
 * P5: the "Потърси промокод" list of the cart: the PUBLIC promotion codes, each with the state the customer's
 * CURRENT cart is in towards it. Built only for GET /api/cart.
 *
 * It decides nothing itself: every verdict is PromotionValidator's, the same validator that applying a code uses, so
 * a code the list calls applicable is exactly a code that applying will accept.
 *
 *  - only a promotion flagged public AND active is read at all (PromotionRepository::allPublicActive()): a
 *    non-public promotion is never loaded here, so it cannot be listed;
 *  - applicable   — the validator accepts the code for this cart ("applied" when it is the cart's own applied code);
 *  - below_minimum — the validator refuses ONLY because the cart is under the minimum spend: the validator is asked
 *    once more with the cart at exactly the minimum, and if THAT is accepted the minimum was the only obstacle;
 *    `remaining` is the minor-unit gap;
 *  - unavailable  — any other refusal that is about THIS cart (new-customers-only, an account scope, nothing it
 *    applies to, over the maximum...): listed, disabled, no hint.
 *  - not listed at all: a code that is switched off, not yet started, expired or used up (in total, or by this customer).
 * Order: applicable first, then the smallest remaining, then the rest, ties by code; at most MAX_ITEMS.
 *
 * A FIXED NUMBER OF QUERIES whatever the number of codes: the codes (1), their scopes (1), the redemption counts
 * (1 for the totals, 1 for the account, only when some code has that limit), and the account's order history (once,
 * only when some code is for new customers only).
 */
final class PublicPromotionList
{
    public const MAX_ITEMS = 8;

    /** A code that is switched off, outside its dates or used up is not offered at all (as opposed to one that merely does not fit THIS cart, which is listed disabled). */
    private const NOT_LISTED_REASONS = ['inactive', 'not_yet_active', 'expired', 'usage_limit_reached', 'usage_limit_per_customer_reached'];

    public function __construct(
        private readonly PromotionRepository $promotions,
        private readonly PromotionValidator $validator,
        private readonly OrderRepository $orders,
    ) {
    }

    /**
     * @return list<array{code: string, description: string, state: string, remaining: ?array{minor: int, currency: string}}>
     */
    public function forCart(CartPricingResult $pricing, ?string $accountId): array
    {
        $priced = $pricing->pricedLines();

        // An empty cart has nothing a code could apply to: no list at all.
        if ($priced === []) {
            return [];
        }

        $promotions = $this->promotions->allPublicActive();

        if ($promotions === []) {
            return [];
        }

        $ids = array_map(static fn (Promotion $p): string => (string) $p->id(), $promotions);
        $scopesById = $this->scopesFor($ids);
        $totals = $this->totalsFor($promotions);
        $perAccount = $accountId !== null ? $this->perAccountFor($promotions, $accountId) : [];
        $hasOrders = $accountId !== null
            && array_filter($promotions, static fn (Promotion $p): bool => $p->newCustomersOnly()) !== []
            && $this->orders->hasAnyForAccount($accountId);

        $lines = array_map(static fn (CheckoutLinePricingResult $result): array => [
            'variationId' => $result->variationId(),
            'quantity' => $result->quantity(),
            'unitPrice' => $result->unitPrice(),
            'lineTotal' => $result->amount(),
            'productId' => $result->productId(),
            'matchingScopeReferenceIds' => $result->matchingScopeReferenceIds(),
            'isDiscounted' => $result->isDiscounted(),
        ], $priced);

        $subtotal = $pricing->subtotal();
        $items = [];

        foreach ($promotions as $promotion) {
            $id = (string) $promotion->id();
            $usage = new PromotionUsageContext(
                customerHasPreviousOrders: $promotion->newCustomersOnly() && $hasOrders,
                redemptionsTotal: $totals[$id] ?? 0,
                redemptionsForAccount: $perAccount[$id] ?? 0,
            );
            $scopes = $scopesById[$id] ?? [];

            $verdict = $this->validator->validate($promotion, $scopes, $subtotal, $accountId, $lines, $usage);
            $state = 'unavailable';
            $remaining = null;

            if ($verdict->isValid()) {
                $state = $pricing->appliedPromotionCode() === $promotion->code() ? 'applied' : 'applicable';
            } elseif (in_array($verdict->reason(), self::NOT_LISTED_REASONS, true)) {
                continue;
            } elseif ($verdict->reason() === 'minimum_spend_not_met' && $promotion->minimumSpend() !== null) {
                // The validator names the FIRST obstacle, and the minimum comes before the rest: ask it again with the cart at exactly the minimum to see whether anything else stands in the way.
                $atMinimum = $this->validator->validate($promotion, $scopes, $promotion->minimumSpend(), $accountId, $lines, $usage);

                if ($atMinimum->isValid()) {
                    $state = 'below_minimum';
                    $remaining = $promotion->minimumSpend()->subtract($subtotal);
                } elseif (in_array($atMinimum->reason(), self::NOT_LISTED_REASONS, true)) {
                    continue;
                }
            }

            $items[] = [
                'code' => $promotion->code(),
                'description' => (string) $promotion->publicDescription(),
                'state' => $state,
                'remaining' => $remaining !== null ? ['minor' => $remaining->minorValue(), 'currency' => $remaining->currency()->code()] : null,
            ];
        }

        $rank = ['applied' => 0, 'applicable' => 0, 'below_minimum' => 1, 'unavailable' => 2];

        usort($items, static function (array $a, array $b) use ($rank): int {
            return [$rank[$a['state']], $a['remaining']['minor'] ?? 0, $a['code']]
                <=> [$rank[$b['state']], $b['remaining']['minor'] ?? 0, $b['code']];
        });

        return array_slice($items, 0, self::MAX_ITEMS);
    }

    /**
     * @param string[] $ids
     * @return array<string, PromotionScope[]>
     */
    private function scopesFor(array $ids): array
    {
        $out = [];

        foreach (PromotionScopeModel::query()->whereIn('promotion_id', $ids)->get() as $model) {
            $out[(string) $model->promotion_id][] = PromotionScope::reconstituteFromStorage(
                id: (string) $model->id,
                promotionId: (string) $model->promotion_id,
                scopeType: PromotionScopeType::from($model->scope_type),
                scopeReferenceId: $model->scope_reference_id,
                mode: PromotionScopeMode::from($model->mode),
            );
        }

        return $out;
    }

    /**
     * @param Promotion[] $promotions
     * @return array<string, int>
     */
    private function totalsFor(array $promotions): array
    {
        $ids = $this->idsWith($promotions, static fn (Promotion $p): bool => $p->usageLimitTotal() !== null);

        if ($ids === []) {
            return [];
        }

        return PromotionRedemptionModel::query()
            ->whereIn('promotion_id', $ids)
            ->whereNull('released_at')
            ->selectRaw('promotion_id, COUNT(*) as aggregate')
            ->groupBy('promotion_id')
            ->pluck('aggregate', 'promotion_id')
            ->map(static fn ($n): int => (int) $n)
            ->all();
    }

    /**
     * @param Promotion[] $promotions
     * @return array<string, int>
     */
    private function perAccountFor(array $promotions, string $accountId): array
    {
        $ids = $this->idsWith($promotions, static fn (Promotion $p): bool => $p->usageLimitPerCustomer() !== null);

        if ($ids === []) {
            return [];
        }

        return PromotionRedemptionModel::query()
            ->whereIn('promotion_id', $ids)
            ->where('account_id', $accountId)
            ->whereNull('released_at')
            ->selectRaw('promotion_id, COUNT(*) as aggregate')
            ->groupBy('promotion_id')
            ->pluck('aggregate', 'promotion_id')
            ->map(static fn ($n): int => (int) $n)
            ->all();
    }

    /**
     * @param Promotion[] $promotions
     * @return string[]
     */
    private function idsWith(array $promotions, callable $has): array
    {
        return array_values(array_map(
            static fn (Promotion $p): string => (string) $p->id(),
            array_filter($promotions, $has),
        ));
    }
}
