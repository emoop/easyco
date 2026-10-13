<?php

namespace EasyCo\Order\Tests;

use DateTimeImmutable;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Enums\OrderStatus;
use EasyCo\Order\Order;
use EasyCo\Pricing\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Checkout form v2, stage C1d-A: the same two rules as the Address entity, on the Order (the snapshot of the delivery): two name parts
 * (an empty LAST name only for an order from before the split) and a pick-up point that is IDENTIFIED or DESCRIBED.
 */
final class RecipientNameAndPickupPointOrderTest extends TestCase
{
    private function street(string $first = 'Ivan', string $last = 'Ivanov'): Order
    {
        return Order::create(
            clientId: 'c', transactionId: 't', email: 'b@example.com', currency: 'EUR', subtotal: Money::fromMinorUnits(1000, 'EUR'), discount: Money::zero('EUR'),
            deliveryType: OrderDeliveryType::STREET_ADDRESS, recipientFirstName: $first, recipientLastName: $last, phone: '+359888123456',
            placedAt: new DateTimeImmutable('2026-01-01 12:00:00'), country: 'BG', city: 'Sofia', addressLine1: 'V 1',
        );
    }

    private function pickup(?string $reference, ?string $address): Order
    {
        return Order::create(
            clientId: 'c', transactionId: 't', email: 'b@example.com', currency: 'EUR', subtotal: Money::fromMinorUnits(1000, 'EUR'), discount: Money::zero('EUR'),
            deliveryType: OrderDeliveryType::PICKUP_POINT, recipientFirstName: 'Ivan', recipientLastName: 'Ivanov', phone: '+359888123456',
            placedAt: new DateTimeImmutable('2026-01-01 12:00:00'), country: 'BG', carrierCode: 'econt', pickupPointReference: $reference, settlement: 'Sofia', pickupPointAddress: $address,
        );
    }

    /** @return array<string, array{string, string}> */
    public static function blankParts(): array
    {
        return [
            'an empty first name' => ['', 'Ivanov'],
            'a blank first name' => ['   ', 'Ivanov'],
            'an empty last name' => ['Ivan', ''],
            'a blank last name' => ['Ivan', ' '],
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
    public function test_the_order_editor_refuses_a_blank_part_and_changes_nothing(string $first, string $last): void
    {
        $order = $this->street();

        try {
            $order->reviseDelivery(OrderDeliveryType::STREET_ADDRESS, $first, $last, '+359888123456', 'BG', 'Plovdiv', null, 'X 1', null, null, null, null);
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException) {
            $this->assertSame('Ivan Ivanov', $order->recipientFullName());
            $this->assertSame('Sofia', $order->city());
        }
    }

    public function test_the_full_name_is_derived_and_the_parts_are_trimmed(): void
    {
        $order = $this->street(' Сия', 'Иванова ');

        $this->assertSame(['Сия', 'Иванова', 'Сия Иванова'], [$order->recipientFirstName(), $order->recipientLastName(), $order->recipientFullName()]);
    }

    public function test_an_order_from_before_the_split_loads_with_an_empty_last_name(): void
    {
        $order = Order::reconstituteFromStorage(
            '5', 'c', null, 't', 'b@example.com', 'EUR', Money::fromMinorUnits(1000, 'EUR'), Money::zero('EUR'), Money::zero('EUR'), null, null, Money::fromMinorUnits(1000, 'EUR'),
            null, OrderStatus::PLACED, new DateTimeImmutable('2026-01-01'), null, OrderDeliveryType::STREET_ADDRESS, 'Мадона', '', '+359888123456', 'BG', 'Sofia', null, 'V 1', null, null, null, null,
        );

        $this->assertSame('Мадона', $order->recipientFullName());
        $this->assertSame('', $order->recipientLastName());
    }

    public function test_a_described_point_an_identified_point_and_nothing_at_all(): void
    {
        $this->assertSame('Speedy office', $this->pickup(null, 'Speedy office')->pickupPointAddress());
        $this->assertSame('office-1', $this->pickup('office-1', null)->pickupPointReference());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pickupPointReference or a pickupPointAddress');

        $this->pickup(null, null);
    }

    public function test_an_edit_may_not_leave_a_pickup_order_with_neither(): void
    {
        $order = $this->pickup(null, 'Speedy office');

        $this->expectException(InvalidArgumentException::class);

        $order->reviseDelivery(OrderDeliveryType::PICKUP_POINT, 'Ivan', 'Ivanov', '+359888123456', 'BG', null, null, null, null, 'econt', null, 'Sofia', null, '   ');
    }

    public function test_an_edit_changes_the_text_of_a_described_point(): void
    {
        $order = $this->pickup(null, 'Speedy office');

        $order->reviseDelivery(OrderDeliveryType::PICKUP_POINT, 'Ivan', 'Ivanov', '+359888123456', 'BG', null, null, null, null, 'econt', null, 'Sofia', null, 'Econt locker, Mall');

        $this->assertSame('Econt locker, Mall', $order->pickupPointAddress());
        $this->assertNull($order->pickupPointReference());
    }
}
