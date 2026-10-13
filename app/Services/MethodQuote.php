<?php

namespace App\Services;

use EasyCo\Shipping\Enums\ShippingDestinationScope;

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
 *
 * destinationScope / servesDestination (shipping stage 6b, design 9.2.4): the method's scope
 * (address | pickup | any) and whether it serves THIS quote's kind of destination. `requiresPickupPoint`
 * is the old boolean, now DERIVED: true only for a pickup-only method. Neither new field is part of
 * what a handle binds (the handle binds the destination kind through the pricing hash already).
 *
 * servedKinds / freeKinds (shipping stage 5g-2b, design 9.3.5): the kinds the method serves and the kinds its free-shipping set
 * covers (address | office | locker, canonical order), facts of the METHOD ROW like destinationScope. The kind-aware hint reads them;
 * a `shipping.quotes` filter cannot change them (a rebuilt quote carries none and gets the row's back, a stated different one
 * is refused as changed_field).
 */
final class MethodQuote
{
    public const NO_QUOTE = 'no_quote';

    public const NO_SETTLEMENT = 'no_settlement';

    /** A CARRIER method whose destination scope does not cover this quote's kind of destination: the carrier is never asked (stage 6b). */
    public const DESTINATION_NOT_SERVED = 'destination_not_served';

    /**
     * @param list<string>|null $servedKinds the kinds the method serves (address | office | locker); null = not stated
     * @param list<string>|null $freeKinds the kinds its free-shipping set covers (stored set within the served kinds); null = not stated
     */
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
        public readonly ?string $courier = null,
        public readonly ?string $deliveryType = null,
        public readonly ?string $destinationScope = null,
        public readonly ?bool $servesDestination = null,
        public readonly ?array $servedKinds = null,
        public readonly ?array $freeKinds = null,
    ) {
    }

    /** @param list<string>|null $servedKinds @param list<string>|null $freeKinds */
    public static function priced(string $methodId, string $name, string $kind, bool $requiresPickupPoint, string $currency, int $amountMinor, ?string $serviceCode = null, ?int $freeAboveMinor = null, ?int $remainingToFreeMinor = null, ?string $courier = null, ?string $deliveryType = null, ?string $destinationScope = null, ?bool $servesDestination = null, ?array $servedKinds = null, ?array $freeKinds = null): self
    {
        return new self($methodId, $name, $kind, $requiresPickupPoint, $currency, $amountMinor, null, $serviceCode, null, $freeAboveMinor, $remainingToFreeMinor, $courier, $deliveryType, $destinationScope, $servesDestination, $servedKinds, $freeKinds);
    }

    /** @param list<string>|null $servedKinds @param list<string>|null $freeKinds */
    public static function unavailable(string $methodId, string $name, string $kind, bool $requiresPickupPoint, string $currency, string $reason, ?string $courier = null, ?string $deliveryType = null, ?string $destinationScope = null, ?bool $servesDestination = null, ?array $servedKinds = null, ?array $freeKinds = null): self
    {
        return new self($methodId, $name, $kind, $requiresPickupPoint, $currency, null, $reason, null, null, null, null, $courier, $deliveryType, $destinationScope, $servesDestination, $servedKinds, $freeKinds);
    }

    /**
     * The method's destination scope (address | pickup | any). A quote built without one — a merchant filter rebuilding a quote with
     * the pre-6b constructor — reads it from the old boolean (true -> pickup, false -> address), which is what that boolean always meant.
     */
    public function scope(): string
    {
        return $this->destinationScope ?? ($this->requiresPickupPoint ? ShippingDestinationScope::PICKUP->value : ShippingDestinationScope::ADDRESS->value);
    }

    /** Does this method serve a pickup point (true) or a street address (false)? The scope's own rule, in one place. */
    public function servesPickupPoint(bool $isPickupPoint): bool
    {
        return ShippingDestinationScope::from($this->scope())->serves($isPickupPoint);
    }

    public function isAvailable(): bool
    {
        return $this->amountMinor !== null;
    }

    public function withHandle(string $handle): self
    {
        return $this->replicate(['handle' => $handle]);
    }

    /** The same quote carrying the method's courier group facts (stage 5f): display facts of the method row, never a filter's to change. */
    public function withGrouping(?string $courier, ?string $deliveryType): self
    {
        return $this->replicate(['courier' => $courier, 'deliveryType' => $deliveryType]);
    }

    /** The same quote carrying the method row's scope and whether it serves this request's destination (stage 6b): facts of the method, never a filter's to change. */
    public function withDestination(?string $destinationScope, ?bool $servesDestination): self
    {
        return $this->replicate(['destinationScope' => $destinationScope, 'servesDestination' => $servesDestination]);
    }

    /**
     * The same quote carrying the method row's served kinds and free kinds (stage 5g-2b): facts of the method, never a filter's to change.
     *
     * @param list<string>|null $servedKinds
     * @param list<string>|null $freeKinds
     */
    public function withKinds(?array $servedKinds, ?array $freeKinds): self
    {
        return $this->replicate(['servedKinds' => $servedKinds, 'freeKinds' => $freeKinds]);
    }

    /** @param array<string, mixed> $changes */
    private function replicate(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
