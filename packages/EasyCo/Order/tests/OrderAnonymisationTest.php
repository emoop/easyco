<?php

namespace EasyCo\Order\Tests;

use DateTimeImmutable;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Enums\OrderStatus;
use EasyCo\Order\Exceptions\OrderNotEditableException;
use EasyCo\Order\Order;
use EasyCo\Pricing\Money;
use LogicException;
use PHPUnit\Framework\TestCase;

/** ACC-4b (acc-4-gdpr-design.md D8): the domain rule of the retention anonymisation: which fields, which statuses, one way, only after the cutoff. */
class OrderAnonymisationTest extends TestCase
{
    private const PLACED = '2026-03-05 10:00:00';

    private function order(OrderStatus $status = OrderStatus::DELIVERED, bool $pickup = false): Order
    {
        return Order::create(
            clientId: 'client-1',
            transactionId: 'transaction-1',
            email: 'Buyer@Example.com',
            currency: 'EUR',
            subtotal: Money::fromMinorUnits(2000, 'EUR'),
            discount: Money::fromMinorUnits(300, 'EUR'),
            deliveryType: $pickup ? OrderDeliveryType::PICKUP_POINT : OrderDeliveryType::STREET_ADDRESS,
            recipientFirstName: 'Ivan', recipientLastName: 'Ivanov',
            phone: '+359888123456',
            placedAt: new DateTimeImmutable(self::PLACED),
            status: $status,
            country: 'BG',
            city: $pickup ? null : 'Sofia',
            postalCode: $pickup ? null : '1000',
            addressLine1: $pickup ? null : 'Vitosha Blvd 1',
            addressLine2: $pickup ? null : 'Floor 2',
            carrierCode: $pickup ? 'econt' : null,
            pickupPointReference: $pickup ? 'office-9' : null,
            settlement: $pickup ? 'Sofia' : null,
            pickupPointName: $pickup ? 'Econt Center' : null,
            pickupPointAddress: $pickup ? 'Vitosha Blvd 100' : null,
            pickupPointKind: $pickup ? 'office' : null,
            region: $pickup ? null : 'Sofia-city',
            customerNote: 'Leave it with the neighbour, Mrs Petrova',
            marketingConsent: true,
            marketingConsentText: 'I agree to receive offers',
            recipientEmail: 'mum@example.com',
        );
    }

    private function cutoff(): DateTimeImmutable
    {
        return new DateTimeImmutable('2027-01-01 00:00:00');
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2037-03-05 03:10:00');
    }

    public function test_a_street_order_loses_its_personal_fields_and_keeps_everything_else(): void
    {
        $order = $this->order();
        $before = $this->facts($order);

        $order->anonymise($this->now(), $this->cutoff());

        $this->assertTrue($order->isAnonymised());
        $this->assertEquals($this->now(), $order->anonymisedAt());
        $this->assertSame(Order::ANONYMISED_EMAIL, $order->email());
        $this->assertSame(Order::ANONYMISED, $order->recipientFirstName());
        $this->assertSame(Order::ANONYMISED, $order->recipientLastName());
        $this->assertSame(Order::ANONYMISED, $order->phone());
        foreach (['postalCode', 'addressLine1', 'addressLine2', 'region', 'settlement', 'pickupPointReference', 'pickupPointName', 'pickupPointAddress', 'customerNote', 'recipientEmail'] as $nulled) {
            $this->assertNull($order->{$nulled}(), $nulled);
        }

        $after = $this->facts($order);
        foreach (array_keys($before) as $key) {
            if (! in_array($key, ['email', 'recipientFirstName', 'recipientLastName', 'phone', 'postalCode', 'addressLine1', 'addressLine2', 'region', 'settlement', 'pickupPointReference', 'pickupPointName', 'pickupPointAddress', 'customerNote', 'recipientEmail', 'anonymisedAt'], true)) {
                $this->assertSame($before[$key], $after[$key], "{$key} must not change");
            }
        }

        $this->assertSame('BG', $order->country());
        $this->assertSame('Sofia', $order->city());
        $this->assertTrue($order->marketingConsent(), 'the consent BOOLEAN stays');
        $this->assertSame(1700, $order->total()->minorValue());
    }

    public function test_a_pickup_order_keeps_one_description_because_the_database_requires_a_pickup_point_to_be_described(): void
    {
        $order = $this->order(OrderStatus::CANCELLED, pickup: true);

        $order->anonymise($this->now(), $this->cutoff());

        $this->assertSame(Order::ANONYMISED, $order->pickupPointAddress());
        $this->assertNull($order->pickupPointReference());
        $this->assertNull($order->pickupPointName());
        $this->assertSame('econt', $order->carrierCode());
        $this->assertSame('office', $order->pickupPointKind());
    }

    public function test_only_a_finished_order_may_be_anonymised(): void
    {
        foreach ([OrderStatus::PLACED, OrderStatus::CONFIRMED, OrderStatus::SHIPPED] as $status) {
            $order = $this->order($status);

            try {
                $order->anonymise($this->now(), $this->cutoff());
                $this->fail("{$status->value} must be refused");
            } catch (LogicException $e) {
                $this->assertStringContainsString('finished', $e->getMessage());
            }

            $this->assertFalse($order->isAnonymised());
            $this->assertSame('buyer@example.com', strtolower($order->email()), 'nothing was changed');
        }

        foreach ([OrderStatus::DELIVERED, OrderStatus::CANCELLED, OrderStatus::REFUNDED] as $status) {
            $order = $this->order($status);
            $order->anonymise($this->now(), $this->cutoff());
            $this->assertTrue($order->isAnonymised(), $status->value);
        }
    }

    public function test_an_order_placed_at_or_after_the_cutoff_is_refused(): void
    {
        $order = $this->order();

        foreach (['2026-03-05 10:00:00', '2026-03-05 09:59:59'] as $cutoff) {
            // placed AT the cutoff, and placed AFTER it: both are still within the retention period
            try {
                $order->anonymise($this->now(), new DateTimeImmutable($cutoff));
                $this->fail('the retention period has not ended');
            } catch (LogicException $e) {
                $this->assertStringContainsString('retention period has not ended', $e->getMessage());
            }
        }

        $order->anonymise($this->now(), new DateTimeImmutable('2026-03-05 10:00:01'));
        $this->assertTrue($order->isAnonymised(), 'placed one second before the cutoff');
    }

    public function test_it_is_one_way_a_second_call_is_refused_and_changes_nothing(): void
    {
        $order = $this->order();
        $order->anonymise($this->now(), $this->cutoff());
        $first = $this->facts($order);

        try {
            $order->anonymise(new DateTimeImmutable('2038-01-01'), $this->cutoff());
            $this->fail('a second anonymise() must be refused');
        } catch (LogicException $e) {
            $this->assertStringContainsString('already anonymised', $e->getMessage());
        }

        $this->assertSame($first, $this->facts($order));
    }

    public function test_an_anonymised_order_cannot_be_edited_and_the_reason_is_stable(): void
    {
        $order = $this->order();
        $order->anonymise($this->now(), $this->cutoff());

        try {
            $order->reviseDelivery(OrderDeliveryType::STREET_ADDRESS, 'A', 'B', '+359888000000', 'BG', 'Sofia', '1000', 'X 1', null, null, null, null);
            $this->fail('an anonymised order is never edited');
        } catch (OrderNotEditableException $e) {
            $this->assertTrue($e->isBecauseAnonymised());
            $this->assertSame('order_anonymised', $e->reason());
            $this->assertFalse($e->isBecauseOfSettledPayment());
        }
    }

    public function test_it_survives_storage(): void
    {
        $order = $this->order();
        $order->assignId('42');
        $order->anonymise($this->now(), $this->cutoff());

        $again = Order::reconstituteFromStorage(
            id: '42', clientId: 'client-1', accountId: null, transactionId: 'transaction-1', email: $order->email(), currency: 'EUR',
            subtotal: $order->subtotal(), discount: $order->discount(), shipping: $order->shipping(), shippingMethodName: null, shippingMethodCode: null,
            total: $order->total(), appliedPromotionCode: null, status: OrderStatus::DELIVERED, placedAt: $order->placedAt(), addressId: null,
            deliveryType: OrderDeliveryType::STREET_ADDRESS, recipientFirstName: $order->recipientFirstName(), recipientLastName: $order->recipientLastName(),
            phone: $order->phone(), country: 'BG', city: 'Sofia', postalCode: null, addressLine1: null, addressLine2: null, carrierCode: null,
            pickupPointReference: null, settlement: null, anonymisedAt: $order->anonymisedAt(),
        );

        $this->assertTrue($again->isAnonymised());
        $this->assertEquals($this->now(), $again->anonymisedAt());
    }

    /** @return array<string, mixed> */
    private function facts(Order $order): array
    {
        return [
            'email' => $order->email(), 'recipientFirstName' => $order->recipientFirstName(), 'recipientLastName' => $order->recipientLastName(), 'phone' => $order->phone(),
            'postalCode' => $order->postalCode(), 'addressLine1' => $order->addressLine1(), 'addressLine2' => $order->addressLine2(), 'region' => $order->region(),
            'settlement' => $order->settlement(), 'pickupPointReference' => $order->pickupPointReference(), 'pickupPointName' => $order->pickupPointName(),
            'pickupPointAddress' => $order->pickupPointAddress(), 'customerNote' => $order->customerNote(), 'recipientEmail' => $order->recipientEmail(),
            'anonymisedAt' => $order->anonymisedAt()?->format('c'),
            // everything that must NOT change
            'clientId' => $order->clientId(), 'transactionId' => $order->transactionId(), 'currency' => $order->currency()->code(),
            'subtotal' => $order->subtotal()->minorValue(), 'discount' => $order->discount()->minorValue(), 'shipping' => $order->shipping()->minorValue(), 'total' => $order->total()->minorValue(),
            'status' => $order->status()->value, 'placedAt' => $order->placedAt()->format('c'), 'deliveryType' => $order->deliveryType()->value,
            'country' => $order->country(), 'city' => $order->city(), 'carrierCode' => $order->carrierCode(), 'pickupPointKind' => $order->pickupPointKind(),
            'marketingConsent' => $order->marketingConsent(), 'marketingConsentText' => $order->marketingConsentText(), 'editRevision' => $order->editRevision(),
            'accountId' => $order->accountId(), 'addressId' => $order->addressId(),
        ];
    }
}
