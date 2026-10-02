<?php

namespace EasyCo\Order\Tests;

use DateTimeImmutable;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Enums\OrderStatus;
use EasyCo\Order\Exceptions\OrderNotEditableException;
use EasyCo\Order\Order;
use EasyCo\Pricing\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * order-editing-design.md §2/§2.2/§3, stage 2 — Order::reviseTotals() and
 * Order::reviseDelivery(), the two new edit mutators, plus
 * assertFieldsMatchDeliveryType()'s narrowed rule (§3, D4). Standalone
 * suite, no Laravel — the aggregate and nothing else
 * (packages/EasyCo/Order/phpunit.xml).
 */
final class OrderEditingTest extends TestCase
{
    /** The street-address fixture, with addressId set (to prove reviseDelivery() never touches it) and status under test. */
    private function order(OrderStatus $status = OrderStatus::PLACED): Order
    {
        return Order::create(
            clientId: 'client-1',
            transactionId: 'transaction-1',
            email: 'buyer@example.com',
            currency: 'EUR',
            subtotal: Money::fromMinorUnits(1000, 'EUR'),
            discount: Money::fromMinorUnits(300, 'EUR'),
            deliveryType: OrderDeliveryType::STREET_ADDRESS,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            placedAt: new DateTimeImmutable('2026-01-01 12:00:00'),
            status: $status,
            addressId: 'address-1',
            country: 'BG',
            city: 'Sofia',
            postalCode: '1000',
            addressLine1: 'Vitosha Blvd 1',
        );
    }

    private function pickupPointOrder(OrderStatus $status = OrderStatus::PLACED): Order
    {
        return Order::create(
            clientId: 'client-1',
            transactionId: 'transaction-1',
            email: 'buyer@example.com',
            currency: 'EUR',
            subtotal: Money::fromMinorUnits(1000, 'EUR'),
            discount: Money::fromMinorUnits(300, 'EUR'),
            deliveryType: OrderDeliveryType::PICKUP_POINT,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            placedAt: new DateTimeImmutable('2026-01-01 12:00:00'),
            status: $status,
            addressId: 'address-1',
            carrierCode: 'econt',
            pickupPointReference: 'office-1',
            settlement: 'Sofia district',
        );
    }

    // --- editRevision (stage 3b, D1) ---------------------------------------

    public function test_edit_revision_defaults_to_zero_for_a_fresh_order(): void
    {
        $this->assertSame(0, $this->order()->editRevision());
    }

    public function test_edit_revision_round_trips_through_reconstitute_from_storage(): void
    {
        $order = Order::reconstituteFromStorage(
            id: '9',
            clientId: 'client-9',
            accountId: null,
            transactionId: 'transaction-9',
            email: 'buyer@example.com',
            currency: 'EUR',
            subtotal: Money::fromMinorUnits(1000, 'EUR'),
            discount: Money::fromMinorUnits(0, 'EUR'),
            shipping: Money::fromMinorUnits(0, 'EUR'),
            shippingMethodName: null,
            shippingMethodCode: null,
            total: Money::fromMinorUnits(1000, 'EUR'),
            appliedPromotionCode: null,
            status: OrderStatus::PLACED,
            placedAt: new DateTimeImmutable('2026-01-01 12:00:00'),
            addressId: null,
            deliveryType: OrderDeliveryType::STREET_ADDRESS,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            country: 'BG',
            city: 'Sofia',
            postalCode: null,
            addressLine1: 'Vitosha Blvd 1',
            addressLine2: null,
            carrierCode: null,
            pickupPointReference: null,
            settlement: null,
            editRevision: 4,
        );

        $this->assertSame(4, $order->editRevision());
    }

    public function test_reconstitute_from_storage_without_an_edit_revision_defaults_to_zero(): void
    {
        $order = Order::reconstituteFromStorage(
            id: '9',
            clientId: 'client-9',
            accountId: null,
            transactionId: 'transaction-9',
            email: 'buyer@example.com',
            currency: 'EUR',
            subtotal: Money::fromMinorUnits(1000, 'EUR'),
            discount: Money::fromMinorUnits(0, 'EUR'),
            shipping: Money::fromMinorUnits(0, 'EUR'),
            shippingMethodName: null,
            shippingMethodCode: null,
            total: Money::fromMinorUnits(1000, 'EUR'),
            appliedPromotionCode: null,
            status: OrderStatus::PLACED,
            placedAt: new DateTimeImmutable('2026-01-01 12:00:00'),
            addressId: null,
            deliveryType: OrderDeliveryType::STREET_ADDRESS,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            country: 'BG',
            city: 'Sofia',
            postalCode: null,
            addressLine1: 'Vitosha Blvd 1',
            addressLine2: null,
            carrierCode: null,
            pickupPointReference: null,
            settlement: null,
        );

        $this->assertSame(0, $order->editRevision());
    }

    public function test_bump_edit_revision_increments_by_exactly_one_each_call(): void
    {
        $order = $this->order();

        $order->bumpEditRevision();
        $this->assertSame(1, $order->editRevision());

        $order->bumpEditRevision();
        $order->bumpEditRevision();
        $this->assertSame(3, $order->editRevision());
    }

    public function test_bump_edit_revision_touches_nothing_else(): void
    {
        $order = $this->order();
        $before = $this->everythingExcept($order, ["status"]);

        $order->bumpEditRevision();

        $this->assertSame($before, $this->everythingExcept($order, ["status"]));
        $this->assertSame(OrderStatus::PLACED, $order->status());
    }

    public function test_a_negative_edit_revision_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Order::create(
            clientId: 'client-1',
            transactionId: 'transaction-1',
            email: 'buyer@example.com',
            currency: 'EUR',
            subtotal: Money::fromMinorUnits(1000, 'EUR'),
            discount: Money::fromMinorUnits(0, 'EUR'),
            deliveryType: OrderDeliveryType::STREET_ADDRESS,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            placedAt: new DateTimeImmutable('2026-01-01 12:00:00'),
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
            editRevision: -1,
        );
    }

    public function test_the_settled_payment_refusal_is_the_same_exception_type_and_says_which_reason_it_is(): void
    {
        $status = OrderNotEditableException::because(OrderStatus::SHIPPED);
        $money = OrderNotEditableException::becausePaymentSettled(OrderStatus::PLACED);

        $this->assertFalse($status->isBecauseOfSettledPayment());
        $this->assertTrue($money->isBecauseOfSettledPayment());
        $this->assertSame(OrderStatus::PLACED, $money->status());
        $this->assertStringContainsString('settled', $money->getMessage());
        $this->assertStringNotContainsString('legal only while placed or confirmed', $money->getMessage());
    }

    public static function editableStatusesProvider(): array
    {
        return [
            'placed' => [OrderStatus::PLACED],
            'confirmed' => [OrderStatus::CONFIRMED],
        ];
    }

    public static function nonEditableStatusesProvider(): array
    {
        return [
            'shipped' => [OrderStatus::SHIPPED],
            'delivered' => [OrderStatus::DELIVERED],
            'cancelled' => [OrderStatus::CANCELLED],
            'refunded' => [OrderStatus::REFUNDED],
        ];
    }

    // --- reviseTotals() ---------------------------------------------------

    #[DataProvider('editableStatusesProvider')]
    public function test_revise_totals_succeeds_from_placed_and_confirmed(OrderStatus $status): void
    {
        $order = $this->order($status);

        $order->reviseTotals(
            Money::fromMinorUnits(2000, 'EUR'),
            Money::fromMinorUnits(500, 'EUR'),
            'NEWCODE',
        );

        $this->assertSame(2000, $order->subtotal()->minorValue());
        $this->assertSame(500, $order->discount()->minorValue());
        $this->assertSame(1500, $order->total()->minorValue());
        $this->assertSame('NEWCODE', $order->appliedPromotionCode());
        $this->assertSame($status, $order->status(), 'reviseTotals() must never move the status.');
    }

    #[DataProvider('nonEditableStatusesProvider')]
    public function test_revise_totals_is_refused_outside_placed_and_confirmed(OrderStatus $status): void
    {
        $order = $this->order($status);

        try {
            $order->reviseTotals(
                Money::fromMinorUnits(2000, 'EUR'),
                Money::fromMinorUnits(500, 'EUR'),
                'NEWCODE',
            );
            $this->fail("reviseTotals() must refuse from status \"{$status->value}\".");
        } catch (OrderNotEditableException $e) {
            $this->assertSame($status, $e->status());
        }

        // Nothing changed.
        $this->assertSame(1000, $order->subtotal()->minorValue());
        $this->assertSame(300, $order->discount()->minorValue());
        $this->assertSame(700, $order->total()->minorValue());
        $this->assertNull($order->appliedPromotionCode());
    }

    public function test_revise_totals_changes_exactly_the_four_intended_fields_and_nothing_else(): void
    {
        $order = $this->order();
        $before = $this->everythingExcept($order, ['subtotal', 'discount', 'total', 'appliedPromotionCode']);

        $order->reviseTotals(
            Money::fromMinorUnits(2000, 'EUR'),
            Money::fromMinorUnits(500, 'EUR'),
            'NEWCODE',
        );

        $after = $this->everythingExcept($order, ['subtotal', 'discount', 'total', 'appliedPromotionCode']);
        $this->assertSame($before, $after, 'every field other than the four revised ones must be untouched.');
    }

    // --- reviseDelivery() ---------------------------------------------------

    #[DataProvider('editableStatusesProvider')]
    public function test_revise_delivery_succeeds_from_placed_and_confirmed(OrderStatus $status): void
    {
        $order = $this->order($status);

        $order->reviseDelivery(
            deliveryType: OrderDeliveryType::STREET_ADDRESS,
            recipientName: 'Petar Petrov',
            phone: '+359888999999',
            country: 'BG',
            city: 'Plovdiv',
            postalCode: '4000',
            addressLine1: 'Main St 5',
            addressLine2: 'Floor 3',
            carrierCode: 'speedy',
            pickupPointReference: null,
            settlement: null,
        );

        $this->assertSame(OrderDeliveryType::STREET_ADDRESS, $order->deliveryType());
        $this->assertSame('Petar Petrov', $order->recipientName());
        $this->assertSame('+359888999999', $order->phone());
        $this->assertSame('BG', $order->country());
        $this->assertSame('Plovdiv', $order->city());
        $this->assertSame('4000', $order->postalCode());
        $this->assertSame('Main St 5', $order->addressLine1());
        $this->assertSame('Floor 3', $order->addressLine2());
        $this->assertSame('speedy', $order->carrierCode());
        $this->assertNull($order->pickupPointReference());
        $this->assertNull($order->settlement());
        $this->assertSame($status, $order->status(), 'reviseDelivery() must never move the status.');
    }

    #[DataProvider('nonEditableStatusesProvider')]
    public function test_revise_delivery_is_refused_outside_placed_and_confirmed(OrderStatus $status): void
    {
        $order = $this->order($status);

        try {
            $order->reviseDelivery(
                deliveryType: OrderDeliveryType::STREET_ADDRESS,
                recipientName: 'Petar Petrov',
                phone: '+359888999999',
                country: 'BG',
                city: 'Plovdiv',
                postalCode: '4000',
                addressLine1: 'Main St 5',
                addressLine2: null,
                carrierCode: null,
                pickupPointReference: null,
                settlement: null,
            );
            $this->fail("reviseDelivery() must refuse from status \"{$status->value}\".");
        } catch (OrderNotEditableException $e) {
            $this->assertSame($status, $e->status());
        }

        $this->assertSame('Ivan Ivanov', $order->recipientName());
        $this->assertSame('Sofia', $order->city());
    }

    public function test_revise_delivery_changes_exactly_the_eleven_intended_fields_and_nothing_else(): void
    {
        $order = $this->order();
        $before = $this->everythingExcept($order, [
            'deliveryType', 'recipientName', 'phone', 'country', 'city',
            'postalCode', 'addressLine1', 'addressLine2', 'carrierCode',
            'pickupPointReference', 'settlement',
        ]);

        $order->reviseDelivery(
            deliveryType: OrderDeliveryType::PICKUP_POINT,
            recipientName: 'Petar Petrov',
            phone: '+359888999999',
            country: null,
            city: null,
            postalCode: null,
            addressLine1: null,
            addressLine2: null,
            carrierCode: 'econt',
            pickupPointReference: 'office-99',
            settlement: 'Plovdiv district',
        );

        $after = $this->everythingExcept($order, [
            'deliveryType', 'recipientName', 'phone', 'country', 'city',
            'postalCode', 'addressLine1', 'addressLine2', 'carrierCode',
            'pickupPointReference', 'settlement',
        ]);
        $this->assertSame($before, $after, 'every field other than the eleven revised ones must be untouched.');
    }

    /**
     * order-editing-design.md §2 (D2's own architect decision, §0 item 1's
     * gap) — addressId is NEVER a parameter of reviseDelivery() and is
     * never touched by it: provenance ("which saved address this order
     * started from"), not a live pointer the edit re-targets. Proven
     * before/after, not merely "no parameter exists for it."
     */
    public function test_revise_delivery_never_touches_address_id(): void
    {
        $order = $this->order();
        $this->assertSame('address-1', $order->addressId());

        $order->reviseDelivery(
            deliveryType: OrderDeliveryType::PICKUP_POINT,
            recipientName: 'Petar Petrov',
            phone: '+359888999999',
            country: null,
            city: null,
            postalCode: null,
            addressLine1: null,
            addressLine2: null,
            carrierCode: 'econt',
            pickupPointReference: 'office-99',
            settlement: 'Plovdiv district',
        );

        $this->assertSame('address-1', $order->addressId(), 'addressId must survive a delivery edit unchanged.');
    }

    /**
     * The guard is real per-call, not just per-construction: an order built
     * editable, then transitioned to a non-editable status, must refuse
     * BOTH mutators afterward — proven by calling reviseTotals() then
     * reviseDelivery() on the same now-shipped order in one test.
     */
    public function test_both_mutators_refuse_once_the_order_has_moved_past_confirmed(): void
    {
        $order = $this->order(OrderStatus::PLACED);
        $order->confirm();
        $order->ship();

        $this->assertSame(OrderStatus::SHIPPED, $order->status());

        try {
            $order->reviseTotals(Money::fromMinorUnits(2000, 'EUR'), Money::fromMinorUnits(0, 'EUR'), null);
            $this->fail('reviseTotals() must refuse once shipped.');
        } catch (OrderNotEditableException $e) {
            $this->assertSame(OrderStatus::SHIPPED, $e->status());
        }

        try {
            $order->reviseDelivery(
                deliveryType: OrderDeliveryType::STREET_ADDRESS,
                recipientName: 'X',
                phone: '+359888000000',
                country: 'BG',
                city: 'Sofia',
                postalCode: null,
                addressLine1: 'Y',
                addressLine2: null,
                carrierCode: null,
                pickupPointReference: null,
                settlement: null,
            );
            $this->fail('reviseDelivery() must refuse once shipped.');
        } catch (OrderNotEditableException $e) {
            $this->assertSame(OrderStatus::SHIPPED, $e->status());
        }
    }

    // --- assertFieldsMatchDeliveryType()'s narrowed rule (§3, D4) -----------

    public function test_street_address_with_carrier_code_succeeds_via_create(): void
    {
        $order = Order::create(
            clientId: 'client-1',
            transactionId: 'transaction-1',
            email: 'buyer@example.com',
            currency: 'EUR',
            subtotal: Money::fromMinorUnits(1000, 'EUR'),
            discount: Money::zero('EUR'),
            deliveryType: OrderDeliveryType::STREET_ADDRESS,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            placedAt: new DateTimeImmutable('2026-01-01'),
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
            carrierCode: 'econt',
        );

        $this->assertSame('econt', $order->carrierCode());
    }

    public function test_street_address_with_carrier_code_succeeds_via_revise_delivery(): void
    {
        $order = $this->order();

        $order->reviseDelivery(
            deliveryType: OrderDeliveryType::STREET_ADDRESS,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            country: 'BG',
            city: 'Sofia',
            postalCode: '1000',
            addressLine1: 'Vitosha Blvd 1',
            addressLine2: null,
            carrierCode: 'econt',
            pickupPointReference: null,
            settlement: null,
        );

        $this->assertSame('econt', $order->carrierCode());
    }

    public function test_street_address_with_pickup_point_reference_still_refuses_via_revise_delivery(): void
    {
        $order = $this->order();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('pickupPointReference');

        $order->reviseDelivery(
            deliveryType: OrderDeliveryType::STREET_ADDRESS,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            country: 'BG',
            city: 'Sofia',
            postalCode: '1000',
            addressLine1: 'Vitosha Blvd 1',
            addressLine2: null,
            carrierCode: null,
            pickupPointReference: 'office-1',
            settlement: null,
        );
    }

    public function test_street_address_with_settlement_still_refuses_via_revise_delivery(): void
    {
        $order = $this->order();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('settlement');

        $order->reviseDelivery(
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
            settlement: 'Sofia district',
        );
    }

    /** PICKUP_POINT's own rule set is provably unchanged — via reviseDelivery() too, not only create(). */
    public function test_pickup_point_missing_carrier_code_still_refuses_via_revise_delivery(): void
    {
        $order = $this->pickupPointOrder();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('carrierCode');

        $order->reviseDelivery(
            deliveryType: OrderDeliveryType::PICKUP_POINT,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            country: null,
            city: null,
            postalCode: null,
            addressLine1: null,
            addressLine2: null,
            carrierCode: null,
            pickupPointReference: 'office-1',
            settlement: 'Sofia district',
        );
    }

    public function test_pickup_point_with_a_street_address_field_still_refuses_via_revise_delivery(): void
    {
        $order = $this->pickupPointOrder();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('country');

        $order->reviseDelivery(
            deliveryType: OrderDeliveryType::PICKUP_POINT,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            country: 'BG',
            city: null,
            postalCode: null,
            addressLine1: null,
            addressLine2: null,
            carrierCode: 'econt',
            pickupPointReference: 'office-1',
            settlement: 'Sofia district',
        );
    }

    /**
     * @param  list<string>  $except
     * @return array<string, mixed>
     */
    private function everythingExcept(Order $order, array $except): array
    {
        $all = [
            'id' => $order->id(),
            'clientId' => $order->clientId(),
            'accountId' => $order->accountId(),
            'transactionId' => $order->transactionId(),
            'email' => $order->email(),
            'currency' => $order->currency()->code(),
            'subtotal' => $order->subtotal()->minorValue(),
            'discount' => $order->discount()->minorValue(),
            'total' => $order->total()->minorValue(),
            'appliedPromotionCode' => $order->appliedPromotionCode(),
            'status' => $order->status()->value,
            'placedAt' => $order->placedAt()->format('Y-m-d H:i:s'),
            'addressId' => $order->addressId(),
            'deliveryType' => $order->deliveryType()->value,
            'recipientName' => $order->recipientName(),
            'phone' => $order->phone(),
            'country' => $order->country(),
            'city' => $order->city(),
            'postalCode' => $order->postalCode(),
            'addressLine1' => $order->addressLine1(),
            'addressLine2' => $order->addressLine2(),
            'carrierCode' => $order->carrierCode(),
            'pickupPointReference' => $order->pickupPointReference(),
            'settlement' => $order->settlement(),
        ];

        foreach ($except as $field) {
            unset($all[$field]);
        }

        return $all;
    }
}
