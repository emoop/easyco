<?php

namespace App\Services;

use EasyCo\Shipping\Enums\ShippingDeliveryType;

/**
 * The pickup kind exactly as the client claimed it (shipping stage 5g-2a): OFFICE or LOCKER, or null for anything else. It is a
 * CLAIM, not a fact: see PickupKindSource. The request layer has already refused any other value; this stays strict anyway.
 */
final class ClientClaimedPickupKindSource implements PickupKindSource
{
    public function kindFor(string $carrierCode, string $pickupPointReference, ?string $claimedKind): ?ShippingDeliveryType
    {
        $kind = $claimedKind === null ? null : ShippingDeliveryType::tryFrom($claimedKind);

        return $kind !== null && in_array($kind, ShippingDeliveryType::pickupKinds(), true) ? $kind : null;
    }
}
