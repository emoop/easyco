<?php

namespace EasyCo\Payment\Tests;

use EasyCo\Payment\Enums\PaymentRefundStatus;
use EasyCo\Payment\PaymentRefundAttemptResult;
use PHPUnit\Framework\TestCase;

final class PaymentRefundAttemptResultTest extends TestCase
{
    public function test_requested_produces_a_requested_status_with_no_failure_reason(): void
    {
        $result = PaymentRefundAttemptResult::requested();

        $this->assertSame(PaymentRefundStatus::REQUESTED, $result->status());
        $this->assertNull($result->failureReason());
    }

    public function test_owed_produces_an_owed_status_with_no_failure_reason(): void
    {
        $result = PaymentRefundAttemptResult::owed();

        $this->assertSame(PaymentRefundStatus::OWED, $result->status());
        $this->assertNull($result->failureReason());
    }

    public function test_completed_produces_a_completed_status_with_no_failure_reason(): void
    {
        $result = PaymentRefundAttemptResult::completed();

        $this->assertSame(PaymentRefundStatus::COMPLETED, $result->status());
        $this->assertNull($result->failureReason());
    }

    public function test_failed_with_a_reason_produces_a_failed_status_with_that_reason(): void
    {
        $result = PaymentRefundAttemptResult::failed('provider_timeout');

        $this->assertSame(PaymentRefundStatus::FAILED, $result->status());
        $this->assertSame('provider_timeout', $result->failureReason());
    }

    public function test_failed_with_no_reason_leaves_failure_reason_null(): void
    {
        $result = PaymentRefundAttemptResult::failed();

        $this->assertSame(PaymentRefundStatus::FAILED, $result->status());
        $this->assertNull($result->failureReason());
    }
}
