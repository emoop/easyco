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
    /** ACC-2b: the most saved addresses one account may hold. POST /api/addresses refuses the 21st (422); checkout never refuses, it just stops saving. */
    public const SAVED_LIMIT = 20;

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
        string $recipientFirstName,
        string $recipientLastName,
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
        ?string $pickupPointName = null,
        ?string $pickupPointAddress = null,
        ?string $pickupPointKind = null,
        ?string $region = null,
    ): Address {
        $address = Address::create(
            deliveryType: $deliveryType,
            recipientFirstName: $recipientFirstName,
            recipientLastName: $recipientLastName,
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
            pickupPointName: $pickupPointName,
            pickupPointAddress: $pickupPointAddress,
            pickupPointKind: $pickupPointKind,
            region: $region,
        );

        // ACC-2b (guests are untouched: no lookup, no extra query): a logged-in customer's typed address is reused when she already
        // has the same one (bumping its updated_at), and is NOT saved at all once she holds SAVED_LIMIT of them: checkout never fails
        // on the book, the order carries its own snapshot and address_id stays null. ONE select serves both questions.
        if ($accountId !== null) {
            $saved = $this->addresses->findByAccountId($accountId);

            foreach ($saved as $existing) {
                if ($this->sameAddress($existing, $address)) {
                    $this->addresses->touchOwned((string) $existing->id(), $accountId);

                    return $existing;
                }
            }

            if (count($saved) >= self::SAVED_LIMIT) {
                return $address;
            }
        }

        $this->addresses->save($address);

        return $address;
    }

    /** Equal after trim, case and collapsed spaces on every field a customer types, the recipient and the phone included. */
    private function sameAddress(Address $a, Address $b): bool
    {
        return $this->fingerprint($a) === $this->fingerprint($b);
    }

    /** @return list<string> */
    private function fingerprint(Address $address): array
    {
        return array_map(
            static fn (?string $value): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $value))),
            [
                $address->deliveryType()->value, $address->country(), $address->city(), $address->postalCode(), $address->addressLine1(),
                $address->addressLine2(), $address->region(), $address->carrierCode(), $address->pickupPointReference(), $address->settlement(),
                $address->pickupPointName(), $address->pickupPointAddress(), $address->pickupPointKind(),
                $address->recipientFirstName(), $address->recipientLastName(), $address->phone(),
            ],
        );
    }
}
