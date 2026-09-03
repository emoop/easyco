<?php

namespace EasyCo\Address;

use EasyCo\Address\Enums\AddressDeliveryType;
use InvalidArgumentException;
use LogicException;

/**
 * A delivery destination — see address-domain-design.md §2 for the full
 * field list and reasoning. Mirrors EasyCo\Promotions\Promotion's shape:
 * private constructor, named assertion methods, a public create()
 * factory, reconstituteFromStorage() for the persistence layer, and a
 * one-time assignId().
 *
 * accountId IS NULLABLE, DELIBERATELY — see design doc §1: null means a
 * guest/one-off checkout address, set means it was saved into a logged-in
 * customer's reusable address book. Unlike Cart's accountId/sessionToken
 * pair, this is not an XOR — accountId is simply optional.
 *
 * EXACTLY THE FIELDS BELONGING TO deliveryType MAY BE NON-NULL, NEVER
 * FIELDS FROM THE OTHER TYPE — see assertFieldsMatchDeliveryType(),
 * mirroring the clarity of Cart's own accountId/sessionToken XOR guard
 * (Cart.php's constructor).
 */
final class Address
{
    private function __construct(
        private ?string $id,
        private readonly ?string $accountId,
        private readonly AddressDeliveryType $deliveryType,
        private readonly string $recipientName,
        private readonly string $phone,
        private readonly ?string $country,
        private readonly ?string $city,
        private readonly ?string $postalCode,
        private readonly ?string $addressLine1,
        private readonly ?string $addressLine2,
        private readonly ?string $carrierCode,
        private readonly ?string $pickupPointReference,
        private readonly ?string $settlement,
    ) {
        self::assertNotEmpty('recipientName', $recipientName);
        self::assertNotEmpty('phone', $phone);
        self::assertAccountIdNotEmptyString($accountId);
        self::assertFieldsMatchDeliveryType(
            deliveryType: $deliveryType,
            country: $country,
            city: $city,
            postalCode: $postalCode,
            addressLine1: $addressLine1,
            addressLine2: $addressLine2,
            carrierCode: $carrierCode,
            pickupPointReference: $pickupPointReference,
            settlement: $settlement,
        );
    }

    private static function assertNotEmpty(string $fieldName, string $value): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException("Address {$fieldName} must not be empty.");
        }
    }

    private static function assertAccountIdNotEmptyString(?string $accountId): void
    {
        if ($accountId === '') {
            throw new InvalidArgumentException('Address accountId must not be an empty string; use null for a guest/one-off address.');
        }
    }

    /**
     * Enforces exclusivity between STREET_ADDRESS fields
     * (country/city/postalCode/addressLine1/addressLine2) and
     * PICKUP_POINT fields (carrierCode/pickupPointReference/settlement)
     * in BOTH directions: the fields belonging to the given deliveryType
     * must be present (postalCode/addressLine2 excepted — nullable
     * either way, see design doc §2), and the fields belonging to the
     * OTHER type must all be null. Mirrors the clarity of Cart's own
     * XOR exception message, naming exactly which fields conflicted
     * rather than just saying "invalid."
     */
    private static function assertFieldsMatchDeliveryType(
        AddressDeliveryType $deliveryType,
        ?string $country,
        ?string $city,
        ?string $postalCode,
        ?string $addressLine1,
        ?string $addressLine2,
        ?string $carrierCode,
        ?string $pickupPointReference,
        ?string $settlement,
    ): void {
        if ($deliveryType === AddressDeliveryType::STREET_ADDRESS) {
            foreach (['country' => $country, 'city' => $city, 'addressLine1' => $addressLine1] as $name => $value) {
                if ($value === null || trim($value) === '') {
                    throw new InvalidArgumentException("Address {$name} must not be empty when deliveryType is STREET_ADDRESS.");
                }
            }

            foreach (['carrierCode' => $carrierCode, 'pickupPointReference' => $pickupPointReference, 'settlement' => $settlement] as $name => $value) {
                if ($value !== null) {
                    throw new InvalidArgumentException("Address {$name} must be null when deliveryType is STREET_ADDRESS, got a non-null value.");
                }
            }

            return;
        }

        foreach (['carrierCode' => $carrierCode, 'pickupPointReference' => $pickupPointReference, 'settlement' => $settlement] as $name => $value) {
            if ($value === null || trim($value) === '') {
                throw new InvalidArgumentException("Address {$name} must not be empty when deliveryType is PICKUP_POINT.");
            }
        }

        foreach ([
            'country' => $country,
            'city' => $city,
            'postalCode' => $postalCode,
            'addressLine1' => $addressLine1,
            'addressLine2' => $addressLine2,
        ] as $name => $value) {
            if ($value !== null) {
                throw new InvalidArgumentException("Address {$name} must be null when deliveryType is PICKUP_POINT, got a non-null value.");
            }
        }
    }

    public static function create(
        AddressDeliveryType $deliveryType,
        string $recipientName,
        string $phone,
        ?string $accountId = null,
        ?string $country = null,
        ?string $city = null,
        ?string $postalCode = null,
        ?string $addressLine1 = null,
        ?string $addressLine2 = null,
        ?string $carrierCode = null,
        ?string $pickupPointReference = null,
        ?string $settlement = null,
    ): self {
        return new self(
            id: null,
            accountId: $accountId,
            deliveryType: $deliveryType,
            recipientName: $recipientName,
            phone: $phone,
            country: $country,
            city: $city,
            postalCode: $postalCode,
            addressLine1: $addressLine1,
            addressLine2: $addressLine2,
            carrierCode: $carrierCode,
            pickupPointReference: $pickupPointReference,
            settlement: $settlement,
        );
    }

    /**
     * Reconstitutes an Address exactly as it exists in storage.
     *
     * PERSISTENCE-LAYER ONLY — trusts the caller (a repository) that the
     * given data is already-valid data read back from storage. This
     * method is not a business operation and application code must never
     * call it directly; only a repository implementation reconstructing
     * this entity from an already-validated row should call it.
     */
    public static function reconstituteFromStorage(
        string $id,
        ?string $accountId,
        AddressDeliveryType $deliveryType,
        string $recipientName,
        string $phone,
        ?string $country,
        ?string $city,
        ?string $postalCode,
        ?string $addressLine1,
        ?string $addressLine2,
        ?string $carrierCode,
        ?string $pickupPointReference,
        ?string $settlement,
    ): self {
        return new self(
            id: $id,
            accountId: $accountId,
            deliveryType: $deliveryType,
            recipientName: $recipientName,
            phone: $phone,
            country: $country,
            city: $city,
            postalCode: $postalCode,
            addressLine1: $addressLine1,
            addressLine2: $addressLine2,
            carrierCode: $carrierCode,
            pickupPointReference: $pickupPointReference,
            settlement: $settlement,
        );
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new LogicException('Address already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    public function accountId(): ?string
    {
        return $this->accountId;
    }

    public function deliveryType(): AddressDeliveryType
    {
        return $this->deliveryType;
    }

    public function recipientName(): string
    {
        return $this->recipientName;
    }

    public function phone(): string
    {
        return $this->phone;
    }

    public function country(): ?string
    {
        return $this->country;
    }

    public function city(): ?string
    {
        return $this->city;
    }

    public function postalCode(): ?string
    {
        return $this->postalCode;
    }

    public function addressLine1(): ?string
    {
        return $this->addressLine1;
    }

    public function addressLine2(): ?string
    {
        return $this->addressLine2;
    }

    public function carrierCode(): ?string
    {
        return $this->carrierCode;
    }

    public function pickupPointReference(): ?string
    {
        return $this->pickupPointReference;
    }

    public function settlement(): ?string
    {
        return $this->settlement;
    }
}
