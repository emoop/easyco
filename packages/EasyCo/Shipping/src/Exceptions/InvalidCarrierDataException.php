<?php

namespace EasyCo\Shipping\Exceptions;

use InvalidArgumentException;

/**
 * A value object of the carrier doors (ShippingContext, ShippingQuote,
 * PickupPoint, ShipmentRequest, ShipmentLabel, CallBudget) was built with
 * data that breaks its invariants (shipping-domain-design.md §6). The message
 * names the class and the field, and never repeats a customer-supplied value:
 * these objects carry addresses and names, and an exception message ends up in
 * logs.
 */
final class InvalidCarrierDataException extends InvalidArgumentException
{
    public static function field(string $class, string $field, string $problem): self
    {
        return new self("{$class}: {$field} {$problem}.");
    }
}
