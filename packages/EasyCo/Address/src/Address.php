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
 *
 * MOSTLY IMMUTABLE, ONE MUTATOR — id/accountId are never rewritten after
 * construction (id via the usual one-time assignId(); accountId doesn't
 * change via this class at all, same posture Catalog\Product takes
 * toward its own structural fields). Every other field can be rewritten
 * via update(), a single mutator on an otherwise-immutable aggregate —
 * same shape as Product::assignBrand() (a single mutator on an
 * otherwise-mostly-immutable aggregate) and Cart::applyPromotionCode()
 * (re-running the same construction-time validation on every mutation,
 * never a separate, looser rule set for updates).
 */
final class Address
{
    /** The kinds a pickup point can have (shipping stage 5g-2a). */
    private const PICKUP_KINDS = ['office', 'locker'];

    private function __construct(
        private ?string $id,
        private readonly ?string $accountId,
        private AddressDeliveryType $deliveryType,
        private string $recipientName,
        private string $phone,
        private ?string $country,
        private ?string $city,
        private ?string $postalCode,
        private ?string $addressLine1,
        private ?string $addressLine2,
        private ?string $carrierCode,
        private ?string $pickupPointReference,
        private ?string $settlement,
        // Display snapshot of the chosen pickup point (stage 4f); trailing and nullable like every later addition.
        private ?string $pickupPointName = null,
        private ?string $pickupPointAddress = null,
        // The KIND of the pickup point (stage 5g-2a): 'office' | 'locker' | null = not stated. A plain validated string: this package imports nothing from Shipping.
        private ?string $pickupPointKind = null,
        // The REGION (state / province) of a STREET address (checkout RN-1): free text, 1 to 100 characters when given, null for a pickup point.
        private ?string $region = null,
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
        self::assertPickupDisplay($deliveryType, $pickupPointName, $pickupPointAddress);
        self::assertPickupKind($deliveryType, $pickupPointKind);
        self::assertRegion($deliveryType, $region);
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
     * (city/postalCode/addressLine1/addressLine2) and
     * PICKUP_POINT fields (carrierCode/pickupPointReference/settlement)
     * in BOTH directions: the fields belonging to the given deliveryType
     * must be present (postalCode/addressLine2 excepted — nullable
     * either way, see design doc §2), and the fields belonging to the
     * OTHER type must all be null.
     *
     * COUNTRY BELONGS TO BOTH TYPES (owner decision D1, shipping stage 3.0b):
     * every delivery, a PICKUP_POINT included, is to a country, so it is no
     * longer in the "must be null for a pickup point" list. Its SHAPE (exactly
     * two uppercase ASCII letters, never normalized) is checked by
     * assertCountryShape(), called only from the two operations that accept a
     * NEW value — create() and update() — and NOT from here, because this method
     * also runs from the constructor, which reconstituteFromStorage() uses: a
     * historical pickup-point row with a NULL country must still load. (A
     * STREET_ADDRESS keeps its old rule here: a non-empty country.) Mirrors the clarity of Cart's own
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


    /**
     * The pickup point's DISPLAY SNAPSHOT (shipping stage 4f, shipping-domain-design.md section 9.1.6): the office's name and
     * address as the customer saw them, kept so an order still reads correctly if the courier renames or closes the office.
     * They are display text, never an identity (the reference is), and nothing here verifies them against a courier.
     *
     * Allowed only for a PICKUP_POINT; a STREET_ADDRESS must have both null; when given, each is 1 to 255 characters.
     * Both being null on a pickup point is LEGAL here — a historical saved pickup address (saved before 4f) has neither and
     * must still load and still check out. That both are REQUIRED for a NEW pickup address is the request layer's rule
     * (checkout and the address API), not the entity's.
     */
    private static function assertPickupDisplay(AddressDeliveryType $deliveryType, ?string $pickupPointName, ?string $pickupPointAddress): void
    {
        foreach (['pickupPointName' => $pickupPointName, 'pickupPointAddress' => $pickupPointAddress] as $name => $value) {
            if ($value === null) {
                continue;
            }

            if ($deliveryType === AddressDeliveryType::STREET_ADDRESS) {
                throw new InvalidArgumentException("Address {$name} must be null when deliveryType is STREET_ADDRESS, got a non-null value.");
            }

            if (trim($value) === '' || mb_strlen($value) > 255) {
                throw new InvalidArgumentException("Address {$name} must be 1 to 255 characters when given; use null for none.");
            }
        }
    }

    /** The pickup point's kind (shipping stage 5g-2a, design 9.3.2 B): only for a PICKUP_POINT, and only 'office' or 'locker'; null = not stated (also LEGAL, so a historical pickup address loads). */
    private static function assertPickupKind(AddressDeliveryType $deliveryType, ?string $pickupPointKind): void
    {
        if ($pickupPointKind === null) {
            return;
        }

        if ($deliveryType === AddressDeliveryType::STREET_ADDRESS) {
            throw new InvalidArgumentException('Address pickupPointKind must be null when deliveryType is STREET_ADDRESS, got a non-null value.');
        }

        if (! in_array($pickupPointKind, self::PICKUP_KINDS, true)) {
            throw new InvalidArgumentException('Address pickupPointKind must be "office" or "locker" when given; use null for not stated.');
        }
    }

    /**
     * The region (checkout RN-1, checkout-region-and-note-design.md 2.1): only for a STREET_ADDRESS (a pickup point has its settlement
     * and the office's own address), 1 to 100 characters when given; null = none (legal: it is optional, and a saved address predates it).
     */
    private static function assertRegion(AddressDeliveryType $deliveryType, ?string $region): void
    {
        if ($region === null) {
            return;
        }

        if ($deliveryType === AddressDeliveryType::PICKUP_POINT) {
            throw new InvalidArgumentException('Address region must be null when deliveryType is PICKUP_POINT, got a non-null value.');
        }

        if (trim($region) === '' || mb_strlen($region) > 100) {
            throw new InvalidArgumentException('Address region must be 1 to 100 characters when given; use null for none.');
        }
    }

    private static function trimmedOrNull(?string $value): ?string
    {
        return $value === null ? null : trim($value);
    }

    /**
     * The delivery country, for EITHER type: required, exactly two uppercase
     * ASCII letters. Shape only — whether the code is a real country is the
     * HTTP layer's list (a domain package must not import app code) — and never
     * normalized: a lowercase "bg" is refused, not fixed.
     */
    private static function assertCountryShape(?string $country): void
    {
        if ($country === null || trim($country) === '') {
            throw new InvalidArgumentException('Address country must not be empty; it is required for every delivery type.');
        }

        if (preg_match('/^[A-Z]{2}$/D', $country) !== 1) {
            throw new InvalidArgumentException("Address country must be an uppercase ISO 3166-1 alpha-2 code (two capital letters), got \"{$country}\".");
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
        ?string $pickupPointName = null,
        ?string $pickupPointAddress = null,
        ?string $pickupPointKind = null,
        ?string $region = null,
    ): self {
        self::assertCountryShape($country);

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
            pickupPointName: self::trimmedOrNull($pickupPointName),
            pickupPointAddress: self::trimmedOrNull($pickupPointAddress),
            pickupPointKind: self::trimmedOrNull($pickupPointKind),
            region: self::trimmedOrNull($region),
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
        ?string $pickupPointName = null,
        ?string $pickupPointAddress = null,
        ?string $pickupPointKind = null,
        ?string $region = null,
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
            pickupPointName: $pickupPointName,
            pickupPointAddress: $pickupPointAddress,
            pickupPointKind: $pickupPointKind,
            region: $region,
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

    /**
     * Overwrites every field except id/accountId — ownership doesn't
     * change via update(), that's a different operation. Re-runs the
     * exact same validation create()'s constructor already runs (the
     * same private assertion methods, not duplicated logic): an update
     * that would violate STREET_ADDRESS/PICKUP_POINT exclusivity is
     * rejected exactly like construction would reject it, and nothing
     * on $this is overwritten unless every assertion passes first.
     */
    public function update(
        AddressDeliveryType $deliveryType,
        string $recipientName,
        string $phone,
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
    ): void {
        $region = self::trimmedOrNull($region);
        self::assertRegion($deliveryType, $region);
        $pickupPointName = self::trimmedOrNull($pickupPointName);
        $pickupPointAddress = self::trimmedOrNull($pickupPointAddress);
        $pickupPointKind = self::trimmedOrNull($pickupPointKind);
        self::assertPickupDisplay($deliveryType, $pickupPointName, $pickupPointAddress);
        self::assertPickupKind($deliveryType, $pickupPointKind);
        self::assertNotEmpty('recipientName', $recipientName);
        self::assertNotEmpty('phone', $phone);
        self::assertCountryShape($country);
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

        $this->deliveryType = $deliveryType;
        $this->recipientName = $recipientName;
        $this->phone = $phone;
        $this->country = $country;
        $this->city = $city;
        $this->postalCode = $postalCode;
        $this->addressLine1 = $addressLine1;
        $this->addressLine2 = $addressLine2;
        $this->carrierCode = $carrierCode;
        $this->pickupPointReference = $pickupPointReference;
        $this->settlement = $settlement;
        $this->pickupPointName = $pickupPointName;
        $this->pickupPointAddress = $pickupPointAddress;
        $this->pickupPointKind = $pickupPointKind;
        $this->region = $region;
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

    /** The office's name as the customer saw it (stage 4f display snapshot); null for a street address or a historical pickup address. */
    public function pickupPointName(): ?string
    {
        return $this->pickupPointName;
    }

    /** The office's address line as the customer saw it (stage 4f display snapshot); null for a street address or a historical pickup address. */
    public function pickupPointAddress(): ?string
    {
        return $this->pickupPointAddress;
    }

    /** 'office' | 'locker' | null (not stated). Only ever non-null for a pickup point. */
    public function pickupPointKind(): ?string
    {
        return $this->pickupPointKind;
    }

    /** The region (state / province) of a street address, or null: none. Never set on a pickup point. */
    public function region(): ?string
    {
        return $this->region;
    }
}
