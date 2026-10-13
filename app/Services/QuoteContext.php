<?php

namespace App\Services;

use EasyCo\Cart\Cart;
use EasyCo\Shipping\Contracts\SettlementNameNormalizer;
use EasyCo\Shipping\Rating\RateLine;
use EasyCo\Shipping\ShippingZone;

/**
 * Everything about a quote REQUEST that does not depend on where the parcel goes (shipping stage 5g-2b, design 9.3.4): the cart,
 * its goods after discount, the rate lines (classes and quantities), the weight, the store's settlement normalizer and the
 * ordered zone list. It is the expensive part of a quote (cart pricing, the variations read), built ONCE per request by
 * ShippingQuoteService::context() and then reused for every bucket and kind of an `all` quote: no second cart pricing.
 */
final class QuoteContext
{
    /**
     * @param list<RateLine> $rateLines
     * @param list<ShippingZone> $zones in the matcher's order
     */
    public function __construct(
        public readonly Cart $cart,
        public readonly string $cartId,
        public readonly string $currency,
        public readonly int $goodsMinor,
        public readonly array $rateLines,
        public readonly ?int $weightGrams,
        public readonly SettlementNameNormalizer $normalizer,
        public readonly array $zones,
    ) {
    }
}
