<?php

namespace App\Services;

use EasyCo\Cart\Cart;
use EasyCo\Pricing\Exceptions\PriceNotConfiguredException;
use EasyCo\Pricing\Money;
use EasyCo\Promotions\Contracts\PromotionRepository;
use EasyCo\Promotions\Contracts\PromotionScopeRepository;

/**
 * THE ONE cart-totals calculation (shipping stage 3.0c, owner decision D6): the
 * subtotal, the promotion discount and the goods-after-discount of a Cart. The
 * cart preview (CartController) and checkout (CheckoutOrchestrator) both call it,
 * so the shipping threshold (stage 3b) and checkout's recompute-and-refuse
 * (stage 4) can never see two different numbers for the same cart.
 *
 * What it does, in this order — the order both callers always had:
 *  1. prices every line live (CheckoutLinePricer: the same PriceContext, the
 *     same final-gross unit price times quantity);
 *  2. sums the subtotal over the PRICED lines;
 *  3. if the cart holds a promotion code: loads it, assembles the usage context,
 *     validates it against the subtotal and the priced lines, and — only when it
 *     is valid — calculates the discount over the applicable lines.
 *
 * WHAT IT DELIBERATELY DOES NOT DO: it opens no transaction, takes no lock,
 * writes nothing and calls no external system, so checkout's Phase 1 stays
 * exactly as it was (the locks — stock decrease, promotion redemption — still
 * come after pricing, inside checkout's own transaction). It never throws for an
 * invalid promotion (that is a result the caller decides on) and throws for an
 * unpriced line only when the caller asks for UnpricedLines::REFUSE.
 *
 * The two intentional differences between the callers are therefore explicit
 * arguments and results, not hidden branches: $unpriced, $includeUnitCost, and
 * CartPricingResult::promotionRefusal().
 */
class CartPricing
{
    public function __construct(
        private readonly CheckoutLinePricer $linePricer,
        private readonly PromotionRepository $promotions,
        private readonly PromotionScopeRepository $promotionScopes,
        private readonly PromotionValidator $promotionValidator,
        private readonly PromotionDiscountCalculator $promotionDiscountCalculator,
        private readonly PromotionUsageContextAssembler $usageContextAssembler,
    ) {
    }

    /**
     * @param string|null $accountId  the customer the promotion's per-customer rules are checked for; null for a guest
     * @param string $currency        the cart's one currency code
     * @param bool $includeUnitCost   whether to look up each line's unit cost (a snapshot input only checkout needs;
     *                                false skips that query and leaves unitCost() null on every line)
     *
     * @throws PriceNotConfiguredException only with UnpricedLines::REFUSE, at the first unpriced line
     */
    public function price(
        Cart $cart,
        ?string $accountId,
        string $currency,
        UnpricedLines $unpriced,
        bool $includeUnitCost,
    ): CartPricingResult {
        $subtotal = Money::zero($currency);
        $lineResults = [];
        $unpricedVariationIds = [];

        foreach ($cart->lines() as $line) {
            try {
                $result = $this->linePricer->priceLine($line->variationId(), $line->quantity(), $currency, $includeUnitCost);
            } catch (PriceNotConfiguredException $exception) {
                if ($unpriced === UnpricedLines::REFUSE) {
                    throw $exception;
                }

                // Left out of the subtotal entirely: counting it as 0 would
                // silently understate what the customer owes.
                $lineResults[] = null;
                $unpricedVariationIds[] = $line->variationId();

                continue;
            }

            $lineResults[] = $result;
            $subtotal = $subtotal->add($result->amount());
        }

        $priced = array_values(array_filter($lineResults, static fn (?CheckoutLinePricingResult $r): bool => $r !== null));

        return $this->withPromotion($cart->appliedPromotionCode(), $accountId, $subtotal, $lineResults, $unpricedVariationIds, $priced);
    }

    /**
     * @param array<int, ?CheckoutLinePricingResult> $lineResults
     * @param string[] $unpricedVariationIds
     * @param array<int, CheckoutLinePricingResult> $priced
     */
    private function withPromotion(
        ?string $code,
        ?string $accountId,
        Money $subtotal,
        array $lineResults,
        array $unpricedVariationIds,
        array $priced,
    ): CartPricingResult {
        $zeroShares = array_fill(0, count($priced), Money::zero($subtotal->currency()));

        if ($code === null) {
            return new CartPricingResult($lineResults, $unpricedVariationIds, $subtotal, null, null, null, null, [], $zeroShares);
        }

        $promotion = $this->promotions->findByCode($code);

        if ($promotion === null) {
            // Deleted after it was applied: a refusal, not an exception.
            return new CartPricingResult($lineResults, $unpricedVariationIds, $subtotal, $code, null, 'not_found', null, [], $zeroShares);
        }

        $scopes = $this->promotionScopes->findByPromotionId($promotion->id());

        // Per-setting query guards live in PromotionUsageContextAssembler.
        $usage = $this->usageContextAssembler->assemble($promotion, $accountId);

        $validatorLines = array_map(static fn (CheckoutLinePricingResult $result): array => [
            'variationId' => $result->variationId(),
            'quantity' => $result->quantity(),
            'unitPrice' => $result->unitPrice(),
            'lineTotal' => $result->amount(),
            'productId' => $result->productId(),
            'matchingScopeReferenceIds' => $result->matchingScopeReferenceIds(),
            'isDiscounted' => $result->isDiscounted(),
        ], $priced);

        $validation = $this->promotionValidator->validate($promotion, $scopes, $subtotal, $accountId, $validatorLines, $usage);

        if (! $validation->isValid()) {
            return new CartPricingResult($lineResults, $unpricedVariationIds, $subtotal, $code, null, $validation->reason(), null, [], $zeroShares);
        }

        $applicableIds = array_flip($validation->applicableVariationIds());

        // array_filter() alone keeps the original keys, so the applicable lines'
        // keys ARE their indexes among the priced lines — which is how the
        // calculator's positional per-line shares are mapped back onto every
        // priced line (zero for the non-applicable ones).
        $applicableLines = array_filter(
            $validatorLines,
            static fn (array $line): bool => isset($applicableIds[$line['variationId']])
        );

        $discountResult = $this->promotionDiscountCalculator->calculate($promotion, array_values($applicableLines));

        $sharesByIndex = array_combine(array_keys($applicableLines), $discountResult->perLineShares());

        $perLineShares = [];
        foreach ($validatorLines as $index => $line) {
            $perLineShares[$index] = $sharesByIndex[$index] ?? Money::zero($subtotal->currency());
        }

        return new CartPricingResult(
            $lineResults,
            $unpricedVariationIds,
            $subtotal,
            $code,
            $promotion,
            null,
            $discountResult,
            $validation->applicableVariationIds(),
            $perLineShares,
        );
    }
}
