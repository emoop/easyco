<?php

namespace EasyCo\Pricing\Contracts;

use DateTimeImmutable;

/**
 * Everything PriceResolver::resolve() needs to pick the right price for one
 * priceable item — see pricing-domain-design.md §4.1.
 */
final class PriceContext
{
    public function __construct(
        public readonly string $priceableId,
        public readonly int $quantity,
        public readonly string $currency,
        public readonly ?string $customerGroupId = null,
        public readonly ?string $channelId = null,
        public readonly ?DateTimeImmutable $at = null,
    ) {
    }
}
