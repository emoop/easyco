<?php

namespace EasyCo\Order\Tests;

use DateTimeImmutable;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Enums\OrderStatus;
use EasyCo\Order\Order;
use EasyCo\Pricing\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * shipping-domain-design.md §7 / §7.1 (shipping stage 2): an order carries an
 * order-level shipping amount, total = subtotal - discount + shipping, and a
 * supplied total is still refused.
 */
class OrderShippingTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function order(array $overrides = []): Order
    {
        return Order::create(...array_merge([
            'clientId' => 'client-1',
            'transactionId' => 'transaction-1',
            'email' => 'buyer@example.com',
            'currency' => 'EUR',
            'subtotal' => Money::fromMinorUnits(8000, 'EUR'),
            'discount' => Money::fromMinorUnits(1000, 'EUR'),
            'deliveryType' => OrderDeliveryType::STREET_ADDRESS,
            'recipientName' => 'Иван Иванов',
            'phone' => '+359888123456',
            'placedAt' => new DateTimeImmutable('2026-01-01 12:00:00'),
            'country' => 'BG',
            'city' => 'София',
            'addressLine1' => 'бул. Витоша 1',
        ], $overrides));
    }

    public function test_zero_shipping_gives_exactly_the_old_total(): void
    {
        $order = $this->order();

        $this->assertSame(7000, $order->total()->minorValue(), '80.00 - 10.00, exactly the pre-shipping number');
        $this->assertSame(0, $order->shipping()->minorValue());
        $this->assertSame('EUR', $order->shipping()->currency()->code());
        $this->assertNull($order->shippingMethodName());
        $this->assertNull($order->shippingMethodCode());
    }

    public function test_total_is_subtotal_minus_discount_plus_shipping(): void
    {
        $order = $this->order([
            'shipping' => Money::fromMinorUnits(500, 'EUR'),
            'shippingMethodName' => 'Доставка до адрес',
            'shippingMethodCode' => 'home-delivery',
        ]);

        $this->assertSame(7500, $order->total()->minorValue(), '80.00 - 10.00 + 5.00');
        $this->assertSame(500, $order->shipping()->minorValue());
        $this->assertSame('Доставка до адрес', $order->shippingMethodName());
        $this->assertSame('home-delivery', $order->shippingMethodCode());
    }

    public function test_create_still_refuses_a_supplied_total(): void
    {
        $this->expectException(\Error::class);

        $this->order(['total' => Money::fromMinorUnits(1, 'EUR')]);
    }

    public function test_negative_shipping_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->order(['shipping' => Money::fromMinorUnits(-1, 'EUR'), 'shippingMethodName' => 'Delivery']);
    }

    public function test_shipping_in_another_currency_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->order(['shipping' => Money::fromMinorUnits(500, 'BGN'), 'shippingMethodName' => 'Delivery']);
    }

    public function test_shipping_greater_than_zero_requires_a_method_name(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->order(['shipping' => Money::fromMinorUnits(500, 'EUR')]);
    }

    public function test_a_method_code_requires_a_method_name(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->order(['shippingMethodCode' => 'home-delivery']);
    }

    public function test_a_zero_shipping_order_may_still_name_its_method(): void
    {
        $order = $this->order(['shippingMethodName' => 'Безплатна доставка', 'shippingMethodCode' => 'free']);

        $this->assertSame(7000, $order->total()->minorValue());
        $this->assertSame('Безплатна доставка', $order->shippingMethodName());
    }

    public function test_method_name_is_trimmed_and_may_not_be_blank_or_longer_than_255(): void
    {
        $this->assertSame('Delivery', $this->order(['shippingMethodName' => '  Delivery  '])->shippingMethodName());

        foreach (['   ', str_repeat('я', 256)] as $bad) {
            try {
                $this->order(['shippingMethodName' => $bad]);
                $this->fail('a blank or over-long method name must be rejected');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(255, mb_strlen($this->order(['shippingMethodName' => str_repeat('я', 255)])->shippingMethodName()));
    }

    public function test_method_code_is_trimmed_and_may_not_be_blank_or_longer_than_64(): void
    {
        $this->assertSame('free', $this->order(['shippingMethodName' => 'Free', 'shippingMethodCode' => ' free '])->shippingMethodCode());

        foreach (['  ', str_repeat('a', 65)] as $bad) {
            try {
                $this->order(['shippingMethodName' => 'Free', 'shippingMethodCode' => $bad]);
                $this->fail('a blank or over-long method code must be rejected');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_discount_larger_than_the_subtotal_cannot_hide_behind_shipping(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // 10.00 - 12.00 + 5.00 = 3.00 is not negative, but the goods are.
        $this->order([
            'subtotal' => Money::fromMinorUnits(1000, 'EUR'),
            'discount' => Money::fromMinorUnits(1200, 'EUR'),
            'shipping' => Money::fromMinorUnits(500, 'EUR'),
            'shippingMethodName' => 'Delivery',
        ]);
    }

    public function test_revise_totals_carries_shipping_unchanged_and_computes_the_total_from_it(): void
    {
        $order = $this->order([
            'shipping' => Money::fromMinorUnits(500, 'EUR'),
            'shippingMethodName' => 'Доставка до адрес',
            'shippingMethodCode' => 'home-delivery',
        ]);

        $order->reviseTotals(Money::fromMinorUnits(2000, 'EUR'), Money::fromMinorUnits(300, 'EUR'), 'NEWCODE');

        $this->assertSame(2000 - 300 + 500, $order->total()->minorValue());
        $this->assertSame(500, $order->shipping()->minorValue(), 'shipping is carried through, never re-priced');
        $this->assertSame('Доставка до адрес', $order->shippingMethodName());
        $this->assertSame('home-delivery', $order->shippingMethodCode());
        $this->assertSame('NEWCODE', $order->appliedPromotionCode());
    }

    public function test_revise_totals_has_no_total_parameter(): void
    {
        $parameters = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(Order::class, 'reviseTotals'))->getParameters(),
        );

        $this->assertSame(['subtotal', 'discount', 'appliedPromotionCode'], $parameters);
    }

    public function test_reconstitute_from_storage_trusts_the_stored_total_and_adds_no_new_throw(): void
    {
        $order = Order::reconstituteFromStorage(
            id: '5',
            clientId: 'client-5',
            accountId: null,
            transactionId: 'transaction-5',
            email: 'buyer@example.com',
            currency: 'EUR',
            subtotal: Money::fromMinorUnits(8000, 'EUR'),
            discount: Money::fromMinorUnits(1000, 'EUR'),
            shipping: Money::fromMinorUnits(500, 'EUR'),
            shippingMethodName: 'Доставка до адрес',
            shippingMethodCode: null,
            total: Money::fromMinorUnits(7500, 'EUR'),
            appliedPromotionCode: null,
            status: OrderStatus::PLACED,
            placedAt: new DateTimeImmutable('2026-01-01 12:00:00'),
            addressId: null,
            deliveryType: OrderDeliveryType::STREET_ADDRESS,
            recipientName: 'Иван Иванов',
            phone: '+359888123456',
            country: 'BG',
            city: 'София',
            postalCode: null,
            addressLine1: 'бул. Витоша 1',
            addressLine2: null,
            carrierCode: null,
            pickupPointReference: null,
            settlement: null,
        );

        $this->assertSame(7500, $order->total()->minorValue());
        $this->assertSame(500, $order->shipping()->minorValue());
        $this->assertNull($order->shippingMethodCode());
    }
}
