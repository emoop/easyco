<?php

namespace EasyCo\Address\Tests;

use EasyCo\Address\Address;
use EasyCo\Address\Enums\AddressDeliveryType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Checkout form v2, stage C1d-A (checkout-form-v2-design.md section 2 and 3): the recipient's name is two parts (an empty LAST name only
 * for a row from before the split), and a pick-up point is IDENTIFIED (a reference) or DESCRIBED (pickupPointAddress), at least one.
 */
final class RecipientNameAndPickupPointTest extends TestCase
{
    private function street(string $first = 'Ivan', string $last = 'Ivanov'): Address
    {
        return Address::create(
            deliveryType: AddressDeliveryType::STREET_ADDRESS, recipientFirstName: $first, recipientLastName: $last, phone: '+359888123456',
            country: 'BG', city: 'Sofia', addressLine1: 'Vitosha Blvd 1',
        );
    }

    private function pickup(?string $reference, ?string $address, ?string $name = null): Address
    {
        return Address::create(
            deliveryType: AddressDeliveryType::PICKUP_POINT, recipientFirstName: 'Ivan', recipientLastName: 'Ivanov', phone: '+359888123456',
            country: 'BG', carrierCode: 'econt', pickupPointReference: $reference, settlement: 'Sofia', pickupPointName: $name, pickupPointAddress: $address,
        );
    }

    // ---- the name ------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{string, string}> */
    public static function blankParts(): array
    {
        return [
            'an empty first name' => ['', 'Ivanov'],
            'a blank first name' => ['   ', 'Ivanov'],
            'an empty last name' => ['Ivan', ''],
            'a blank last name' => ['Ivan', " \t "],
        ];
    }

    #[DataProvider('blankParts')]
    public function test_create_refuses_a_blank_first_or_last_name(string $first, string $last): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/recipient(First|Last)Name must not be empty/');

        $this->street($first, $last);
    }

    #[DataProvider('blankParts')]
    public function test_update_refuses_a_blank_first_or_last_name_and_changes_nothing(string $first, string $last): void
    {
        $address = $this->street();

        try {
            $address->update(AddressDeliveryType::STREET_ADDRESS, $first, $last, '+359888123456', 'BG', 'Plovdiv', addressLine1: 'X 1');
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException) {
            $this->assertSame('Ivan Ivanov', $address->recipientFullName());
            $this->assertSame('Sofia', $address->city());
        }
    }

    public function test_the_parts_are_trimmed_and_the_full_name_is_derived_from_them(): void
    {
        $address = $this->street('  Сия ', ' Иванова  ');

        $this->assertSame('Сия', $address->recipientFirstName());
        $this->assertSame('Иванова', $address->recipientLastName());
        $this->assertSame('Сия Иванова', $address->recipientFullName());
    }

    public function test_a_historical_row_with_an_empty_last_name_still_loads_and_its_full_name_is_the_first_name(): void
    {
        $address = Address::reconstituteFromStorage(
            '7', null, AddressDeliveryType::STREET_ADDRESS, 'Мадона', '', '+359888123456', 'BG', 'Sofia', null, 'V 1', null, null, null, null,
        );

        $this->assertSame('', $address->recipientLastName());
        $this->assertSame('Мадона', $address->recipientFullName());
    }

    public function test_the_first_name_is_never_empty_not_even_in_storage(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Address::reconstituteFromStorage('7', null, AddressDeliveryType::STREET_ADDRESS, '', 'Ivanov', '+359888123456', 'BG', 'Sofia', null, 'V 1', null, null, null, null);
    }

    // ---- the pick-up point ---------------------------------------------------------------------------------------------------

    public function test_a_described_point_needs_only_its_text(): void
    {
        $address = $this->pickup(null, '  Speedy office, Vitosha Blvd 100 ');

        $this->assertNull($address->pickupPointReference());
        $this->assertSame('Speedy office, Vitosha Blvd 100', $address->pickupPointAddress());
    }

    public function test_an_identified_point_needs_only_its_reference(): void
    {
        $address = $this->pickup('office-1234', null);

        $this->assertSame('office-1234', $address->pickupPointReference());
        $this->assertNull($address->pickupPointAddress());
    }

    public function test_a_point_with_neither_a_reference_nor_a_description_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pickupPointReference or a pickupPointAddress');

        $this->pickup(null, null);
    }

    public function test_a_blank_reference_is_never_a_reference(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->pickup('   ', 'Speedy office');
    }

    public function test_the_carrier_and_the_settlement_stay_required_for_a_described_point(): void
    {
        foreach (['carrierCode', 'settlement'] as $field) {
            try {
                Address::create(
                    deliveryType: AddressDeliveryType::PICKUP_POINT, recipientFirstName: 'I', recipientLastName: 'I', phone: '1', country: 'BG',
                    carrierCode: $field === 'carrierCode' ? null : 'econt', settlement: $field === 'settlement' ? null : 'Sofia', pickupPointAddress: 'Somewhere',
                );
                $this->fail("{$field} must stay required");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($field, $e->getMessage());
            }
        }
    }

    public function test_a_street_address_still_refuses_every_pickup_field(): void
    {
        foreach (['pickupPointReference' => 'o-1', 'carrierCode' => 'econt', 'settlement' => 'Sofia', 'pickupPointName' => 'N', 'pickupPointAddress' => 'A'] as $field => $value) {
            try {
                Address::create(...array_merge([
                    'deliveryType' => AddressDeliveryType::STREET_ADDRESS, 'recipientFirstName' => 'I', 'recipientLastName' => 'I', 'phone' => '1', 'country' => 'BG',
                    'city' => 'Sofia', 'addressLine1' => 'V 1',
                ], [$field => $value]));
                $this->fail("{$field} must be refused for a street address");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($field, $e->getMessage());
            }
        }
    }

    public function test_an_existing_row_with_all_three_values_loads_and_reads_back_unchanged(): void
    {
        $address = Address::reconstituteFromStorage(
            '9', null, AddressDeliveryType::PICKUP_POINT, 'Ivan', 'Ivanov', '+359888123456', 'BG', null, null, null, null, 'econt', 'office-1', 'Sofia', 'Econt Center', 'Vitosha Blvd 100',
        );

        $this->assertSame(['office-1', 'Econt Center', 'Vitosha Blvd 100'], [$address->pickupPointReference(), $address->pickupPointName(), $address->pickupPointAddress()]);
    }

    public function test_an_update_keeps_the_same_invariant(): void
    {
        $address = $this->pickup('office-1', null);

        $this->expectException(InvalidArgumentException::class);

        $address->update(AddressDeliveryType::PICKUP_POINT, 'Ivan', 'Ivanov', '+359888123456', 'BG', carrierCode: 'econt', pickupPointReference: null, settlement: 'Sofia');
    }
}
