<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * A zone that still has methods cannot be deleted (shipping-domain-design.md §12.6): never a silent cascade.
 * The message is a translated sentence naming the count and pointing at the zone's methods; nothing was
 * written. The database refuses it too (the restrict foreign key `ship_methods_zone_id_foreign`) — that is only
 * the backstop. A zone has no "deactivate": the merchant removes or deactivates its METHODS first.
 */
final class ShippingZoneInUseException extends RuntimeException
{
    public function __construct(public readonly string $zoneName, public readonly int $methodCount)
    {
        parent::__construct(trans_choice('shipping.zones.in_use', $methodCount, ['name' => $zoneName, 'count' => $methodCount]));
    }
}
