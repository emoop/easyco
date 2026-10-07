<?php

namespace EasyCo\Shipping\Rating;

use InvalidArgumentException;
use LogicException;

/**
 * The calculator's answer for ONE shipping method: either a price in minor
 * units of the request's currency, or "needs a carrier quote" for a CARRIER
 * method, whose price is never computed locally (§5, §6). A CARRIER method is
 * never given a price here, not even 0.
 *
 * A priced method ALSO carries its free-shipping threshold facts (shipping stage
 * 3e, §5.1), so the API can tell the customer how much more unlocks free
 * shipping on the same basis the price was computed on:
 *  - freeAboveMinor: the method's threshold, for a FLAT or PER_CLASS method that
 *    has one; null when the method has no threshold, and null for FREE (free by
 *    kind, no threshold possible) and CARRIER (the threshold is deliberately not
 *    applied to a live quote, §5.2).
 *  - remainingToFreeMinor: freeAboveMinor - goodsAfterDiscountMinor while the
 *    goods are BELOW the threshold, and 0 once the threshold is met ("99.99
 *    against 100.00 needs 0.01, 100.00 and 100.01 are free"). null whenever
 *    freeAboveMinor is null.
 * Both are null together or set together — a threshold fact is never half-known.
 * They are INFORMATION: they never change the price and are never part of what a
 * quote handle binds.
 */
final class MethodRate
{
    private function __construct(
        public readonly string $methodId,
        public readonly string $currency,
        private readonly ?int $amountMinor,
        public readonly ?string $carrierCode,
        public readonly ?int $freeAboveMinor,
        public readonly ?int $remainingToFreeMinor,
    ) {
        if (($freeAboveMinor === null) !== ($remainingToFreeMinor === null)) {
            throw new InvalidArgumentException('MethodRate freeAboveMinor and remainingToFreeMinor must be set together or both null.');
        }

        if ($remainingToFreeMinor !== null && $remainingToFreeMinor < 0) {
            throw new InvalidArgumentException("MethodRate remainingToFreeMinor must not be negative, got {$remainingToFreeMinor}.");
        }
    }

    public static function priced(string $methodId, string $currency, int $amountMinor, ?int $freeAboveMinor = null, ?int $remainingToFreeMinor = null): self
    {
        return new self($methodId, $currency, $amountMinor, null, $freeAboveMinor, $remainingToFreeMinor);
    }

    public static function needsCarrierQuote(string $methodId, string $currency, string $carrierCode): self
    {
        return new self($methodId, $currency, null, $carrierCode, null, null);
    }

    /** True for a CARRIER method: the price must be asked of the carrier (stage 3c). */
    public function needsQuote(): bool
    {
        return $this->amountMinor === null;
    }

    /** @throws LogicException for a method that needs a carrier quote — check needsQuote() first */
    public function amountMinor(): int
    {
        return $this->amountMinor ?? throw new LogicException("Method {$this->methodId} needs a carrier quote; it has no locally computed price.");
    }
}
