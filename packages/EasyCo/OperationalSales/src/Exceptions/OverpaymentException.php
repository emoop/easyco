<?php

namespace EasyCo\OperationalSales\Exceptions;

use EasyCo\Pricing\Money;
use RuntimeException;

/**
 * Thrown when recordPayment() is given a payment amount larger than the
 * plan's current outstandingBalance(). Overpayment handling (e.g.
 * refunding the difference) is a real business decision that
 * operational-sales-domain-design.md does not specify — this is
 * explicitly rejected rather than silently accepted with a wrong
 * resulting balance.
 */
final class OverpaymentException extends RuntimeException
{
    public static function becauseAmountExceedsOutstandingBalance(string $planId, Money $paymentAmount, Money $outstandingBalance): self
    {
        return new self(
            "InstallmentPlan {$planId}: payment of {$paymentAmount->decimalValue()} {$paymentAmount->currency()->code()} ".
            "exceeds the outstanding balance of {$outstandingBalance->decimalValue()} {$outstandingBalance->currency()->code()}."
        );
    }
}
