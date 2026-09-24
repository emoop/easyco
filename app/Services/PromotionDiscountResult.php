<?php

namespace App\Services;

use EasyCo\Pricing\Money;
use InvalidArgumentException;

/**
 * The outcome of PromotionDiscountCalculator::calculate() — see that
 * class's own docblock for the capping rules this reflects.
 */
final class PromotionDiscountResult
{
    /**
     * A cheap corruption detector, not routine defensive programming —
     * PromotionDiscountCalculator's own allocate() derives perLineShares
     * FROM amount() by construction (never the reverse), so these two
     * checks should never actually fire against real calculator output;
     * they exist to catch a future regression immediately, at the point
     * a bad PromotionDiscountResult would otherwise be constructed, not
     * to handle a scenario this class expects to occur.
     *
     * @param Money[] $perLineShares
     */
    private function __construct(
        private readonly Money $amount,
        private readonly bool $discountCapped,
        private readonly ?Money $nominalAmount,
        private readonly array $perLineShares,
    ) {
        if ($this->perLineShares === []) {
            throw new InvalidArgumentException('PromotionDiscountResult: perLineShares must not be empty.');
        }

        $sum = Money::zero($this->amount->currency());
        foreach ($this->perLineShares as $share) {
            // add() itself throws InvalidArgumentException on a
            // currency mismatch — the "same currency" half of this
            // invariant is enforced for free by reusing it, not by a
            // separate, duplicated currency check.
            $sum = $sum->add($share);
        }

        if (! $sum->equals($this->amount)) {
            throw new InvalidArgumentException(
                "PromotionDiscountResult: perLineShares sum ({$sum->minorValue()} {$sum->currency()->code()}) ".
                "must equal amount ({$this->amount->minorValue()} {$this->amount->currency()->code()})."
            );
        }
    }

    /**
     * The discount was not capped — $amount is exactly the computed
     * value.
     *
     * @param Money[] $perLineShares
     */
    public static function uncapped(Money $amount, array $perLineShares): self
    {
        return new self(amount: $amount, discountCapped: false, nominalAmount: null, perLineShares: $perLineShares);
    }

    /**
     * A FIXED_AMOUNT discount whose face value exceeded the eligible
     * base — $amount is the capped (actually-applied) value,
     * $nominalAmount is the code's original, uncapped face value.
     *
     * @param Money[] $perLineShares
     */
    public static function capped(Money $amount, Money $nominalAmount, array $perLineShares): self
    {
        return new self(amount: $amount, discountCapped: true, nominalAmount: $nominalAmount, perLineShares: $perLineShares);
    }

    /** The actual discount to subtract from the cart's subtotal. */
    public function amount(): Money
    {
        return $this->amount;
    }

    /**
     * True only when the Promotion is FIXED_AMOUNT and its nominal
     * discountAmount() exceeded the eligible base — PERCENTAGE never
     * needs capping (basis points are bounded 0-10000 by Promotion's
     * own validation, so it can never exceed its base).
     */
    public function discountCapped(): bool
    {
        return $this->discountCapped;
    }

    /**
     * The code's original, uncapped face value — only non-null when
     * discountCapped() is true. Lets a caller show "this code is worth
     * X but only Y of eligible items are in your cart."
     */
    public function nominalAmount(): ?Money
    {
        return $this->nominalAmount;
    }

    /**
     * This line's own share of amount() — operational-sales-domain-
     * design.md §3.13, promotions-domain-design.md §8. Positional, NOT
     * keyed by variationId: aligned 1:1, by array position, with the
     * $applicableLines array passed into
     * PromotionDiscountCalculator::calculate() — never an associative
     * array keyed by variationId, because a POS ticket may legitimately
     * carry the same variation on two separate lines (§3.13's own
     * "per-line keys" note), which an associative breakdown would
     * silently collapse. Always sums to exactly amount() — derived FROM
     * it via Money::allocate(), never computed independently, so this
     * property holds by construction, not by a separate check.
     *
     * No consumer yet (§3.13's own implementation stage 4 is when
     * SaleLine's promotionDiscountShare snapshot field starts reading
     * this) — CheckoutOrchestrator and CartController still use only
     * amount()/discountCapped()/nominalAmount(), unchanged.
     *
     * @return Money[]
     */
    public function perLineShares(): array
    {
        return $this->perLineShares;
    }
}
