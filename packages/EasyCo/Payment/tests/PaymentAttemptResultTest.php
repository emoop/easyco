<?php

namespace EasyCo\Payment\Tests;

use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\PaymentAttemptResult;
use PHPUnit\Framework\TestCase;

final class PaymentAttemptResultTest extends TestCase
{
    public function test_pending_produces_a_pending_status_with_no_provider_reference_or_failure_reason(): void
    {
        $result = PaymentAttemptResult::pending();

        $this->assertSame(PaymentStatus::PENDING, $result->status());
        $this->assertNull($result->providerReference());
        $this->assertNull($result->failureReason());
    }

    public function test_captured_produces_a_captured_status_with_the_given_provider_reference(): void
    {
        $result = PaymentAttemptResult::captured('ch_123');

        $this->assertSame(PaymentStatus::CAPTURED, $result->status());
        $this->assertSame('ch_123', $result->providerReference());
        $this->assertNull($result->failureReason());
    }

    public function test_failed_with_a_reason_produces_a_failed_status_with_that_reason(): void
    {
        $result = PaymentAttemptResult::failed('card_declined');

        $this->assertSame(PaymentStatus::FAILED, $result->status());
        $this->assertSame('card_declined', $result->failureReason());
        $this->assertNull($result->providerReference());
    }

    public function test_failed_with_no_reason_leaves_failure_reason_null(): void
    {
        $result = PaymentAttemptResult::failed();

        $this->assertSame(PaymentStatus::FAILED, $result->status());
        $this->assertNull($result->failureReason());
    }
}
