<?php

namespace App\Storefront\ReadModels;

/**
 * The customer-facing price facts of a product or variation, in MINOR units of `currency` (gross, as the
 * customer pays: the same figure ProductPriceDisplay shows).
 *
 * - from_minor           the lowest final price.
 * - to_minor             the highest final price (equal to from_minor when every price is the same). Cards and
 *                        product pages are priced by the same code from the SHOWN variations (S1b).
 * - regular_from_minor   the regular price of the cheapest-final quote, ONLY when that quote is discounted;
 *                        null otherwise.
 */
final readonly class PriceBlock
{
    public function __construct(
        public int $fromMinor,
        public int $toMinor,
        public string $currency,
        public ?int $regularFromMinor,
    ) {
    }

    /** @return array{from_minor: int, to_minor: int, currency: string, regular_from_minor: ?int} */
    public function toArray(): array
    {
        return [
            'from_minor' => $this->fromMinor,
            'to_minor' => $this->toMinor,
            'currency' => $this->currency,
            'regular_from_minor' => $this->regularFromMinor,
        ];
    }
}
