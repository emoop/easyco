<?php

namespace EasyCo\Address\Enums;

/**
 * How a delivery is destined — see address-domain-design.md §2.
 * STREET_ADDRESS uses country/city/postalCode/addressLine1/addressLine2;
 * PICKUP_POINT uses the carrier-agnostic carrierCode/pickupPointReference/
 * settlement instead. Exactly the fields belonging to the given type may
 * be non-null — see Address::assertFieldsMatchDeliveryType().
 */
enum AddressDeliveryType: string
{
    case STREET_ADDRESS = 'street_address';
    case PICKUP_POINT = 'pickup_point';
}
