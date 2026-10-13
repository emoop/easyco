<?php

namespace App\Services;

use EasyCo\Shipping\Enums\ShippingDeliveryType;

/**
 * Where the KIND of a pickup point (office | locker) comes from when an order or a saved address is created (shipping stage
 * 5g-2a, shipping-domain-design.md 9.3.7). THE ONE SEAM: the checkout and the address API read the kind through this and
 * nowhere else, so the courier stage replaces one binding instead of hunting for the field.
 *
 * TODAY IT IS A CLAIM: ClientClaimedPickupKindSource returns what the client sent. A claim is bound consistently from quote to
 * handle to checkout to order (the pricing hash carries it), but nothing verifies it against the real office. In the courier
 * stage the binding becomes record-derived: the kind is found from the synced pickup-point record identified by
 * (carrier code, pickup point reference) and the claimed value is ignored or refused.
 *
 * NOT used by the quote endpoint: there the kind is the customer's dropdown choice, which decides which list of points is shown.
 */
interface PickupKindSource
{
    /**
     * @param  string|null  $pickupPointReference  the courier's id of the point, or null: a DESCRIBED point (what the customer typed, no
     *                                           integration) has none; a future courier source derives the kind from a reference and
     *                                           falls back to the claim for a described point (checkout form v2, section 3.3)
     * @param  string|null  $claimedKind  what the client sent: 'office' | 'locker' | null (not stated)
     * @return ShippingDeliveryType|null OFFICE, LOCKER, or null = not stated
     */
    public function kindFor(string $carrierCode, ?string $pickupPointReference, ?string $claimedKind): ?ShippingDeliveryType;
}
