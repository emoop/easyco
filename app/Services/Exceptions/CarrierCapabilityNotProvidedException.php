<?php

namespace App\Services\Exceptions;

use EasyCo\Shipping\Carrier\CarrierCapability;
use RuntimeException;

/**
 * The carrier is registered but does not offer the requested capability: it
 * never declared it, or declared it and bound no provider under the named key
 * (a misconfigured extension). Refused by name; there is no fallback to another
 * capability or another carrier.
 */
final class CarrierCapabilityNotProvidedException extends RuntimeException
{
    public function __construct(public readonly string $carrierCode, public readonly CarrierCapability $capability, string $detail)
    {
        parent::__construct("Shipping carrier \"{$carrierCode}\" cannot be used for \"{$capability->value}\": {$detail}.");
    }
}
