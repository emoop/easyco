<?php

namespace App\Services;

use EasyCo\Address\Enums\AddressDeliveryType;

/**
 * Everything CheckoutOrchestrator::place() needs from the customer, per
 * checkout-domain-design.md §8. A plain carrier — validation of "exactly
 * one of $addressId / the new-address fields" and of email format
 * belongs to the future HTTP layer's request validation, not here.
 *
 * $addressId SET (logged-in only, §8.4) means "use this saved address" —
 * AddressResolver::resolveExisting() already enforces the
 * account-ownership check, so this class does not re-check it.
 * $addressId NULL means a fresh address is being typed (guest always,
 * or a logged-in customer not using a saved one); $deliveryType and the
 * matching address fields are then required.
 */
final class CheckoutInput
{
    public function __construct(
        public readonly string $cartId,
        public readonly string $email,
        public readonly string $recipientName,
        public readonly string $phone,
        public readonly ?string $accountId = null,
        public readonly ?string $addressId = null,
        public readonly ?AddressDeliveryType $deliveryType = null,
        public readonly ?string $country = null,
        public readonly ?string $city = null,
        public readonly ?string $postalCode = null,
        public readonly ?string $addressLine1 = null,
        public readonly ?string $addressLine2 = null,
        public readonly ?string $carrierCode = null,
        public readonly ?string $pickupPointReference = null,
        public readonly ?string $settlement = null,
    ) {
    }
}
