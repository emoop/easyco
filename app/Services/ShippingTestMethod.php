<?php

namespace App\Services;

/**
 * One method as "Try it" (shipping-domain-design.md §12.3.5) shows it: the real
 * calculator's answer for a hypothetical destination and cart, plus the method's
 * own readable summary. A CARRIER method has no local price — `needsCarrierQuote`
 * is true and `amountMinor` is null (the tester never calls a carrier).
 *
 * destinationScope / servesDestination (stage 6d, design 9.2.8): the method's scope
 * (address | pickup | any) and whether it serves the destination KIND the tester was
 * asked about — the scope's ONE rule (ShippingMethod::servesPickupPoint()), never a
 * second copy of it here.
 */
final class ShippingTestMethod
{
    public function __construct(
        public readonly string $methodId,
        public readonly string $name,
        /** address | pickup | any — the method's destination scope. */
        public readonly string $destinationScope,
        /** Whether this method serves the destination kind the tester was asked about. */
        public readonly bool $servesDestination,
        public readonly string $summary,
        public readonly bool $needsCarrierQuote,
        public readonly ?int $amountMinor,
        public readonly ?int $freeAboveMinor,
        public readonly ?int $remainingToFreeMinor,
        /** 'replace' | 'adjust' for a PER_CLASS method (shipping-domain-design.md §12.2), null for any other kind. */
        public readonly ?string $classMode = null,
        public readonly ?string $courier = null,
    ) {
    }
}
