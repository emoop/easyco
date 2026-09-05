<?php

namespace App\Services;

use EasyCo\Pricing\Money;

/**
 * The outcome of CheckoutLinePricer::priceLine() — everything Checkout
 * orchestration needs, per line, to eventually build a SaleLine
 * (checkout-domain-design.md §8.3 step 8) and contribute to Order's
 * subtotal (§8.3 step 4).
 */
final class CheckoutLinePricingResult
{
    /** @param array<string, string[]> $matchingScopeReferenceIds */
    private function __construct(
        private readonly string $variationId,
        private readonly int $quantity,
        private readonly ?string $productId,
        private readonly array $matchingScopeReferenceIds,
        private readonly bool $isDiscounted,
        private readonly Money $unitPrice,
        private readonly Money $amount,
        private readonly Money $profit,
        private readonly bool $costRecorded,
    ) {
    }

    /** @param array<string, string[]> $matchingScopeReferenceIds */
    public static function create(
        string $variationId,
        int $quantity,
        ?string $productId,
        array $matchingScopeReferenceIds,
        bool $isDiscounted,
        Money $unitPrice,
        Money $amount,
        Money $profit,
        bool $costRecorded,
    ): self {
        return new self(
            $variationId,
            $quantity,
            $productId,
            $matchingScopeReferenceIds,
            $isDiscounted,
            $unitPrice,
            $amount,
            $profit,
            $costRecorded,
        );
    }

    public function variationId(): string
    {
        return $this->variationId;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    /**
     * Carried for the Promotions path (PromotionValidator/
     * PromotionDiscountCalculator), not used by the SaleLine/profit
     * path — mirrors CartController::serializeCart()'s own
     * one-array-for-both-consumers approach rather than resolving the
     * same scope twice.
     */
    public function productId(): ?string
    {
        return $this->productId;
    }

    /**
     * Carried for the Promotions path, not used by the SaleLine/profit
     * path — mirrors CartController::serializeCart()'s own
     * one-array-for-both-consumers approach rather than resolving the
     * same scope twice.
     *
     * @return array<string, string[]>
     */
    public function matchingScopeReferenceIds(): array
    {
        return $this->matchingScopeReferenceIds;
    }

    /**
     * Carried for the Promotions path, not used by the SaleLine/profit
     * path — mirrors CartController::serializeCart()'s own
     * one-array-for-both-consumers approach rather than resolving the
     * same scope twice.
     */
    public function isDiscounted(): bool
    {
        return $this->isDiscounted;
    }

    public function unitPrice(): Money
    {
        return $this->unitPrice;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function profit(): Money
    {
        return $this->profit;
    }

    /**
     * False means profit() is a KNOWN DISTORTION, not a real number —
     * checkout-domain-design.md §9.3: this line's priceable had no
     * recorded ProductCost, so cost was treated as zero, showing
     * 100%-margin profit until the merchant fills it in. Exposed here
     * specifically so a future reporting layer can flag/exclude these
     * rather than silently trusting them.
     */
    public function costRecorded(): bool
    {
        return $this->costRecorded;
    }
}
