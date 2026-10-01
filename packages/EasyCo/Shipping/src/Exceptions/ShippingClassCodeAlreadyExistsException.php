<?php

namespace EasyCo\Shipping\Exceptions;

use RuntimeException;

/**
 * Thrown by EloquentShippingClassRepository::save() when the UNIQUE(code)
 * constraint of shipping_classes is violated. Mirrors
 * EasyCo\Promotions\Exceptions\PromotionCodeAlreadyExistsException
 * (CLAUDE.md rule 3).
 */
final class ShippingClassCodeAlreadyExistsException extends RuntimeException
{
    public static function forCode(string $code): self
    {
        return new self("A ShippingClass with code \"{$code}\" already exists.");
    }
}
