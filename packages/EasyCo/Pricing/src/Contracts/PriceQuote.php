<?php

namespace EasyCo\Pricing\Contracts;

use EasyCo\Pricing\Price;

/**
 * Regular price = what the Price List says before any rule/special-price
 * adjustment. Final price = the resolved price the customer actually pays.
 * When they're equal, the item simply isn't discounted right now — see
 * pricing-domain-design.md §4.1.
 */
final class PriceQuote
{
    public function __construct(
        public readonly Price $regular,
        public readonly Price $final,
    ) {
    }

    public function isDiscounted(): bool
    {
        return ! $this->final->gross()->equals($this->regular->gross());
    }
}
