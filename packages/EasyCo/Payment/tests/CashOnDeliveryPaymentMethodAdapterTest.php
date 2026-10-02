<?php

namespace EasyCo\Payment\Tests;

use EasyCo\Payment\Adapters\CashOnDeliveryPaymentMethodAdapter;
use EasyCo\Payment\Enums\PaymentRefundStatus;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Payment;
use EasyCo\Payment\PaymentContext;
use EasyCo\Pricing\Money;
use PHPUnit\Framework\TestCase;

final class CashOnDeliveryPaymentMethodAdapterTest extends TestCase
{
    private function adapter(): CashOnDeliveryPaymentMethodAdapter
    {
        return new CashOnDeliveryPaymentMethodAdapter();
    }

    public function test_charge_always_returns_pending_with_no_provider_reference(): void
    {
        $result = $this->adapter()->charge(
            Money::fromMinorUnits(1000, 'EUR'),
            new PaymentContext(orderId: 'order-1'),
        );

        $this->assertSame(PaymentStatus::PENDING, $result->status());
        $this->assertNull($result->providerReference());
    }

    public function test_charge_always_returns_pending_regardless_of_amount_or_context(): void
    {
        $result = $this->adapter()->charge(
            Money::fromMinorUnits(999999, 'BGN'),
            new PaymentContext(orderId: 'a-completely-different-order'),
        );

        $this->assertSame(PaymentStatus::PENDING, $result->status());
        $this->assertNull($result->providerReference());
    }

    public function test_refund_always_returns_owed_because_the_money_is_paid_back_later(): void
    {
        $original = Payment::create('order-1', 'cash_on_delivery', Money::fromMinorUnits(1000, 'EUR'), PaymentStatus::CAPTURED);

        $result = $this->adapter()->refund(
            $original,
            Money::fromMinorUnits(1000, 'EUR'),
            new PaymentContext(orderId: 'order-1'),
        );

        $this->assertSame(PaymentRefundStatus::OWED, $result->status());
        $this->assertNull($result->failureReason());
    }

    public function test_the_adapter_is_offline(): void
    {
        $this->assertTrue($this->adapter()->isOffline(), 'it calls no external system, which is what lets its refund run inside a database transaction');
    }
}
