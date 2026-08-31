<?php

namespace EasyCo\Cart;

use EasyCo\Cart\Contracts\CartRepository;
use EasyCo\Cart\Exceptions\InsufficientStockForCartException;
use EasyCo\Inventory\Contracts\StockLevelRepository;

/**
 * Orchestrates the one operation that genuinely needs both this
 * domain's own CartRepository and Inventory's published
 * StockLevelRepository contract in the same place: adding a line
 * while enforcing decision #7's soft, add-time-only stock check. See
 * cart-domain-design.md §3 for why depending on another domain's
 * Contracts/ interface (never its Eloquent implementation, never a
 * direct package instantiation) is treated as an allowed dependency
 * here — the same posture RestrictedPriceWriteGuard takes toward its
 * own domain's contracts, extended to a published contract belonging
 * to a different domain.
 *
 * Deliberately does NOT touch Pricing/Catalog. Building a PriceContext
 * needs Catalog data this package must never depend on (§1) — that
 * stays the app-layer controller's job; this class receives
 * $priceAtAddMinor/$priceAtAddCurrency already resolved, purely to
 * attach to the new CartLine for later display (§5).
 */
final class CartLineAdder
{
    public function __construct(
        private readonly CartRepository $carts,
        private readonly StockLevelRepository $stockLevels,
    ) {
    }

    /**
     * Adds $quantity of $variationId to $cart, merging into an
     * existing line for the same variation if one is present
     * (Cart::addLine()'s own invariant). The stock check is against
     * the RESULTING total for this variation (already-in-cart +
     * requested), not just the newly-requested amount — adding 3 more
     * of something you already have 8 of checks for 11, not 3.
     *
     * @throws InsufficientStockForCartException
     */
    public function addLine(
        Cart $cart,
        string $variationId,
        int $quantity,
        ?int $priceAtAddMinor,
        ?string $priceAtAddCurrency,
    ): void {
        $available = $this->stockLevels->findByVariationId($variationId)->quantity();

        $alreadyInCart = 0;
        foreach ($cart->lines() as $existingLine) {
            if ($existingLine->variationId() === $variationId) {
                $alreadyInCart = $existingLine->quantity();
                break;
            }
        }

        $totalRequested = $alreadyInCart + $quantity;

        if ($totalRequested > $available) {
            throw new InsufficientStockForCartException($variationId, $totalRequested, $available);
        }

        $cart->addLine(new CartLine(
            id: null,
            cartId: $cart->id() ?? '',
            variationId: $variationId,
            quantity: $quantity,
            priceAtAddMinor: $priceAtAddMinor,
            priceAtAddCurrency: $priceAtAddCurrency,
        ));

        $this->carts->save($cart);
    }
}
