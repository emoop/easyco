<?php

namespace Tests\Feature;

use EasyCo\Payment\Contracts\PaymentRefundRepository;
use EasyCo\Payment\Enums\PaymentRefundStatus;
use EasyCo\Payment\PaymentRefund;
use EasyCo\Pricing\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EloquentPaymentRefundRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function repository(): PaymentRefundRepository
    {
        return app(PaymentRefundRepository::class);
    }

    private function amount(int $minor = 500): Money
    {
        return Money::fromMinorUnits($minor, 'EUR');
    }

    public function test_save_then_find_by_id_round_trips_a_payment_refund(): void
    {
        $refund = PaymentRefund::create(
            'payment-1',
            $this->amount(),
            PaymentRefundStatus::COMPLETED,
            reason: 'defective',
            refundedBy: 'staff-7',
        );

        $this->repository()->save($refund);

        $this->assertNotNull($refund->id());

        $reloaded = $this->repository()->findById($refund->id());
        $this->assertNotNull($reloaded);
        $this->assertSame('payment-1', $reloaded->paymentId());
        $this->assertSame(500, $reloaded->amount()->minorValue());
        $this->assertSame('EUR', $reloaded->amount()->currency()->code());
        $this->assertSame('defective', $reloaded->reason());
        $this->assertSame('staff-7', $reloaded->refundedBy());
        $this->assertSame(PaymentRefundStatus::COMPLETED, $reloaded->status());
    }

    public function test_find_by_id_for_a_nonexistent_id_returns_null(): void
    {
        $this->assertNull($this->repository()->findById('999999'));
    }

    public function test_find_by_payment_id_returns_every_matching_refund(): void
    {
        $first = PaymentRefund::create('payment-multi', $this->amount(200), PaymentRefundStatus::COMPLETED, reason: 'partial return');
        $second = PaymentRefund::create('payment-multi', $this->amount(100), PaymentRefundStatus::COMPLETED, reason: 'second partial return');
        $other = PaymentRefund::create('payment-other', $this->amount(), PaymentRefundStatus::PENDING);

        $this->repository()->save($first);
        $this->repository()->save($second);
        $this->repository()->save($other);

        $results = $this->repository()->findByPaymentId('payment-multi');

        $this->assertCount(2, $results);
        $ids = array_map(fn (PaymentRefund $refund) => $refund->id(), $results);
        $this->assertContains($first->id(), $ids);
        $this->assertContains($second->id(), $ids);
        $this->assertNotContains($other->id(), $ids);
    }
}
