<?php

namespace EasyCo\Pricing\Persistence;

use EasyCo\Pricing\Contracts\PriceContext;
use EasyCo\Pricing\Contracts\PriceQuote;
use EasyCo\Pricing\Contracts\PriceResolver;
use EasyCo\Pricing\Price;
use OutOfBoundsException;

/**
 * TEMPORARY STAND-IN — NOT A REAL IMPLEMENTATION.
 *
 * This exists only to prove the Catalog<->Pricing integration contract
 * (Variation::priceableId() feeding into PriceResolver::resolve()) works
 * end-to-end, before any real PriceList/PriceListItem/PriceRule persistence
 * exists (see pricing-domain-design.md §2-3, none of which is implemented
 * yet). Prices are hardcoded/seeded in memory at construction time and
 * looked up by priceableId only — quantity tiers, customer group, channel,
 * currency and scheduled validity from PriceContext are all ignored, and
 * there is no discount source, so regular and final are always the same
 * seeded Price. Replace with a real PriceList-backed resolver before this
 * is used for anything beyond wiring verification.
 */
final class InMemoryPriceResolver implements PriceResolver
{
    /** @param array<string, Price> $pricesByPriceableId */
    public function __construct(
        private readonly array $pricesByPriceableId,
    ) {
    }

    public function resolve(PriceContext $context): PriceQuote
    {
        $price = $this->pricesByPriceableId[$context->priceableId] ?? null;

        if ($price === null) {
            throw new OutOfBoundsException(
                "InMemoryPriceResolver has no seeded price for priceableId \"{$context->priceableId}\"."
            );
        }

        return new PriceQuote(regular: $price, final: $price);
    }
}
