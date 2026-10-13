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
 *
 * $paymentMethod matches the Payment domain's own `method` string
 * ('cash_on_delivery' / 'bank_transfer' in V1) — validating that it's one
 * the merchant actually offers belongs to the future HTTP layer, not here.
 *
 * $guestCartToken is the request's own session-held cart token (cart-domain-design.md
 * §8): the HTTP layer resolves it from the session and never from the request body,
 * and the orchestrator uses it — together with $accountId — as the identity that must
 * match a claim (§14.2) and a live cart's owner before anything is bought. Required
 * for a guest checkout: without it, no guest cart could ever be shown to be the
 * requester's.
 */
final class CheckoutInput
{
    public function __construct(
        public readonly string $cartId,
        public readonly string $email,
        public readonly string $recipientFirstName,
        public readonly string $recipientLastName,
        public readonly string $phone,
        public readonly string $paymentMethod,
        public readonly ?string $accountId = null,
        public readonly ?string $guestCartToken = null,
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
        // The shipping choice (stage 4e): the method id and the quote handle the quote endpoint issued, and optionally the
        // amount the customer was shown. Shapes are validated by the resolver; the expected amount is only compared.
        public readonly ?string $shippingMethodId = null,
        public readonly ?string $quoteHandle = null,
        public readonly ?int $expectedShippingMinor = null,
        // The pickup point's display snapshot (stage 4f): text the customer saw, never an identity.
        public readonly ?string $pickupPointName = null,
        public readonly ?string $pickupPointAddress = null,
        // The kind the CLIENT claims for a typed pickup point: 'office' | 'locker' (stage 5g-2a). Read ONCE, through PickupKindSource.
        public readonly ?string $pickupPointKind = null,
        // RN-1: the region of a typed STREET address as the client sent it (the per-country policy is applied ONCE, in the orchestrator),
        // and the customer's order note (a blank one is no note).
        public readonly ?string $region = null,
        public readonly ?string $customerNote = null,
        // WHAT THE CUSTOMER TICKED (checkout form v2, C1d-B), already decided by the HTTP layer from the merchant's settings: the terms
        // version the page displayed (set only when the terms were asked and accepted; the placement instant is taken from the checkout's
        // own clock), the three booleans (null = not offered / not sent) and the consent sentence the SERVER chose, never the client.
        public readonly ?string $termsVersion = null,
        public readonly ?bool $confirmationRequested = null,
        public readonly ?bool $callBeforeShipping = null,
        public readonly ?bool $marketingConsent = null,
        public readonly ?string $marketingConsentText = null,
    ) {
    }
}
