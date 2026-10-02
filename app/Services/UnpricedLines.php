<?php

namespace App\Services;

/**
 * What CartPricing does with a cart line that has no price. The two callers
 * genuinely differ here (shipping stage 3.0c, owner decision D6), so the
 * difference is an explicit argument, never a branch hidden in the service:
 *
 *  - SKIP: the line is left out of the subtotal and reported as unpriced. The
 *    cart preview uses this — one removed price must not take the whole cart
 *    response down, and treating the line as 0 would understate the total
 *    (cart-domain-design.md §12).
 *  - REFUSE: PriceNotConfiguredException propagates at the first unpriced line,
 *    exactly as the resolver threw it. Checkout uses this — nothing is sold
 *    without a price, and the exception aborts Phase 1 before any write.
 */
enum UnpricedLines
{
    case SKIP;
    case REFUSE;
}
