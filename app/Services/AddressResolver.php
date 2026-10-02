<?php

namespace App\Services;

use App\Services\Exceptions\AddressIncompleteForCheckoutException;
use App\Services\Exceptions\AddressNotFoundForCheckoutException;
use EasyCo\Address\Address;
use EasyCo\Address\Contracts\AddressRepository;
use EasyCo\Address\Enums\AddressDeliveryType;
use Illuminate\Support\Facades\Log;

/**
 * Resolves the Address for a checkout, per checkout-domain-design.md §8.4.
 *
 * TWO PATHS, mirroring the design doc's own two cases:
 * - resolveExisting(): a logged-in customer chose one of their saved
 *   addresses by id. ACCOUNT-ONLY BY DESIGN, not an oversight: a guest
 *   never has a saved address to select from (guests always go through
 *   resolveNew() instead) — there is no equivalent of Cart's
 *   session-token identification for a saved address, same posture
 *   AddressController's own docblock already states for index()/
 *   update().
 * - resolveNew(): a guest (always) or a logged-in customer typing a
 *   fresh address. Always constructs and saves a REAL Address row —
 *   one consistent path for both cases, mirroring address-domain-
 *   design.md §1's own "simplest structure that satisfies both cases"
 *   reasoning for the domain itself; no special-cased "guest addresses
 *   aren't really persisted" branch.
 *
 * Same shape/staging as App\Services\ClientResolver: a small, standalone
 * app-layer service, not yet wired into any Checkout orchestration
 * transaction.
 */
class AddressResolver
{
    public function __construct(
        private readonly AddressRepository $addresses,
    ) {
    }

    /**
     * @throws AddressNotFoundForCheckoutException if no address with
     *   this id exists, or it belongs to a different account than
     *   $accountId — both cases throw the exact same exception with the
     *   exact same message, never revealing which one happened.
     */
    public function resolveExisting(string $addressId, string $accountId): Address
    {
        $address = $this->addresses->findById($addressId);

        if ($address === null || $address->accountId() !== $accountId) {
            throw new AddressNotFoundForCheckoutException($addressId);
        }

        // A historical pickup-point address saved before D1 has no country; an
        // Order cannot be built from it and nothing may guess one. Refused here,
        // before any write. The warning carries the address id ONLY (no personal
        // data) so the merchant can find it and run the backfill command.
        if ($address->country() === null) {
            Log::warning('Checkout refused: the saved address has no country (run addresses:backfill-pickup-country).', [
                'address_id' => $addressId,
            ]);

            throw new AddressIncompleteForCheckoutException($addressId);
        }

        return $address;
    }

    public function resolveNew(
        AddressDeliveryType $deliveryType,
        string $recipientName,
        string $phone,
        ?string $accountId,
        ?string $country = null,
        ?string $city = null,
        ?string $postalCode = null,
        ?string $addressLine1 = null,
        ?string $addressLine2 = null,
        ?string $carrierCode = null,
        ?string $pickupPointReference = null,
        ?string $settlement = null,
    ): Address {
        $address = Address::create(
            deliveryType: $deliveryType,
            recipientName: $recipientName,
            phone: $phone,
            accountId: $accountId,
            country: $country,
            city: $city,
            postalCode: $postalCode,
            addressLine1: $addressLine1,
            addressLine2: $addressLine2,
            carrierCode: $carrierCode,
            pickupPointReference: $pickupPointReference,
            settlement: $settlement,
        );

        $this->addresses->save($address);

        return $address;
    }
}
