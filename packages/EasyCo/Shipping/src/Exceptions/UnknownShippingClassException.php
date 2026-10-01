<?php

namespace EasyCo\Shipping\Exceptions;

use RuntimeException;

/**
 * Thrown by EloquentShippingMethodRepository::save() when a method's class
 * rates name a shipping class code that does not exist — checked before the
 * write, and again if the shipping_method_class_rates foreign key itself
 * refuses the row (a class removed between the check and the insert).
 */
final class UnknownShippingClassException extends RuntimeException
{
    /** @param list<string> $codes */
    public static function forCodes(array $codes): self
    {
        return new self('No ShippingClass exists with code: "'.implode('", "', $codes).'".');
    }
}
