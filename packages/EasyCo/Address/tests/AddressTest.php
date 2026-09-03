<?php

namespace EasyCo\Address\Tests;

use EasyCo\Address\Address;
use EasyCo\Address\Enums\AddressDeliveryType;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class AddressTest extends TestCase
{
    /**
     * array_key_exists(), NOT ?? — an override explicitly set to null
     * (e.g. ['country' => null], used to test the missing-field-throws
     * cases) must actually pass null through. ?? treats an explicit
     * null the same as "key absent," which would silently fall back to
     * the default instead.
     */
    private function value(array $overrides, string $key, mixed $default): mixed
    {
        return array_key_exists($key, $overrides) ? $overrides[$key] : $default;
    }

    private function streetAddress(array $overrides = []): Address
    {
        return Address::create(
            deliveryType: AddressDeliveryType::STREET_ADDRESS,
            recipientName: $this->value($overrides, 'recipientName', 'Ivan Ivanov'),
            phone: $this->value($overrides, 'phone', '+359888123456'),
            accountId: $this->value($overrides, 'accountId', null),
            country: $this->value($overrides, 'country', 'BG'),
            city: $this->value($overrides, 'city', 'Sofia'),
            postalCode: $this->value($overrides, 'postalCode', '1000'),
            addressLine1: $this->value($overrides, 'addressLine1', 'Vitosha Blvd 1'),
            addressLine2: $this->value($overrides, 'addressLine2', null),
            carrierCode: $this->value($overrides, 'carrierCode', null),
            pickupPointReference: $this->value($overrides, 'pickupPointReference', null),
            settlement: $this->value($overrides, 'settlement', null),
        );
    }

    private function pickupPoint(array $overrides = []): Address
    {
        return Address::create(
            deliveryType: AddressDeliveryType::PICKUP_POINT,
            recipientName: $this->value($overrides, 'recipientName', 'Ivan Ivanov'),
            phone: $this->value($overrides, 'phone', '+359888123456'),
            accountId: $this->value($overrides, 'accountId', null),
            country: $this->value($overrides, 'country', null),
            city: $this->value($overrides, 'city', null),
            postalCode: $this->value($overrides, 'postalCode', null),
            addressLine1: $this->value($overrides, 'addressLine1', null),
            addressLine2: $this->value($overrides, 'addressLine2', null),
            carrierCode: $this->value($overrides, 'carrierCode', 'econt'),
            pickupPointReference: $this->value($overrides, 'pickupPointReference', 'office-1234'),
            settlement: $this->value($overrides, 'settlement', 'Sofia'),
        );
    }

    // --- STREET_ADDRESS construction -----------------------------------

    public function test_street_address_with_all_required_fields_succeeds(): void
    {
        $address = $this->streetAddress();

        $this->assertSame(AddressDeliveryType::STREET_ADDRESS, $address->deliveryType());
        $this->assertSame('BG', $address->country());
        $this->assertSame('Sofia', $address->city());
        $this->assertSame('1000', $address->postalCode());
        $this->assertSame('Vitosha Blvd 1', $address->addressLine1());
        $this->assertNull($address->addressLine2());
        $this->assertNull($address->carrierCode());
        $this->assertNull($address->pickupPointReference());
        $this->assertNull($address->settlement());
    }

    public function test_street_address_with_null_postal_code_and_address_line_2_succeeds(): void
    {
        $address = $this->streetAddress(['postalCode' => null, 'addressLine2' => null]);

        $this->assertNull($address->postalCode());
        $this->assertNull($address->addressLine2());
    }

    public function test_street_address_missing_country_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('country');

        $this->streetAddress(['country' => null]);
    }

    public function test_street_address_missing_city_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('city');

        $this->streetAddress(['city' => null]);
    }

    public function test_street_address_missing_address_line_1_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('addressLine1');

        $this->streetAddress(['addressLine1' => null]);
    }

    public function test_street_address_with_empty_string_country_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->streetAddress(['country' => '']);
    }

    /** A STREET_ADDRESS construction that also supplies carrierCode must throw — exclusivity direction 1. */
    public function test_street_address_with_carrier_code_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('carrierCode');

        $this->streetAddress(['carrierCode' => 'econt']);
    }

    public function test_street_address_with_pickup_point_reference_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pickupPointReference');

        $this->streetAddress(['pickupPointReference' => 'office-1234']);
    }

    public function test_street_address_with_settlement_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('settlement');

        $this->streetAddress(['settlement' => 'Sofia']);
    }

    // --- PICKUP_POINT construction ---------------------------------------

    public function test_pickup_point_with_all_required_fields_succeeds(): void
    {
        $address = $this->pickupPoint();

        $this->assertSame(AddressDeliveryType::PICKUP_POINT, $address->deliveryType());
        $this->assertSame('econt', $address->carrierCode());
        $this->assertSame('office-1234', $address->pickupPointReference());
        $this->assertSame('Sofia', $address->settlement());
        $this->assertNull($address->country());
        $this->assertNull($address->city());
        $this->assertNull($address->postalCode());
        $this->assertNull($address->addressLine1());
        $this->assertNull($address->addressLine2());
    }

    public function test_pickup_point_missing_carrier_code_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('carrierCode');

        $this->pickupPoint(['carrierCode' => null]);
    }

    public function test_pickup_point_missing_pickup_point_reference_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pickupPointReference');

        $this->pickupPoint(['pickupPointReference' => null]);
    }

    public function test_pickup_point_missing_settlement_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('settlement');

        $this->pickupPoint(['settlement' => null]);
    }

    public function test_pickup_point_with_empty_string_carrier_code_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->pickupPoint(['carrierCode' => '']);
    }

    /** A PICKUP_POINT construction that also supplies country must throw — exclusivity direction 2. */
    public function test_pickup_point_with_country_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('country');

        $this->pickupPoint(['country' => 'BG']);
    }

    public function test_pickup_point_with_city_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('city');

        $this->pickupPoint(['city' => 'Sofia']);
    }

    public function test_pickup_point_with_postal_code_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('postalCode');

        $this->pickupPoint(['postalCode' => '1000']);
    }

    public function test_pickup_point_with_address_line_1_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('addressLine1');

        $this->pickupPoint(['addressLine1' => 'Vitosha Blvd 1']);
    }

    public function test_pickup_point_with_address_line_2_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('addressLine2');

        $this->pickupPoint(['addressLine2' => 'Floor 2']);
    }

    // --- recipientName / phone required regardless of type -----------------

    public function test_empty_recipient_name_throws_for_street_address(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->streetAddress(['recipientName' => '']);
    }

    public function test_empty_recipient_name_throws_for_pickup_point(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->pickupPoint(['recipientName' => '']);
    }

    public function test_whitespace_only_recipient_name_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->streetAddress(['recipientName' => '   ']);
    }

    public function test_empty_phone_throws_for_street_address(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->streetAddress(['phone' => '']);
    }

    public function test_empty_phone_throws_for_pickup_point(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->pickupPoint(['phone' => '']);
    }

    // --- accountId ---------------------------------------------------------

    public function test_null_account_id_succeeds(): void
    {
        $address = $this->streetAddress(['accountId' => null]);

        $this->assertNull($address->accountId());
    }

    public function test_non_empty_account_id_succeeds(): void
    {
        $address = $this->streetAddress(['accountId' => '42']);

        $this->assertSame('42', $address->accountId());
    }

    public function test_empty_string_account_id_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->streetAddress(['accountId' => '']);
    }

    // --- update() ------------------------------------------------------------

    public function test_update_overwrites_every_field_it_touches(): void
    {
        $address = $this->streetAddress(['accountId' => '42']);

        $address->update(
            deliveryType: AddressDeliveryType::STREET_ADDRESS,
            recipientName: 'New Name',
            phone: '+359888000000',
            country: 'BG',
            city: 'Plovdiv',
            postalCode: '4000',
            addressLine1: 'New St 1',
            addressLine2: 'Floor 3',
        );

        $this->assertSame('New Name', $address->recipientName());
        $this->assertSame('+359888000000', $address->phone());
        $this->assertSame('Plovdiv', $address->city());
        $this->assertSame('4000', $address->postalCode());
        $this->assertSame('New St 1', $address->addressLine1());
        $this->assertSame('Floor 3', $address->addressLine2());
        // accountId never changes via update().
        $this->assertSame('42', $address->accountId());
    }

    public function test_update_can_switch_delivery_type_from_street_address_to_pickup_point(): void
    {
        $address = $this->streetAddress();

        $address->update(
            deliveryType: AddressDeliveryType::PICKUP_POINT,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            carrierCode: 'speedy',
            pickupPointReference: 'office-9999',
            settlement: 'Varna',
        );

        $this->assertSame(AddressDeliveryType::PICKUP_POINT, $address->deliveryType());
        $this->assertSame('speedy', $address->carrierCode());
        $this->assertNull($address->country());
        $this->assertNull($address->addressLine1());
    }

    public function test_update_rejecting_exclusivity_violation_leaves_the_address_unchanged(): void
    {
        $address = $this->streetAddress();

        try {
            $address->update(
                deliveryType: AddressDeliveryType::STREET_ADDRESS,
                recipientName: 'Ivan Ivanov',
                phone: '+359888123456',
                country: 'BG',
                city: 'Sofia',
                addressLine1: 'Vitosha Blvd 1',
                carrierCode: 'econt',
            );
            $this->fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame('BG', $address->country());
        $this->assertNull($address->carrierCode());
    }

    public function test_update_with_empty_recipient_name_throws_and_leaves_the_address_unchanged(): void
    {
        $address = $this->streetAddress();

        try {
            $address->update(
                deliveryType: AddressDeliveryType::STREET_ADDRESS,
                recipientName: '',
                phone: '+359888123456',
                country: 'BG',
                city: 'Sofia',
                addressLine1: 'Vitosha Blvd 1',
            );
            $this->fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame('Ivan Ivanov', $address->recipientName());
    }

    // --- assignId() ------------------------------------------------------------

    public function test_id_can_only_be_assigned_once(): void
    {
        $address = $this->streetAddress();
        $address->assignId('1');

        $this->assertSame('1', $address->id());

        $this->expectException(LogicException::class);
        $address->assignId('2');
    }

    // --- reconstituteFromStorage() -----------------------------------------

    public function test_reconstitute_from_storage_round_trips_a_street_address(): void
    {
        $address = Address::reconstituteFromStorage(
            id: '5',
            accountId: '42',
            deliveryType: AddressDeliveryType::STREET_ADDRESS,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            country: 'BG',
            city: 'Sofia',
            postalCode: '1000',
            addressLine1: 'Vitosha Blvd 1',
            addressLine2: 'Floor 2',
            carrierCode: null,
            pickupPointReference: null,
            settlement: null,
        );

        $this->assertSame('5', $address->id());
        $this->assertSame('42', $address->accountId());
        $this->assertSame(AddressDeliveryType::STREET_ADDRESS, $address->deliveryType());
        $this->assertSame('Ivan Ivanov', $address->recipientName());
        $this->assertSame('+359888123456', $address->phone());
        $this->assertSame('BG', $address->country());
        $this->assertSame('Sofia', $address->city());
        $this->assertSame('1000', $address->postalCode());
        $this->assertSame('Vitosha Blvd 1', $address->addressLine1());
        $this->assertSame('Floor 2', $address->addressLine2());
    }

    public function test_reconstitute_from_storage_round_trips_a_pickup_point(): void
    {
        $address = Address::reconstituteFromStorage(
            id: '6',
            accountId: null,
            deliveryType: AddressDeliveryType::PICKUP_POINT,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            country: null,
            city: null,
            postalCode: null,
            addressLine1: null,
            addressLine2: null,
            carrierCode: 'econt',
            pickupPointReference: 'office-1234',
            settlement: 'Sofia',
        );

        $this->assertSame('6', $address->id());
        $this->assertNull($address->accountId());
        $this->assertSame(AddressDeliveryType::PICKUP_POINT, $address->deliveryType());
        $this->assertSame('econt', $address->carrierCode());
        $this->assertSame('office-1234', $address->pickupPointReference());
        $this->assertSame('Sofia', $address->settlement());
    }
}
