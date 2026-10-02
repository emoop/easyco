<?php

namespace App\Services;

use EasyCo\Pricing\Money;
use EasyCo\Promotions\Promotion;

/**
 * Everything CartPricing worked out for one cart, as plain values (shipping
 * stage 3.0c). Immutable; it carries no behaviour beyond the two derived
 * amounts, discount() and goodsAfterDiscount().
 *
 * A promotion that is not valid is a RESULT here, not an exception: the cart
 * preview reports it (promotionRefusal()) and charges the plain subtotal, while
 * checkout turns the very same refusal into PromotionNoLongerValidException.
 * That decision belongs to the caller.
 *
 * Money is money in the cart's one currency; goodsAfterDiscount() is
 * subtotal - discount and is NOT guarded against going negative here — that
 * "structurally impossible" check stays where it always was (the cart preview
 * throws, checkout's SaleLine reconciliation and Order::create refuse).
 */
final class CartPricingResult
{
    /**
     * @param array<int, ?CheckoutLinePricingResult> $lineResults aligned to the cart's lines; null = unpriced (SKIP mode only)
     * @param string[] $unpricedVariationIds the lines left out of the subtotal (SKIP mode), in cart order
     * @param string[] $applicableVariationIds
     * @param array<int, Money> $perLineShares aligned to pricedLines(), zero for a non-applicable line
     */
    public function __construct(
        private readonly array $lineResults,
        private readonly array $unpricedVariationIds,
        private readonly Money $subtotal,
        private readonly ?string $appliedPromotionCode,
        private readonly ?Promotion $promotion,
        private readonly ?string $promotionRefusal,
        private readonly ?PromotionDiscountResult $discountResult,
        private readonly array $applicableVariationIds,
        private readonly array $perLineShares,
    ) {
    }

    /** @return array<int, ?CheckoutLinePricingResult> one entry per cart line, in cart order; null = no price */
    public function lineResults(): array
    {
        return $this->lineResults;
    }

    /** @return array<int, CheckoutLinePricingResult> the priced lines only, in cart order */
    public function pricedLines(): array
    {
        return array_values(array_filter($this->lineResults, static fn (?CheckoutLinePricingResult $line): bool => $line !== null));
    }

    /** @return string[] */
    public function unpricedVariationIds(): array
    {
        return $this->unpricedVariationIds;
    }

    public function subtotal(): Money
    {
        return $this->subtotal;
    }

    /** The code stored on the cart, whether or not it turned out to be valid. */
    public function appliedPromotionCode(): ?string
    {
        return $this->appliedPromotionCode;
    }

    /** The Promotion, only when the stored code is valid for this cart. */
    public function promotion(): ?Promotion
    {
        return $this->promotion;
    }

    /** Why the stored code does not apply (PromotionValidator's reason codes, or "not_found"); null when valid or no code. */
    public function promotionRefusal(): ?string
    {
        return $this->promotionRefusal;
    }

    public function discountResult(): ?PromotionDiscountResult
    {
        return $this->discountResult;
    }

    /** @return string[] */
    public function applicableVariationIds(): array
    {
        return $this->applicableVariationIds;
    }

    /** @return array<int, Money> aligned to pricedLines() */
    public function perLineShares(): array
    {
        return $this->perLineShares;
    }

    public function discount(): Money
    {
        return $this->discountResult?->amount() ?? Money::zero($this->subtotal->currency());
    }

    public function goodsAfterDiscount(): Money
    {
        return $this->subtotal->subtract($this->discount());
    }
}
