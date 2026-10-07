<?php

namespace App\Services;

/**
 * One shipping method of the matched zone, as the quote service offers it: its
 * identity and what the customer sees, and EITHER an amount OR "unavailable" with
 * a reason. The handle (QuoteHandleStore) is set in a separate, last step, only for
 * a method that has an amount.
 *
 * unavailableReason is a CarrierUnavailableReason value (provider_error, timed_out,
 * invalid_response, not_configured) or one of this service's own:
 * `no_quote` (the carrier answered and offers nothing for this parcel) and
 * `no_settlement` (a carrier cannot quote a destination with no settlement).
 *
 * serviceCode: for a CARRIER method, the service of the carrier's CHEAPEST quote
 * (a method names a carrier, not a service — §6.7); null for a local method.
 *
 * freeAboveMinor / remainingToFreeMinor: the same threshold facts MethodRate
 * carries (shipping stage 3e, §5.1), for the API to report. null when the method
 * has no threshold, and null for an unavailable (carrier) method. INFORMATION
 * only — never part of what a handle binds.
 */
final class MethodQuote
{
    public const NO_QUOTE = 'no_quote';

    public const NO_SETTLEMENT = 'no_settlement';

    public function __construct(
        public readonly string $methodId,
        public readonly string $name,
        public readonly string $kind,
        public readonly bool $requiresPickupPoint,
        public readonly string $currency,
        public readonly ?int $amountMinor,
        public readonly ?string $unavailableReason = null,
        public readonly ?string $serviceCode = null,
        public readonly ?string $handle = null,
        public readonly ?int $freeAboveMinor = null,
        public readonly ?int $remainingToFreeMinor = null,
    ) {
    }

    public static function priced(string $methodId, string $name, string $kind, bool $requiresPickupPoint, string $currency, int $amountMinor, ?string $serviceCode = null, ?int $freeAboveMinor = null, ?int $remainingToFreeMinor = null): self
    {
        return new self($methodId, $name, $kind, $requiresPickupPoint, $currency, $amountMinor, null, $serviceCode, null, $freeAboveMinor, $remainingToFreeMinor);
    }

    public static function unavailable(string $methodId, string $name, string $kind, bool $requiresPickupPoint, string $currency, string $reason): self
    {
        return new self($methodId, $name, $kind, $requiresPickupPoint, $currency, null, $reason);
    }

    public function isAvailable(): bool
    {
        return $this->amountMinor !== null;
    }

    public function withHandle(string $handle): self
    {
        return new self($this->methodId, $this->name, $this->kind, $this->requiresPickupPoint, $this->currency, $this->amountMinor, $this->unavailableReason, $this->serviceCode, $handle, $this->freeAboveMinor, $this->remainingToFreeMinor);
    }
}
