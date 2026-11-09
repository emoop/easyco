<?php

namespace App\Services;

use EasyCo\Pricing\Contracts\CostPriceProvider;
use EasyCo\Pricing\Contracts\PriceContext;
use EasyCo\Pricing\Contracts\PriceRangeResolver;
use EasyCo\Pricing\Contracts\PriceResolver;
use EasyCo\Pricing\Exceptions\PriceNotConfiguredException;

/**
 * Resolves the live price for one checkout line, per checkout-domain-
 * design.md §8.3 step 3 / §9.3.
 *
 * priceableId === variationId directly — no separate Variation lookup
 * needed here, per Variation's own documented identity
 * ("priceableId() == its own id"). CatalogScopeResolver still needs the
 * Variation internally for scope matching, hence that dependency.
 *
 * NO PROFIT COMPUTED HERE ANY MORE — operational-sales-domain-design.md
 * §3.13 stage 4a's own review removed it: this class used to compute a
 * pre-promotion profit (amount - unitCost x quantity, itself scaled by
 * quantity to fix a real bug — see this task's own commit history), but
 * that figure became dead the moment SaleLineSnapshotBuilder started
 * computing the real, post-promotion profit as the ONE place it's
 * computed for a checkout SALE line. This class now only resolves the
 * raw unitCost (null = genuinely unknown, §9.3) and hands it through.
 *
 * $scope/$quote ALSO CARRY productId/matchingScopeReferenceIds/
 * isDiscounted THROUGH TO THE RESULT — not used by this class's own
 * price/profit math, but the Promotions path (PromotionValidator/
 * PromotionDiscountCalculator) needs exactly these per line, same
 * "one array, extended rather than recomputed twice" pattern
 * CartController::serializeCart() already established. Without this,
 * a Checkout orchestrator would have to call
 * CatalogScopeResolver::forVariation() a second time per line.
 */
class CheckoutLinePricer
{
    public function __construct(
        private readonly PriceResolver $priceResolver,
        private readonly CatalogScopeResolver $catalogScopeResolver,
        private readonly CostPriceProvider $costPriceProvider,
        private readonly PriceRangeResolver $priceRangeResolver,
    ) {
    }

    /**
     * P5: the SAME result as priceLine() for EVERY line of a cart, read in a few GROUPED queries (CatalogScopeResolver::forVariations() + PriceRangeResolver::resolveQuotes()), so the number of queries does
     * not grow with the number of lines. Returns one entry per input line, in the same order and under the same keys: the CheckoutLinePricingResult, or — for a line whose price cannot be resolved — the
     * PriceNotConfiguredException that priceLine() would have thrown (NOT thrown here: the caller decides, exactly as CartPricing always did per line).
     *
     * The batch resolver OMITS a target it cannot price, which is also what it does when the "Regular Prices" system list was never seeded; priceLine() throws a RuntimeException for that case, which must
     * stay loud. So when NOTHING could be priced, the first line is priced the old way (it throws the RuntimeException, or tells that the list exists and the lines are genuinely unpriced). A cart with the same
     * variation on two lines (the Cart aggregate never makes one) is priced line by line as before.
     *
     * Unit costs (a snapshot input only checkout asks for) are still read per line: that read is not part of the cart preview.
     *
     * @param array<int, array{variationId: string, quantity: int}> $lines
     * @return array<int, CheckoutLinePricingResult|PriceNotConfiguredException>
     *
     * @throws \RuntimeException when the reserved "Regular Prices" list is missing (as priceLine() does)
     */
    public function priceLines(array $lines, string $currency, bool $includeUnitCost = true): array
    {
        if ($lines === []) {
            return [];
        }

        $ids = array_map(static fn (array $line): string => $line['variationId'], $lines);

        if (count($ids) !== count(array_unique($ids))) {
            return $this->priceOneByOne($lines, $currency, $includeUnitCost);
        }

        $scopes = $this->catalogScopeResolver->forVariations(array_values($ids));
        $nullShape = ['productId' => null, 'matchingScopeReferenceIds' => [], 'productName' => null, 'sku' => null];
        $contexts = [];

        foreach ($lines as $index => $line) {
            $scope = $scopes[$line['variationId']] ?? $nullShape;
            $contexts[$index] = new PriceContext(
                priceableId: $line['variationId'],
                quantity: $line['quantity'],
                currency: $currency,
                productId: $scope['productId'],
                matchingScopeReferenceIds: $scope['matchingScopeReferenceIds'],
            );
        }

        $quotes = $this->priceRangeResolver->resolveQuotes(array_values($contexts));

        if ($quotes === []) {
            // nothing could be priced: the old single-line path tells whether the list is missing (it throws) or the lines are genuinely unpriced
            $firstKey = array_key_first($lines);
            $out = [];

            try {
                $out[$firstKey] = $this->priceLine($lines[$firstKey]['variationId'], $lines[$firstKey]['quantity'], $currency, $includeUnitCost);
            } catch (PriceNotConfiguredException $exception) {
                $out[$firstKey] = $exception;
            }

            foreach ($lines as $index => $line) {
                if ($index === $firstKey) {
                    continue;
                }

                $out[$index] = $out[$firstKey] instanceof PriceNotConfiguredException
                    ? PriceNotConfiguredException::forPriceableId($line['variationId'])
                    : $this->priceLine($line['variationId'], $line['quantity'], $currency, $includeUnitCost);
            }

            ksort($out);

            return $out;
        }

        $out = [];

        foreach ($lines as $index => $line) {
            $quote = $quotes[$line['variationId']] ?? null;

            if ($quote === null) {
                $out[$index] = PriceNotConfiguredException::forPriceableId($line['variationId']);

                continue;
            }

            $scope = $scopes[$line['variationId']] ?? $nullShape;
            $unitPrice = $quote->final->gross();

            $out[$index] = CheckoutLinePricingResult::create(
                variationId: $line['variationId'],
                quantity: $line['quantity'],
                productId: $scope['productId'],
                matchingScopeReferenceIds: $scope['matchingScopeReferenceIds'],
                isDiscounted: $quote->isDiscounted(),
                regularUnitPrice: $quote->regular->gross(),
                unitPrice: $unitPrice,
                amount: $unitPrice->multiply($line['quantity']),
                unitCost: $includeUnitCost ? $this->costPriceProvider->costFor($line['variationId'], $currency) : null,
                productName: $scope['productName'],
                sku: $scope['sku'],
            );
        }

        return $out;
    }

    /**
     * @param array<int, array{variationId: string, quantity: int}> $lines
     * @return array<int, CheckoutLinePricingResult|PriceNotConfiguredException>
     */
    private function priceOneByOne(array $lines, string $currency, bool $includeUnitCost): array
    {
        $out = [];

        foreach ($lines as $index => $line) {
            try {
                $out[$index] = $this->priceLine($line['variationId'], $line['quantity'], $currency, $includeUnitCost);
            } catch (PriceNotConfiguredException $exception) {
                $out[$index] = $exception;
            }
        }

        return $out;
    }

    /**
     * @throws PriceNotConfiguredException Same exception Cart's own
     *   add-time resolution can throw; the caller (future Checkout
     *   orchestration) lets this abort the whole transaction cleanly,
     *   per §8.3 step 3 — nothing partial is ever committed.
     */
    public function priceLine(string $variationId, int $quantity, string $currency, bool $includeUnitCost = true): CheckoutLinePricingResult
    {
        $scope = $this->catalogScopeResolver->forVariation($variationId);

        $quote = $this->priceResolver->resolve(new PriceContext(
            priceableId: $variationId,
            quantity: $quantity,
            currency: $currency,
            productId: $scope['productId'],
            matchingScopeReferenceIds: $scope['matchingScopeReferenceIds'],
        ));

        $regularUnitPrice = $quote->regular->gross();
        $unitPrice = $quote->final->gross();
        $amount = $unitPrice->multiply($quantity);

        // Raw value only — null means genuinely unknown (§9.3). Profit is
        // no longer computed here at all: SaleLineSnapshotBuilder is now
        // the ONE place that computes it, on net, after the promotion
        // share (operational-sales-domain-design.md §3.13 stage 4a).
        // $includeUnitCost false (the cart preview, via CartPricing) skips the lookup and
        // leaves unitCost null; only a snapshot (checkout) reads it.
        $unitCost = $includeUnitCost ? $this->costPriceProvider->costFor($variationId, $currency) : null;

        return CheckoutLinePricingResult::create(
            variationId: $variationId,
            quantity: $quantity,
            productId: $scope['productId'],
            matchingScopeReferenceIds: $scope['matchingScopeReferenceIds'],
            isDiscounted: $quote->isDiscounted(),
            regularUnitPrice: $regularUnitPrice,
            unitPrice: $unitPrice,
            amount: $amount,
            unitCost: $unitCost,
            productName: $scope['productName'],
            sku: $scope['sku'],
        );
    }
}
