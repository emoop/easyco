<?php

namespace EasyCo\Shipping\Rating;

use LogicException;

/**
 * The calculator's answer for ONE shipping method: either a price in minor
 * units of the request's currency, or "needs a carrier quote" for a CARRIER
 * method, whose price is never computed locally (§5, §6). A CARRIER method is
 * never given a price here, not even 0.
 */
final class MethodRate
{
    private function __construct(
        public readonly string $methodId,
        public readonly string $currency,
        private readonly ?int $amountMinor,
        public readonly ?string $carrierCode,
    ) {
    }

    public static function priced(string $methodId, string $currency, int $amountMinor): self
    {
        return new self($methodId, $currency, $amountMinor, null);
    }

    public static function needsCarrierQuote(string $methodId, string $currency, string $carrierCode): self
    {
        return new self($methodId, $currency, null, $carrierCode);
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
