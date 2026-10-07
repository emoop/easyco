<?php

namespace App\Services;

/**
 * The methods the quote service OFFERS for a cart and destination, before any
 * handle is issued (shipping-domain-design.md §6.7). This is the seam: the list
 * is complete and priced, and the stage 3d part 2 merchant filter will transform
 * it HERE, after which issueHandles() issues a handle for each price that is
 * finally offered — so a handle never names a price the filter then changed.
 *
 * pricingHash is the hash of everything that priced these offers (destination as
 * normalized, matched zone, goods after discount, the cart's lines and quantities,
 * the promotion code); every handle of this quote is bound to it.
 */
final class QuoteOffers
{
    /**
     * @param list<MethodQuote> $methods
     * @param FreeShippingHint|null $freeShippingHint the zone's "add X more" fact
     *        (stage 3e, §5.1), or null when nothing applies; carried through the
     *        merchant filter untouched
     */
    public function __construct(
        public readonly string $cartId,
        public readonly string $currency,
        public readonly int $goodsAfterDiscountMinor,
        public readonly string $zoneId,
        public readonly string $zoneName,
        public readonly string $pricingHash,
        public readonly array $methods,
        public readonly ?FreeShippingHint $freeShippingHint = null,
    ) {
    }

    /** @param list<MethodQuote> $methods */
    public function withMethods(array $methods): self
    {
        return new self($this->cartId, $this->currency, $this->goodsAfterDiscountMinor, $this->zoneId, $this->zoneName, $this->pricingHash, $methods, $this->freeShippingHint);
    }
}
