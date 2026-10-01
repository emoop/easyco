<?php

namespace App\Services;

use App\Services\Exceptions\OrderAddLineRefusedException;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\VariationRepository;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Pricing\Currency;
use EasyCo\Pricing\Exceptions\PriceNotConfiguredException;
use EasyCo\Pricing\Money;
use InvalidArgumentException;

/**
 * The one place the order edit dialog turns a picked variation plus a
 * quantity into the ADD entry OrderLineEditor::apply() writes — stage
 * 4b-ii, order-editing-design.md §1/E2 ("add a product/variation with a
 * quantity").
 *
 * THE PRICE IS ALWAYS RESOLVED LIVE, NEVER TYPED IN: this class calls
 * App\Services\CheckoutLinePricer::priceLine() — the SAME service
 * CheckoutOrchestrator uses for a storefront line — so the added line's
 * regularUnitPrice/finalUnitPrice/productName/sku/unitCost come from the
 * exact formula and the exact read a real sale uses (CatalogScopeResolver
 * → PriceResolver::resolve(PriceContext) → CostPriceProvider), and the
 * merchant never has a price field to fill in, per §1's own "never a free
 * price override" decision. CheckoutOrchestrator then feeds that same
 * result into the SAME builder this entry's shape mirrors:
 * SaleLineSnapshotBuilder::buildForCart()'s `lines` array, keyed exactly
 * as OrderLineEditor::apply()'s own "add" entry documents it (variationId,
 * quantity, regularUnitPrice, finalUnitPrice, unitCost, productName, sku,
 * promotionDiscountShare, discretionaryDiscount).
 *
 * DISCRETIONARY DISCOUNT IS ALWAYS ZERO HERE: E2 keeps adding a line and
 * discounting a line as two separate intents — a manual discount is applied
 * in a LATER edit, once the new line is an ordinary current line on the
 * order. The alternative (a discount field on the add form) would need
 * OrderLineEditor's own discount-above-regular-total invariant surfaced
 * before the line even exists.
 *
 * THE PROMOTION SHARE IS A PLACEHOLDER ZERO, DELIBERATELY: OrderEditor
 * plans the promotion over the lines the edit will PRODUCE and overwrites
 * every "add" entry's own promotionDiscountShare before the lines are
 * written (`$changes[$position]['pricedLine']['promotionDiscountShare'] =
 * $addShares[$addPosition++]` — see its own promotion-planning comment).
 * Passing a hand-computed share here would be a second, diverging
 * implementation of §7's redistribution.
 *
 * NOT SELLABLE IS REFUSED, NOT PRICED: the variation is re-read from
 * Catalog and gated by the domain's own Variation::isEffectivelyPurchasable()
 * plus its product's ProductStatus::ACTIVE — the same two facts
 * OrderLineProductSearch filters on — so a crafted or stale submission
 * naming an archived product can never become a line, whatever the picker
 * happened to show.
 *
 * THE QUANTITY HAS NO STOCK CEILING HERE, AND MUST NOT: the real check is
 * StockLevelRepository's own InsufficientStockException, raised inside
 * OrderLineEditor::apply()'s stock step before any line is written. A
 * second, guessed ceiling in the panel would be a duplicate rule able to
 * disagree with the one that actually refuses.
 */
final class OrderAddLinePricer
{
    public function __construct(
        private readonly VariationRepository $variations,
        private readonly ProductRepository $products,
        private readonly CheckoutLinePricer $linePricer,
    ) {}

    /**
     * The ADD change entry for one picked variation, priced live.
     *
     * @return array{change: 'add', pricedLine: array<string, mixed>}
     *
     * @throws OrderAddLineRefusedException If the variation no longer
     *                                      exists, is not effectively purchasable, or its product is not
     *                                      active — nothing has been written at this point.
     * @throws PriceNotConfiguredException If
     *                                     the variation has no price in the order's own currency; the
     *                                     dialog renders that as its own translated refusal.
     */
    public function pricedChange(string $variationId, int $quantity, Currency|string $currency): array
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException(
                "OrderAddLinePricer: the quantity to add must be at least 1, got {$quantity}."
            );
        }

        $currency = $currency instanceof Currency ? $currency : Currency::of($currency);

        $variation = $this->variations->findById($variationId);

        if ($variation === null) {
            throw OrderAddLineRefusedException::variationNotFound($variationId);
        }

        if (! $variation->isEffectivelyPurchasable()) {
            throw OrderAddLineRefusedException::variationNotSellable($variationId);
        }

        $product = $this->products->findById((string) $variation->productId());

        if ($product === null || $product->status() !== ProductStatus::ACTIVE) {
            throw OrderAddLineRefusedException::productNotSellable($variationId);
        }

        $result = $this->linePricer->priceLine($variationId, $quantity, $currency->code());

        return [
            'change' => 'add',
            'pricedLine' => [
                'variationId' => $variationId,
                'quantity' => $quantity,
                'regularUnitPrice' => $result->regularUnitPrice(),
                'finalUnitPrice' => $result->unitPrice(),
                'unitCost' => $result->unitCost(),
                // The pricing result's own §3.13 snapshot fields are the
                // authority here — exactly the pair CheckoutOrchestrator
                // passes into SaleLineSnapshotBuilder. The aggregate's own
                // name/sku are the fallback for the one case the scope
                // resolver cannot answer (it returns a null-shape when its
                // own product read finds no row), and BOTH are non-empty in
                // every reachable case — SaleLine::create()'s own Tier B
                // invariant remains the final backstop.
                'productName' => $result->productName() ?? $product->name(),
                'sku' => $result->sku() ?? $variation->sku(),
                'promotionDiscountShare' => Money::zero($currency),
                'discretionaryDiscount' => Money::zero($currency),
            ],
        ];
    }
}
