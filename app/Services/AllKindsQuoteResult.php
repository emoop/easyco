<?php

namespace App\Services;

/**
 * The answer to an `all` quote (shipping stage 5g-2b, design 9.3.4): one final list of method quotes PER KIND of destination
 * (address, office, locker; a kind is absent when its bucket matched no zone), each from that kind's own pricing hash, so each
 * handle binds the kind it was quoted for. A PREVIEW (no settlement) carries no handle at all.
 */
final class AllKindsQuoteResult
{
    public const KINDS = ['address', 'office', 'locker'];

    /**
     * @param array<string, array{id: string, name: string}|null> $zones 'address' | 'pickup' => the matched zone, or null
     * @param array<string, ShippingQuoteResult> $kinds 'address' | 'office' | 'locker' => that kind's final quotes
     */
    public function __construct(
        public readonly string $cartId,
        public readonly string $currency,
        public readonly int $goodsAfterDiscountMinor,
        public readonly bool $preview,
        public readonly array $zones,
        public readonly array $kinds,
        public readonly ?FreeShippingHint $freeShippingHint = null,
    ) {
    }

    /** @return array{id: string, name: string} the first matched bucket's zone: the address one, else the pickup one */
    public function firstZone(): array
    {
        return $this->zones['address'] ?? $this->zones['pickup'] ?? ['id' => '', 'name' => ''];
    }

    /**
     * The methods in order of FIRST APPEARANCE (the address kind's list first, then the pickup kinds' methods not yet listed), each
     * with its quote of every kind that lists it AND that it serves.
     *
     * @return list<array{id: string, first: MethodQuote, kinds: array<string, MethodQuote>}>
     */
    public function methods(): array
    {
        $byId = [];

        foreach (self::KINDS as $kind) {
            foreach (($this->kinds[$kind] ?? null)?->methods ?? [] as $quote) {
                if ($quote->servesDestination === false) {
                    continue; // listed for the merchant filter's sake, but it does not serve this kind: not an option
                }

                $byId[$quote->methodId] ??= ['id' => $quote->methodId, 'first' => $quote, 'kinds' => []];
                $byId[$quote->methodId]['kinds'][$kind] = $quote;
            }
        }

        return array_values($byId);
    }
}
