<?php

namespace EasyCo\Shipping\Rating;

use InvalidArgumentException;

/**
 * What the rate calculator needs to price an order's shipping, as plain values
 * (shipping stage 3b) — no Cart, Catalog, Pricing or Promotions types, so this
 * package depends on none of them.
 *
 *  - currency: the order's currency code, uppercase ISO 4217 shape; the
 *    returned prices are in it;
 *  - goodsAfterDiscountMinor: the shared CartPricing figure (stage 3.0c),
 *    i.e. subtotal - discount, the basis of the free-shipping threshold
 *    (shipping-domain-design.md §5.1). Zero is valid (a fully discounted
 *    cart), a negative number is a programmer error;
 *  - lines: one RateLine per cart line. An empty list is refused — there is no
 *    shipping to price for nothing.
 *
 * The names deliberately differ from the §6 provider value objects
 * (ShippingQuote, ShippingContext, …), which belong to stage 3c.
 */
final class RateRequest
{
    /** @param list<RateLine> $lines */
    public function __construct(
        public readonly string $currency,
        public readonly int $goodsAfterDiscountMinor,
        public readonly array $lines,
    ) {
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new InvalidArgumentException("RateRequest currency must be an uppercase 3-letter code, got \"{$currency}\".");
        }

        if ($goodsAfterDiscountMinor < 0) {
            throw new InvalidArgumentException("RateRequest goodsAfterDiscountMinor must not be negative, got {$goodsAfterDiscountMinor}.");
        }

        if ($lines === []) {
            throw new InvalidArgumentException('RateRequest needs at least one line; there is no shipping to price for an empty cart.');
        }

        foreach ($lines as $line) {
            if (! $line instanceof RateLine) {
                throw new InvalidArgumentException('RateRequest lines must all be RateLine instances.');
            }
        }
    }
}
