<?php

namespace EasyCo\Shipping\Carrier;

/** The courier's own two worlds (courier-integration-design.md §5). Credentials are stored per environment, never shared. */
enum CourierEnvironment: string
{
    case TEST = 'test';
    case PRODUCTION = 'production';

    /** An unknown stored value is TEST: the safe side (no real shipment, no real customer data). */
    public static function fromStored(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::TEST;
    }
}
