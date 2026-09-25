<?php

namespace App\Services;

use EasyCo\Pricing\Money;

/**
 * The outcome of CheckoutLinePricer::priceLine() — everything Checkout
 * orchestration needs, per line, to eventually build a SaleLine
 * (checkout-domain-design.md §8.3 step 8) and contribute to Order's
 * subtotal (§8.3 step 4).
 *
 * NO profit()/costRecorded() HERE — removed in operational-sales-domain-
 * design.md §3.13 stage 4a's own review: this class's pre-promotion
 * profit (amount - unitCost x quantity) became dead the moment
 * App\Services\SaleLineSnapshotBuilder started computing the real,
 * post-promotion profit (D4 — on net) as the ONE place profit is
 * computed for a checkout SALE line. Confirmed via a full-repo grep
 * before removing: no production code read either accessor (only this
 * class's own now-updated test did). unitCost() alone is what
 * SaleLineSnapshotBuilder actually needs; costRecorded() was always
 * exactly unitCost() !== null, so it added no information of its own.
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
        private readonly Money $regularUnitPrice,
        private readonly Money $unitPrice,
        private readonly Money $amount,
        private readonly ?Money $unitCost,
        private readonly ?string $productName,
        private readonly ?string $sku,
    ) {
    }

    /** @param array<string, string[]> $matchingScopeReferenceIds */
    public static function create(
        string $variationId,
        int $quantity,
        ?string $productId,
        array $matchingScopeReferenceIds,
        bool $isDiscounted,
        Money $regularUnitPrice,
        Money $unitPrice,
        Money $amount,
        ?Money $unitCost,
        ?string $productName,
        ?string $sku,
    ): self {
        return new self(
            $variationId,
            $quantity,
            $productId,
            $matchingScopeReferenceIds,
            $isDiscounted,
            $regularUnitPrice,
            $unitPrice,
            $amount,
            $unitCost,
            $productName,
            $sku,
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

    /**
     * D2 (operational-sales-domain-design.md §3.13 stage 4a) — level 2's
     * regular component, per unit, as the store showed it at the moment
     * of sale. Discarded before this stage; carried now so
     * SaleLineSnapshotBuilder can pass it straight into
     * SaleLine::create()'s regularUnitPrice without re-resolving the
     * PriceQuote a second time.
     */
    public function regularUnitPrice(): Money
    {
        return $this->regularUnitPrice;
    }

    /**
     * Level 2's final component, per unit — unchanged meaning, this is
     * "today's" unitPrice §0 already documented.
     */
    public function unitPrice(): Money
    {
        return $this->unitPrice;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    /**
     * The raw cost value itself — null means genuinely unknown
     * (checkout-domain-design.md §9.3 / operational-sales-domain-
     * design.md §3.13 Q2), never treated as zero. Carried for
     * SaleLineSnapshotBuilder to snapshot onto SaleLine::create()'s
     * unitCost, and for it alone to compute profit — see this class's
     * own docblock for why no profit/costRecorded accessor lives here
     * any more.
     */
    public function unitCost(): ?Money
    {
        return $this->unitCost;
    }

    /**
     * Snapshotted for CheckoutOrchestrator to pass into a SALE-type
     * SaleLine — see operational-sales-domain-design.md §3.12.
     */
    public function productName(): ?string
    {
        return $this->productName;
    }

    public function sku(): ?string
    {
        return $this->sku;
    }
}
