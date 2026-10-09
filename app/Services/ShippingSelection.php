<?php

namespace App\Services;

use EasyCo\Pricing\Money;

/**
 * The shipping a customer chose, verified (shipping stage 4d): exactly what stage 4e passes to Order::create and the
 * placement snapshot writer. Immutable. methodId is also the order's shipping_method_code (O4: the method id as a string).
 * The amount is always the server's own recomputed figure, never a number from the client.
 */
final class ShippingSelection
{
    public function __construct(
        public readonly string $methodId,
        public readonly string $methodName,
        public readonly ?string $courier,
        public readonly ?string $deliveryType,
        public readonly Money $amount,
        public readonly ?string $serviceCode,
        public readonly bool $requiresPickupPoint,
        public readonly string $kind,
    ) {
    }

    /** A local method (flat, free, per-class) is priced by the store itself; a carrier method has a carrier service. */
    public function isLocal(): bool
    {
        return $this->kind !== 'carrier';
    }
}
