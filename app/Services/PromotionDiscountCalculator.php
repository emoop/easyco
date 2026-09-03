<?php

namespace App\Services;

use EasyCo\Pricing\Currency;
use EasyCo\Pricing\Money;
use EasyCo\Promotions\Enums\PromotionDiscountType;
use EasyCo\Promotions\Promotion;
use InvalidArgumentException;

/**
 * Computes the actual discount amount for a Promotion already confirmed
 * applicable to a set of cart lines — pure Money/Promotion math only, no
 * database calls, no Cart/CartController/PromotionValidator knowledge,
 * same separation-of-concerns posture as PromotionValidator itself. The
 * caller is responsible for having already filtered $applicableLines
 * down to exactly PromotionValidationResult::applicableVariationIds();
 * this class doesn't know that type exists.
 *
 * TWO CAPPING RULES, BOTH DELIBERATE:
 * 1. FIXED_AMOUNT is capped at the eligible base — it can never exceed
 *    what the matching items are actually worth, never pushes anything
 *    below zero. PERCENTAGE never needs capping (basis points are
 *    bounded 0-10000 by Promotion's own construction-time validation).
 * 2. usage_limit_items, when set, caps how many cart UNITS (not lines)
 *    the discount touches — computed fresh every call, NOT a
 *    cross-order redemption count (that still needs Checkout, per
 *    promotions-domain-design.md §5). Lines are walked in the order
 *    given; the line that crosses the limit contributes only
 *    unitPrice * (remaining allowed units) to the base, not its full
 *    lineTotal.
 *
 * roundedDivide() below is a byte-for-byte copy of
 * EloquentPriceResolver::roundedDivide() (itself a copy of Price's own
 * private rounding helper) — deliberately reimplemented here rather
 * than shared, same posture that private method's own docblock
 * describes: each consumer stays narrowly local rather than widening
 * another package's public API for one extra caller.
 */
final class PromotionDiscountCalculator
{
    /**
     * @param array<int, array{variationId: string, quantity: int, unitPrice: Money, lineTotal: Money}> $applicableLines
     *   Must be non-empty — the caller only ever reaches this method
     *   once PromotionValidator has confirmed at least one applicable
     *   line exists.
     */
    public function calculate(Promotion $promotion, array $applicableLines): PromotionDiscountResult
    {
        if ($applicableLines === []) {
            throw new InvalidArgumentException(
                'PromotionDiscountCalculator::calculate() requires at least one applicable line.'
            );
        }

        $base = $this->eligibleBase($promotion, $applicableLines);

        if ($promotion->discountType() === PromotionDiscountType::PERCENTAGE) {
            $discountMinor = self::roundedDivide(
                $base->minorValue() * $promotion->percentageBasisPoints(),
                10000
            );

            return PromotionDiscountResult::uncapped(Money::fromMinorUnits($discountMinor, $base->currency()));
        }

        $nominal = $promotion->discountAmount();

        if ($nominal->subtract($base)->isPositive()) {
            return PromotionDiscountResult::capped($base, $nominal);
        }

        return PromotionDiscountResult::uncapped($nominal);
    }

    /**
     * @param array<int, array{variationId: string, quantity: int, unitPrice: Money, lineTotal: Money}> $applicableLines
     */
    private function eligibleBase(Promotion $promotion, array $applicableLines): Money
    {
        $currency = $applicableLines[0]['lineTotal']->currency();
        $usageLimitItems = $promotion->usageLimitItems();

        if ($usageLimitItems !== null) {
            $totalQuantity = array_sum(array_map(
                static fn (array $line) => $line['quantity'],
                $applicableLines
            ));

            if ($totalQuantity > $usageLimitItems) {
                return $this->baseCappedByUsageLimit($applicableLines, $usageLimitItems, $currency);
            }
        }

        $sum = Money::zero($currency);
        foreach ($applicableLines as $line) {
            $sum = $sum->add($line['lineTotal']);
        }

        return $sum;
    }

    /**
     * Walks $applicableLines in array order, accumulating quantity
     * toward $usageLimitItems. Lines entirely within the limit
     * contribute their full lineTotal; the one line that crosses it
     * contributes only unitPrice * (remaining allowed units) — an
     * exact integer multiplication (Money::multiply() only ever
     * accepts an integer factor), never a float. Lines beyond the
     * point the limit is exhausted contribute nothing.
     *
     * @param array<int, array{variationId: string, quantity: int, unitPrice: Money, lineTotal: Money}> $applicableLines
     */
    private function baseCappedByUsageLimit(array $applicableLines, int $usageLimitItems, Currency $currency): Money
    {
        $sum = Money::zero($currency);
        $remaining = $usageLimitItems;

        foreach ($applicableLines as $line) {
            if ($remaining <= 0) {
                break;
            }

            if ($line['quantity'] <= $remaining) {
                $sum = $sum->add($line['lineTotal']);
                $remaining -= $line['quantity'];

                continue;
            }

            $sum = $sum->add($line['unitPrice']->multiply($remaining));
            $remaining = 0;
        }

        return $sum;
    }

    /**
     * Half-up integer division, byte-for-byte the same algorithm as
     * EloquentPriceResolver::roundedDivide() / Price::roundedDivide() —
     * see this class's own docblock for why it's reimplemented here
     * rather than shared.
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
