<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * Thrown by ShippingProviderResolver when the carrier code is not a registered
 * carrier — never falls back to a default or another carrier: quoting or
 * labelling through a carrier nobody chose is worse than a loud failure
 * (shipping-domain-design.md §6, the PaymentMethodAdapterResolver posture).
 */
final class UnknownShippingCarrierException extends RuntimeException
{
    public function __construct(public readonly string $carrierCode)
    {
        parent::__construct("No shipping carrier is registered under the code \"{$carrierCode}\".");
    }
}
