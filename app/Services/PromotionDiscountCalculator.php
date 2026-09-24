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
 * PER-LINE BREAKDOWN (operational-sales-domain-design.md §3.13,
 * promotions-domain-design.md §8) — added on top of the already-correct
 * total, never changing it: eligibleAmountsPerLine() computes exactly
 * the same per-line eligible amounts this class already derived
 * internally before (full lineTotal, a usage-limit-crossing line's
 * partial unitPrice x remaining, or zero) — previously discarded the
 * moment they were summed into $base, now kept and exposed via
 * PromotionDiscountResult::perLineShares(). The already-computed total
 * discount is allocated across those SAME per-line amounts via
 * Money::allocate() (EasyCo\Pricing\Money) — the breakdown is derived
 * FROM the total, never the other way round, so amount() cannot regress
 * by construction: it is not possible for perLineShares() to sum to
 * anything other than amount(), because amount() IS what's being
 * allocated.
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

        $currency = $applicableLines[0]['lineTotal']->currency();
        $eligibleAmounts = $this->eligibleAmountsPerLine($promotion, $applicableLines);
        $base = self::sumAmounts($eligibleAmounts, $currency);

        if ($promotion->discountType() === PromotionDiscountType::PERCENTAGE) {
            $discountMinor = self::roundedDivide(
                $base->minorValue() * $promotion->percentageBasisPoints(),
                10000
            );
            $totalDiscount = Money::fromMinorUnits($discountMinor, $base->currency());

            return PromotionDiscountResult::uncapped($totalDiscount, self::allocate($totalDiscount, $eligibleAmounts));
        }

        $nominal = $promotion->discountAmount();

        if ($nominal->subtract($base)->isPositive()) {
            // Capped: the applied discount is the full eligible base —
            // Money::allocate($base, eligibleAmounts) degenerates to
            // share[i] == eligibleAmounts[i] exactly here (total ==
            // sum(weights) is the one case allocate() itself documents
            // as needing no rounding at all), so no special-casing is
            // needed for the per-line breakdown either.
            return PromotionDiscountResult::capped($base, $nominal, self::allocate($base, $eligibleAmounts));
        }

        return PromotionDiscountResult::uncapped($nominal, self::allocate($nominal, $eligibleAmounts));
    }

    /**
     * @param array<int, array{variationId: string, quantity: int, unitPrice: Money, lineTotal: Money}> $applicableLines
     * @return Money[] Same count/order as $applicableLines.
     */
    private function eligibleAmountsPerLine(Promotion $promotion, array $applicableLines): array
    {
        $usageLimitItems = $promotion->usageLimitItems();

        if ($usageLimitItems !== null) {
            $totalQuantity = array_sum(array_map(
                static fn (array $line) => $line['quantity'],
                $applicableLines
            ));

            if ($totalQuantity > $usageLimitItems) {
                return $this->perLineAmountsCappedByUsageLimit($applicableLines, $usageLimitItems);
            }
        }

        return array_map(static fn (array $line) => $line['lineTotal'], $applicableLines);
    }

    /**
     * Walks $applicableLines in array order, accumulating quantity
     * toward $usageLimitItems. Lines entirely within the limit
     * contribute their full lineTotal; the one line that crosses it
     * contributes only unitPrice * (remaining allowed units) — an
     * exact integer multiplication (Money::multiply() only ever
     * accepts an integer factor), never a float. Lines beyond the
     * point the limit is exhausted contribute zero.
     *
     * @param array<int, array{variationId: string, quantity: int, unitPrice: Money, lineTotal: Money}> $applicableLines
     * @return Money[] Same count/order as $applicableLines.
     */
    private function perLineAmountsCappedByUsageLimit(array $applicableLines, int $usageLimitItems): array
    {
        $currency = $applicableLines[0]['lineTotal']->currency();
        $amounts = [];
        $remaining = $usageLimitItems;

        foreach ($applicableLines as $line) {
            if ($remaining <= 0) {
                $amounts[] = Money::zero($currency);

                continue;
            }

            if ($line['quantity'] <= $remaining) {
                $amounts[] = $line['lineTotal'];
                $remaining -= $line['quantity'];

                continue;
            }

            $amounts[] = $line['unitPrice']->multiply($remaining);
            $remaining = 0;
        }

        return $amounts;
    }

    /**
     * @param Money[] $amounts
     */
    private static function sumAmounts(array $amounts, Currency $currency): Money
    {
        $sum = Money::zero($currency);

        foreach ($amounts as $amount) {
            $sum = $sum->add($amount);
        }

        return $sum;
    }

    /**
     * Money::allocate() takes integer weights, not Money — converts each
     * eligible amount's own minorValue() into the weight Money::allocate()
     * expects.
     *
     * @param Money[] $eligibleAmounts
     * @return Money[]
     */
    private static function allocate(Money $totalDiscount, array $eligibleAmounts): array
    {
        $weights = array_map(static fn (Money $amount) => $amount->minorValue(), $eligibleAmounts);

        return $totalDiscount->allocate($weights);
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
