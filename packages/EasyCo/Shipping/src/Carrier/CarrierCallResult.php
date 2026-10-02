<?php

namespace EasyCo\Shipping\Carrier;

/**
 * The outcome of a rate or pickup-point call as the checkout sees it
 * (shipping-domain-design.md §6): either the provider's items (possibly none —
 * "this carrier will not carry it" is an ANSWER, not a failure), or an explicit
 * "unavailable" with a reason. Never an exception into the caller.
 *
 * @template T of ShippingQuote|PickupPoint
 */
final class CarrierCallResult
{
    /** @param list<T> $items */
    private function __construct(
        private readonly array $items,
        private readonly ?CarrierUnavailableReason $reason,
    ) {
    }

    /**
     * @template U of ShippingQuote|PickupPoint
     *
     * @param  list<U>  $items
     * @return self<U>
     */
    public static function answered(array $items): self
    {
        return new self(array_values($items), null);
    }

    public static function unavailable(CarrierUnavailableReason $reason): self
    {
        return new self([], $reason);
    }

    public function isAvailable(): bool
    {
        return $this->reason === null;
    }

    /** @return list<T> empty when unavailable, and also when the carrier answered "nothing" */
    public function items(): array
    {
        return $this->items;
    }

    public function reason(): ?CarrierUnavailableReason
    {
        return $this->reason;
    }
}
