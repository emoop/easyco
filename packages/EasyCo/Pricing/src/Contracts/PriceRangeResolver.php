<?php

namespace EasyCo\Pricing\Contracts;

/**
 * Batched sibling of PriceResolver — resolves a PriceQuote for many
 * priceable targets in one call instead of one PriceContext at a time.
 * Its intended pairing is EasyCo\Pricing\PriceRange::fromQuotes():
 * PriceRange::fromQuotes($rangeResolver->resolveQuotes($contexts)).
 *
 * OMISSION IS DELIBERATE, A DIVERGENCE FROM PriceResolver::resolve()'s
 * FAIL-LOUD SINGLE-TARGET CONTRACT: a listing rendering many targets at
 * once must not explode because ONE of them happens to be unpriced — a
 * context whose price cannot be resolved (no regular item configured for
 * it) is simply left out of the returned array, never thrown for. This
 * mirrors the "fail-soft at the display boundary" posture already
 * documented on App\Services\ProductPricingAndStock's own class
 * docblock for reads.
 *
 * A missing "Regular Prices" system list (never seeded) returns an EMPTY
 * array here — not a RuntimeException like PriceResolver::resolve()
 * throws for the same condition. A range/listing view has nothing
 * sensible to show with no pricing configured at all; the fail-loud
 * signal for that genuine setup error still exists (PriceResolver::
 * resolve() itself, and anything that already depends on it).
 *
 * BOUNDEDNESS CONTRACT: an implementation's query count must not grow
 * with the number of contexts, products, or variations in $contexts. It
 * may grow only with (a) the number of distinct `at` values present in
 * the batch and (b) the number of distinct WINNING PriceLists actually
 * matched across the batch — bounded in practice by the store's
 * configured PriceList count, typically a handful. See
 * EloquentPriceRangeResolver's own docblock for how this is achieved.
 */
interface PriceRangeResolver
{
    /**
     * @param PriceContext[] $contexts one context per priceable target
     * @return array<string, PriceQuote> keyed by priceableId; a context
     *   whose price cannot be resolved is OMITTED, never thrown for.
     */
    public function resolveQuotes(array $contexts): array;
}
