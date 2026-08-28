<?php

namespace EasyCo\Pricing\Persistence\Eloquent;

use DateTimeImmutable;
use EasyCo\Pricing\Contracts\PriceContext;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Contracts\PriceListScopeRepository;
use EasyCo\Pricing\Contracts\PriceQuote;
use EasyCo\Pricing\Contracts\PriceResolver;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Enums\PriceListScopeType;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListItem;
use RuntimeException;

/**
 * Real PriceResolver implementation running §4.6's algorithm against the
 * PriceList/PriceListScope/PriceListItem tables — see
 * pricing-persistence-domain-design.md §4.3-§4.6. Replaces
 * InMemoryPriceResolver (not yet swapped in PricingServiceProvider — that
 * binding change is a separate, later step, §8 item 2e).
 */
final class EloquentPriceResolver implements PriceResolver
{
    private const REGULAR_PRICES_LIST_NAME = 'Regular Prices';

    public function __construct(
        private readonly PriceListRepository $priceListRepository,
        private readonly PriceListScopeRepository $priceListScopeRepository,
        private readonly PriceListItemRepository $priceListItemRepository,
    ) {
    }

    public function resolve(PriceContext $context): PriceQuote
    {
        $at = $context->at ?? new DateTimeImmutable();

        $regularList = $this->priceListRepository->findSystemListByName(self::REGULAR_PRICES_LIST_NAME);

        if ($regularList === null) {
            throw new RuntimeException(
                'The reserved "Regular Prices" system PriceList has not been seeded — see '.
                'pricing-persistence-domain-design.md §4.5 / §8 item 3.'
            );
        }

        $regular = $this->resolveFixedItemsPrice($regularList, $context);

        if ($regular === null) {
            throw new RuntimeException(
                "No \"Regular Prices\" price is set for priceableId \"{$context->priceableId}\"."
            );
        }

        $winningList = $this->findWinningList($at, $context);

        if ($winningList === null || $winningList->id() === $regularList->id()) {
            return new PriceQuote(regular: $regular, final: $regular);
        }

        if ($winningList->mode() === PriceListMode::PERCENTAGE_OFF_REGULAR) {
            $final = $this->applyPercentageOff($regular, $winningList->percentageBasisPoints());

            return new PriceQuote(regular: $regular, final: $final);
        }

        // FIXED_ITEMS: winning by priority/scope, but no item row for this
        // exact target — §4.6 forbids falling through to the next-highest
        // priority list (no blending), so this falls back to regular.
        $final = $this->resolveFixedItemsPrice($winningList, $context) ?? $regular;

        return new PriceQuote(regular: $regular, final: $final);
    }

    /**
     * §4.6 steps 1-2: every ACTIVE, time-window-valid list, highest
     * priority first (ties broken by highest id — most recently created
     * — per findAllActiveAndValidAt()'s own ordering guarantee), AND-scope
     * matched (§4.1). The first match wins outright; defensively returns
     * null rather than assuming a match always exists (even though
     * "Regular Prices" being universal + always-active should guarantee
     * one in practice).
     */
    private function findWinningList(DateTimeImmutable $at, PriceContext $context): ?PriceList
    {
        foreach ($this->priceListRepository->findAllActiveAndValidAt($at) as $candidate) {
            if ($this->scopeMatches($candidate, $context)) {
                return $candidate;
            }
        }

        return null;
    }

    /** Zero scopes = universal (always matches). Otherwise AND logic, §4.1. */
    private function scopeMatches(PriceList $list, PriceContext $context): bool
    {
        $scopes = $this->priceListScopeRepository->findByPriceListId($list->id());

        if ($scopes === []) {
            return true;
        }

        foreach ($scopes as $scope) {
            $matches = match ($scope->scopeType()) {
                PriceListScopeType::CUSTOMER_GROUP => $scope->scopeReferenceId() === $context->customerGroupId,
                PriceListScopeType::CHANNEL => $scope->scopeReferenceId() === $context->channelId,
                PriceListScopeType::PRODUCT => $scope->scopeReferenceId() === $context->productId,
                PriceListScopeType::BRAND, PriceListScopeType::CATEGORY, PriceListScopeType::TAG, PriceListScopeType::ATTRIBUTE_VALUE => in_array(
                    $scope->scopeReferenceId(),
                    $context->matchingScopeReferenceIds[$scope->scopeType()->value] ?? [],
                    true
                ),
            };

            if (! $matches) {
                return false;
            }
        }

        return true;
    }

    /**
     * §4.3's item-level fallback (VARIATION first, then PRODUCT) followed
     * by §4.4's quantity-tier threshold lookup within whichever target
     * was found. Returns null when the list has no item at all for this
     * target — the caller decides what that means (regular-price
     * fallback for a non-system list, a hard error for "Regular Prices"
     * itself).
     */
    private function resolveFixedItemsPrice(PriceList $list, PriceContext $context): ?Price
    {
        $items = $this->priceListItemRepository->findByPriceListId($list->id());

        $candidates = array_values(array_filter(
            $items,
            fn (PriceListItem $item) => $item->targetType() === PriceListItemTargetType::VARIATION
                && $item->targetId() === $context->priceableId
        ));

        if ($candidates === [] && $context->productId !== null) {
            $candidates = array_values(array_filter(
                $items,
                fn (PriceListItem $item) => $item->targetType() === PriceListItemTargetType::PRODUCT
                    && $item->targetId() === $context->productId
            ));
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (PriceListItem $a, PriceListItem $b) => $b->minQuantity() <=> $a->minQuantity());

        foreach ($candidates as $candidate) {
            if ($candidate->minQuantity() <= $context->quantity) {
                return $candidate->price();
            }
        }

        return null;
    }

    /**
     * §4.6 step 4: regular price × (1 − percentage). Money::multiply()
     * only accepts an integer quantity by design (see Money's own
     * docblock — percentage math belongs to a future Discount domain,
     * not Money/Price), so this stays narrowly local to
     * PERCENTAGE_OFF_REGULAR resolution rather than growing Price's
     * public API for one extra consumer.
     */
    private function applyPercentageOff(Price $regular, int $percentageBasisPoints): Price
    {
        $rawMoney = $regular->isTaxInclusive() ? $regular->gross() : $regular->net();

        $discountedMinor = self::roundedDivide(
            $rawMoney->minorValue() * (10000 - $percentageBasisPoints),
            10000
        );

        $discountedMoney = Money::fromMinorUnits($discountedMinor, $rawMoney->currency());

        return $regular->isTaxInclusive()
            ? Price::inclusiveOfTax($discountedMoney, $regular->taxRateBasisPoints())
            : Price::exclusiveOfTax($discountedMoney, $regular->taxRateBasisPoints());
    }

    /**
     * Half-up integer division, byte-for-byte the same algorithm as
     * Price::roundedDivide() — that method is private to Price and not
     * reused directly on purpose: it stays Price's own internal net/gross
     * rounding helper, and this package does not widen Price's public API
     * just to share it with this one extra consumer.
     */
    private static function roundedDivide(int $numerator, int $denominator): int
    {
        $sign = ($numerator < 0) === ($denominator < 0) ? 1 : -1;
        $numerator = abs($numerator);
        $denominator = abs($denominator);

        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator - ($quotient * $denominator);

        if ($remainder * 2 >= $denominator) {
            $quotient++;
        }

        return $sign * $quotient;
    }
}
