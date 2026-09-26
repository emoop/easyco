<?php

namespace EasyCo\Cart;

/**
 * A cart's claim, read back for a replay check — cart-domain-design.md §14.2.
 *
 * WHY THIS EXISTS INSTEAD OF REUSING THE Cart AGGREGATE: a claimed row has NULL
 * live identity by construction (§14.1), and Cart's own XOR invariant ("exactly
 * one of accountId/sessionToken", §2/§7) would reject such a row with an
 * InvalidArgumentException — surfacing as a 500. So the one thing that must read a
 * claimed row reads this value object instead, and `CartRepository::findById()`
 * returns null for a claimed cart, which makes "no aggregate is ever built from a
 * claim" a property of the repository rather than a rule callers must remember.
 *
 * It carries only what a replay answer needs: which cart was asked about, and the
 * order it produced. The claimed identity columns stay in the row, where the
 * repository's own query is the only thing that reads them — a caller cannot hold
 * this object and accidentally reveal whose it is.
 */
final readonly class CartClaim
{
    public function __construct(
        public string $cartId,
        public string $orderId,
    ) {
    }
}
