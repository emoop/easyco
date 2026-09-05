<?php

namespace EasyCo\Order\Tests;

use DateTimeImmutable;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Enums\OrderStatus;
use EasyCo\Order\Order;
use EasyCo\Pricing\Money;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class OrderTest extends TestCase
{
    /**
     * array_key_exists(), NOT ?? — an override explicitly set to null
     * must actually pass null through. ?? treats an explicit null the
     * same as "key absent," which would silently fall back to the
     * default instead. Same helper pattern as AddressTest::value().
     */
    private function value(array $overrides, string $key, mixed $default): mixed
    {
        return array_key_exists($key, $overrides) ? $overrides[$key] : $default;
    }

    private function placedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01 12:00:00');
    }

    private function streetAddressOrder(array $overrides = []): Order
    {
        return Order::create(
            clientId: $this->value($overrides, 'clientId', 'client-1'),
            transactionId: $this->value($overrides, 'transactionId', 'transaction-1'),
            email: $this->value($overrides, 'email', 'buyer@example.com'),
            currency: $this->value($overrides, 'currency', 'EUR'),
            subtotal: $this->value($overrides, 'subtotal', Money::fromMinorUnits(1000, 'EUR')),
            discount: $this->value($overrides, 'discount', Money::fromMinorUnits(300, 'EUR')),
            deliveryType: OrderDeliveryType::STREET_ADDRESS,
            recipientName: $this->value($overrides, 'recipientName', 'Ivan Ivanov'),
            phone: $this->value($overrides, 'phone', '+359888123456'),
            placedAt: $this->value($overrides, 'placedAt', $this->placedAt()),
            accountId: $this->value($overrides, 'accountId', null),
            appliedPromotionCode: $this->value($overrides, 'appliedPromotionCode', null),
            status: $this->value($overrides, 'status', OrderStatus::PLACED),
            addressId: $this->value($overrides, 'addressId', null),
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

    private function pickupPointOrder(array $overrides = []): Order
    {
        return Order::create(
            clientId: $this->value($overrides, 'clientId', 'client-1'),
            transactionId: $this->value($overrides, 'transactionId', 'transaction-1'),
            email: $this->value($overrides, 'email', 'buyer@example.com'),
            currency: $this->value($overrides, 'currency', 'EUR'),
            subtotal: $this->value($overrides, 'subtotal', Money::fromMinorUnits(1000, 'EUR')),
            discount: $this->value($overrides, 'discount', Money::fromMinorUnits(300, 'EUR')),
            deliveryType: OrderDeliveryType::PICKUP_POINT,
            recipientName: $this->value($overrides, 'recipientName', 'Ivan Ivanov'),
            phone: $this->value($overrides, 'phone', '+359888123456'),
            placedAt: $this->value($overrides, 'placedAt', $this->placedAt()),
            accountId: $this->value($overrides, 'accountId', null),
            appliedPromotionCode: $this->value($overrides, 'appliedPromotionCode', null),
            status: $this->value($overrides, 'status', OrderStatus::PLACED),
            addressId: $this->value($overrides, 'addressId', null),
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

    // --- construction, both delivery types ----------------------------------

    public function test_street_address_order_with_valid_fields_succeeds(): void
    {
        $order = $this->streetAddressOrder();

        $this->assertNull($order->id());
        $this->assertSame('client-1', $order->clientId());
        $this->assertSame('transaction-1', $order->transactionId());
        $this->assertSame('buyer@example.com', $order->email());
        $this->assertSame('EUR', $order->currency()->code());
        $this->assertSame(OrderDeliveryType::STREET_ADDRESS, $order->deliveryType());
        $this->assertSame('BG', $order->country());
        $this->assertSame('Sofia', $order->city());
        $this->assertSame('Vitosha Blvd 1', $order->addressLine1());
        $this->assertNull($order->carrierCode());
    }

    public function test_pickup_point_order_with_valid_fields_succeeds(): void
    {
        $order = $this->pickupPointOrder();

        $this->assertSame(OrderDeliveryType::PICKUP_POINT, $order->deliveryType());
        $this->assertSame('econt', $order->carrierCode());
        $this->assertSame('office-1234', $order->pickupPointReference());
        $this->assertSame('Sofia', $order->settlement());
        $this->assertNull($order->country());
        $this->assertNull($order->addressLine1());
    }

    // --- required-not-empty fields -------------------------------------------

    public function test_empty_email_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('email');

        $this->streetAddressOrder(['email' => '']);
    }

    public function test_empty_recipient_name_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('recipientName');

        $this->streetAddressOrder(['recipientName' => '']);
    }

    public function test_empty_phone_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('phone');

        $this->streetAddressOrder(['phone' => '']);
    }

    public function test_empty_client_id_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('clientId');

        $this->streetAddressOrder(['clientId' => '']);
    }

    public function test_empty_transaction_id_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('transactionId');

        $this->streetAddressOrder(['transactionId' => '']);
    }

    // --- accountId -----------------------------------------------------------

    public function test_null_account_id_succeeds(): void
    {
        $order = $this->streetAddressOrder(['accountId' => null]);

        $this->assertNull($order->accountId());
    }

    public function test_non_empty_account_id_succeeds(): void
    {
        $order = $this->streetAddressOrder(['accountId' => '42']);

        $this->assertSame('42', $order->accountId());
    }

    public function test_empty_string_account_id_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->streetAddressOrder(['accountId' => '']);
    }

    // --- currency / total ------------------------------------------------------

    public function test_mismatched_subtotal_and_discount_currency_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->streetAddressOrder([
            'subtotal' => Money::fromMinorUnits(1000, 'EUR'),
            'discount' => Money::fromMinorUnits(300, 'BGN'),
        ]);
    }

    public function test_discount_exceeding_subtotal_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->streetAddressOrder([
            'subtotal' => Money::fromMinorUnits(500, 'EUR'),
            'discount' => Money::fromMinorUnits(600, 'EUR'),
        ]);
    }

    public function test_total_is_genuinely_computed_from_subtotal_and_discount(): void
    {
        $order = $this->streetAddressOrder([
            'subtotal' => Money::fromMinorUnits(1000, 'EUR'),
            'discount' => Money::fromMinorUnits(300, 'EUR'),
        ]);

        $this->assertSame(700, $order->total()->minorValue());
        $this->assertSame('EUR', $order->total()->currency()->code());
    }

    // --- STREET_ADDRESS vs PICKUP_POINT exclusivity, both directions -------

    public function test_street_address_missing_country_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('country');

        $this->streetAddressOrder(['country' => null]);
    }

    public function test_street_address_missing_city_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('city');

        $this->streetAddressOrder(['city' => null]);
    }

    public function test_street_address_missing_address_line_1_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('addressLine1');

        $this->streetAddressOrder(['addressLine1' => null]);
    }

    public function test_street_address_with_carrier_code_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('carrierCode');

        $this->streetAddressOrder(['carrierCode' => 'econt']);
    }

    public function test_street_address_with_pickup_point_reference_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pickupPointReference');

        $this->streetAddressOrder(['pickupPointReference' => 'office-1234']);
    }

    public function test_street_address_with_settlement_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('settlement');

        $this->streetAddressOrder(['settlement' => 'Sofia']);
    }

    public function test_pickup_point_missing_carrier_code_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('carrierCode');

        $this->pickupPointOrder(['carrierCode' => null]);
    }

    public function test_pickup_point_missing_pickup_point_reference_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pickupPointReference');

        $this->pickupPointOrder(['pickupPointReference' => null]);
    }

    public function test_pickup_point_missing_settlement_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('settlement');

        $this->pickupPointOrder(['settlement' => null]);
    }

    public function test_pickup_point_with_country_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('country');

        $this->pickupPointOrder(['country' => 'BG']);
    }

    public function test_pickup_point_with_city_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('city');

        $this->pickupPointOrder(['city' => 'Sofia']);
    }

    public function test_pickup_point_with_postal_code_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('postalCode');

        $this->pickupPointOrder(['postalCode' => '1000']);
    }

    public function test_pickup_point_with_address_line_1_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('addressLine1');

        $this->pickupPointOrder(['addressLine1' => 'Vitosha Blvd 1']);
    }

    public function test_pickup_point_with_address_line_2_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('addressLine2');

        $this->pickupPointOrder(['addressLine2' => 'Floor 2']);
    }

    // --- status default --------------------------------------------------------

    public function test_status_defaults_to_placed_when_omitted(): void
    {
        $order = $this->streetAddressOrder();

        $this->assertSame(OrderStatus::PLACED, $order->status());
    }

    public function test_status_can_be_explicitly_set(): void
    {
        $order = $this->streetAddressOrder(['status' => OrderStatus::CANCELLED]);

        $this->assertSame(OrderStatus::CANCELLED, $order->status());
    }

    // --- assignId() ------------------------------------------------------------

    public function test_id_can_only_be_assigned_once(): void
    {
        $order = $this->streetAddressOrder();
        $order->assignId('1');

        $this->assertSame('1', $order->id());

        $this->expectException(LogicException::class);
        $order->assignId('2');
    }

    // --- reconstituteFromStorage() -----------------------------------------

    public function test_reconstitute_from_storage_round_trips_a_street_address_order(): void
    {
        $placedAt = $this->placedAt();

        $order = Order::reconstituteFromStorage(
            id: '9',
            clientId: 'client-9',
            accountId: '42',
            transactionId: 'transaction-9',
            email: 'buyer@example.com',
            currency: 'EUR',
            subtotal: Money::fromMinorUnits(1000, 'EUR'),
            discount: Money::fromMinorUnits(300, 'EUR'),
            total: Money::fromMinorUnits(700, 'EUR'),
            appliedPromotionCode: 'summer20',
            status: OrderStatus::FULFILLED,
            placedAt: $placedAt,
            addressId: '7',
            deliveryType: OrderDeliveryType::STREET_ADDRESS,
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

        $this->assertSame('9', $order->id());
        $this->assertSame('client-9', $order->clientId());
        $this->assertSame('42', $order->accountId());
        $this->assertSame('transaction-9', $order->transactionId());
        $this->assertSame('buyer@example.com', $order->email());
        $this->assertSame('EUR', $order->currency()->code());
        $this->assertSame(1000, $order->subtotal()->minorValue());
        $this->assertSame(300, $order->discount()->minorValue());
        $this->assertSame(700, $order->total()->minorValue());
        $this->assertSame('summer20', $order->appliedPromotionCode());
        $this->assertSame(OrderStatus::FULFILLED, $order->status());
        $this->assertSame($placedAt, $order->placedAt());
        $this->assertSame('7', $order->addressId());
        $this->assertSame('Floor 2', $order->addressLine2());
    }

    public function test_reconstitute_from_storage_round_trips_a_pickup_point_order(): void
    {
        $order = Order::reconstituteFromStorage(
            id: '10',
            clientId: 'client-10',
            accountId: null,
            transactionId: 'transaction-10',
            email: 'guest@example.com',
            currency: 'EUR',
            subtotal: Money::fromMinorUnits(1000, 'EUR'),
            discount: Money::fromMinorUnits(0, 'EUR'),
            total: Money::fromMinorUnits(1000, 'EUR'),
            appliedPromotionCode: null,
            status: OrderStatus::PLACED,
            placedAt: $this->placedAt(),
            addressId: null,
            deliveryType: OrderDeliveryType::PICKUP_POINT,
            recipientName: 'Maria Petrova',
            phone: '+359888654321',
            country: null,
            city: null,
            postalCode: null,
            addressLine1: null,
            addressLine2: null,
            carrierCode: 'econt',
            pickupPointReference: 'office-1234',
            settlement: 'Plovdiv',
        );

        $this->assertSame(OrderDeliveryType::PICKUP_POINT, $order->deliveryType());
        $this->assertSame('econt', $order->carrierCode());
        $this->assertNull($order->accountId());
        $this->assertNull($order->appliedPromotionCode());
    }

    /**
     * reconstituteFromStorage() must TRUST the given total exactly, not
     * silently recompute or "correct" it to subtotal-discount — this
     * proves it doesn't. (1000 - 300 = 700, but 800 is passed and must
     * come back untouched.)
     */
    public function test_reconstitute_from_storage_trusts_the_given_total_without_recomputing(): void
    {
        $order = Order::reconstituteFromStorage(
            id: '11',
            clientId: 'client-11',
            accountId: null,
            transactionId: 'transaction-11',
            email: 'buyer@example.com',
            currency: 'EUR',
            subtotal: Money::fromMinorUnits(1000, 'EUR'),
            discount: Money::fromMinorUnits(300, 'EUR'),
            total: Money::fromMinorUnits(800, 'EUR'),
            appliedPromotionCode: null,
            status: OrderStatus::PLACED,
            placedAt: $this->placedAt(),
            addressId: null,
            deliveryType: OrderDeliveryType::STREET_ADDRESS,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            country: 'BG',
            city: 'Sofia',
            postalCode: '1000',
            addressLine1: 'Vitosha Blvd 1',
            addressLine2: null,
            carrierCode: null,
            pickupPointReference: null,
            settlement: null,
        );

        $this->assertSame(800, $order->total()->minorValue());
    }

    public function test_reconstitute_from_storage_still_rejects_a_negative_total(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Order::reconstituteFromStorage(
            id: '12',
            clientId: 'client-12',
            accountId: null,
            transactionId: 'transaction-12',
            email: 'buyer@example.com',
            currency: 'EUR',
            subtotal: Money::fromMinorUnits(500, 'EUR'),
            discount: Money::fromMinorUnits(300, 'EUR'),
            total: Money::fromMinorUnits(-1, 'EUR'),
            appliedPromotionCode: null,
            status: OrderStatus::PLACED,
            placedAt: $this->placedAt(),
            addressId: null,
            deliveryType: OrderDeliveryType::STREET_ADDRESS,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            country: 'BG',
            city: 'Sofia',
            postalCode: '1000',
            addressLine1: 'Vitosha Blvd 1',
            addressLine2: null,
            carrierCode: null,
            pickupPointReference: null,
            settlement: null,
        );
    }
}
