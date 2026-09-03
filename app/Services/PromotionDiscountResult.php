<?php

namespace App\Services;

use EasyCo\Pricing\Money;

/**
 * The outcome of PromotionDiscountCalculator::calculate() — see that
 * class's own docblock for the capping rules this reflects.
 */
final class PromotionDiscountResult
{
    private function __construct(
        private readonly Money $amount,
        private readonly bool $discountCapped,
        private readonly ?Money $nominalAmount,
    ) {
    }

    /** The discount was not capped — $amount is exactly the computed value. */
    public static function uncapped(Money $amount): self
    {
        return new self(amount: $amount, discountCapped: false, nominalAmount: null);
    }

    /**
     * A FIXED_AMOUNT discount whose face value exceeded the eligible
     * base — $amount is the capped (actually-applied) value,
     * $nominalAmount is the code's original, uncapped face value.
     */
    public static function capped(Money $amount, Money $nominalAmount): self
    {
        return new self(amount: $amount, discountCapped: true, nominalAmount: $nominalAmount);
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
}
