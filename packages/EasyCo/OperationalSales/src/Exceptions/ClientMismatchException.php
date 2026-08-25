<?php

namespace EasyCo\OperationalSales\Exceptions;

use RuntimeException;

/**
 * Thrown when a SaleLine attached to an InstallmentPlan (via
 * attachReservedLine() or recordPayment()) belongs to a different
 * client than the plan itself. An InstallmentPlan tracks one client's
 * balance and must never silently mix another client's lines into it.
 */
final class ClientMismatchException extends RuntimeException
{
    public static function becauseClientDoesNotMatch(string $planId, string $planClientId, string $lineClientId): self
    {
        return new self(
            "InstallmentPlan {$planId} belongs to client {$planClientId}, but the given SaleLine belongs to client {$lineClientId}."
        );
    }
}
