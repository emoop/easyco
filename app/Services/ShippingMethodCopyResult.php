<?php

namespace App\Services;

use EasyCo\Shipping\ShippingMethod;

/**
 * What ShippingMethodCopier::copyToZones() returns: the new methods, one per target zone, and the zones that ALREADY
 * had a method with the same name and kind (the copy was made anyway — facts, not enforcement — so the screen can
 * state it).
 */
final class ShippingMethodCopyResult
{
    /**
     * @param  list<ShippingMethod>  $created
     * @param  list<string>  $zonesWithSameNameAndKind  zone ids
     */
    public function __construct(
        public readonly array $created,
        public readonly array $zonesWithSameNameAndKind,
    ) {
    }
}
