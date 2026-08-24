<?php

namespace EasyCo\Pricing;

use InvalidArgumentException;

/**
 * Immutable commercial price: a Money amount plus the minimum tax metadata
 * needed to compute net/gross/tax consistently.
 *
 * WHY Price DOES NOT DUPLICATE Money's FIELDS:
 * A price is not itself a different kind of number — it's a Money amount
 * plus commercial context (tax treatment). Storing minorValue/currency a
 * second time on Price would let the two drift out of sync; Price simply
 * holds a Money and delegates to it.
 *
 * WHY QUANTITY IS NOT HERE:
 * A price is "how much one unit costs", independent of how many units are
 * being bought. Quantity is a property of a line item (CartLine, OrderLine
 * — future work), which multiplies a Price's Money by an integer quantity.
 * Baking quantity into Price would make "the price of one item" and "the
 * price of a specific cart line" the same type, which they are not.
 *
 * WHY THE TAX RATE IS AN INTEGER (BASIS POINTS), NOT A FLOAT:
 * 1 basis point = 0.01%, so 10000 basis points = 100%, 2000 = 20%, 900 = 9%.
 * If the rate were a float (e.g. 0.20), computing net() or gross() would
 * require dividing/multiplying Money's integer minor units by a float —
 * reintroducing exactly the float-precision risk Money was built to avoid.
 * Keeping the rate as an integer keeps the entire net/gross/tax computation
 * on integers from end to end.
 *
 * ROUNDING STRATEGY:
 * Whichever of net/gross is the price's stored basis is exact by
 * definition; the OTHER one is computed with half-up integer rounding.
 * tax() is never rounded independently — it is always
 * (gross - net) by subtraction. This guarantees net + tax === gross for
 * every price, in every currency, with no possibility of the two
 * independently-rounded numbers failing to add up.
 *
 * DELIBERATELY NOT IMPLEMENTED YET (future work, see RFC-01):
 * - Discounts/promotions: kept out of Price on purpose. EasyCo will need
 *   several different discount sources later (special price, customer-group
 *   price, quantity breaks, coupons, campaigns) that combine in ways a
 *   single "withDiscountPercent()" on Price can't express or stay honest
 *   about — that belongs to a future Discount domain that produces a new
 *   Price, not a method Price grows for itself.
 * - Tax classes/rules/jurisdictions: Price only knows "this rate applies",
 *   not *why* — rate resolution by country/state/product-tax-class is a
 *   future Tax domain that decides which basis-points value to pass in.
 * - Currency conversion: inherited from Money — not a Price concern either.
 */
final class Price
{
    private function __construct(
        private readonly Money $money,
        private readonly int $taxRateBasisPoints,
        private readonly bool $taxInclusive,
    ) {
    }

    /**
     * @param Money $money            The net (tax-exclusive) amount.
     * @param int   $taxRateBasisPoints 1 basis point = 0.01%; 2000 = 20%.
     */
    public static function exclusiveOfTax(Money $money, int $taxRateBasisPoints = 0): self
    {
        self::assertTaxRate($taxRateBasisPoints);

        return new self($money, $taxRateBasisPoints, taxInclusive: false);
    }

    /**
     * @param Money $money            The gross (tax-inclusive) amount.
     * @param int   $taxRateBasisPoints 1 basis point = 0.01%; 2000 = 20%.
     */
    public static function inclusiveOfTax(Money $money, int $taxRateBasisPoints = 0): self
    {
        self::assertTaxRate($taxRateBasisPoints);

        return new self($money, $taxRateBasisPoints, taxInclusive: true);
    }

    public function currency(): Currency
    {
        return $this->money->currency();
    }

    public function taxRateBasisPoints(): int
    {
        return $this->taxRateBasisPoints;
    }

    /** Display convenience only — never used internally for calculations. */
    public function taxRatePercent(): float
    {
        return $this->taxRateBasisPoints / 100;
    }

    public function isTaxInclusive(): bool
    {
        return $this->taxInclusive;
    }

    /** The net (tax-exclusive) amount. */
    public function net(): Money
    {
        if (! $this->taxInclusive) {
            return $this->money;
        }

        $netMinor = self::roundedDivide(
            $this->money->minorValue() * 10000,
            10000 + $this->taxRateBasisPoints
        );

        return Money::fromMinorUnits($netMinor, $this->currency());
    }

    /** The gross (tax-inclusive) amount. */
    public function gross(): Money
    {
        if ($this->taxInclusive) {
            return $this->money;
        }

        $grossMinor = self::roundedDivide(
            $this->money->minorValue() * (10000 + $this->taxRateBasisPoints),
            10000
        );

        return Money::fromMinorUnits($grossMinor, $this->currency());
    }

    /**
     * Always gross() - net() by subtraction — never rounded independently,
     * so net() + tax() === gross() always holds exactly.
     */
    public function tax(): Money
    {
        return $this->gross()->subtract($this->net());
    }

    public function equals(self $other): bool
    {
        return $this->money->equals($other->money)
            && $this->taxRateBasisPoints === $other->taxRateBasisPoints
            && $this->taxInclusive === $other->taxInclusive;
    }

    private static function assertTaxRate(int $taxRateBasisPoints): void
    {
        if ($taxRateBasisPoints < 0) {
            throw new InvalidArgumentException('Tax rate basis points cannot be negative.');
        }
    }

    /**
     * Half-up integer division: rounds .5 and above up, pure integer math.
     * Used instead of PHP's round() so nothing here ever touches a float.
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
