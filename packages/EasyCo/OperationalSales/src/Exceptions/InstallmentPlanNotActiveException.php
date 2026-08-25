<?php

namespace EasyCo\OperationalSales\Exceptions;

use EasyCo\OperationalSales\Enums\InstallmentPlanStatus;
use RuntimeException;

/**
 * Thrown when an operation that requires an ACTIVE InstallmentPlan
 * (attachReservedLine(), recordPayment(), cancel()) is attempted on a
 * plan that has already been COMPLETED or CANCELLED.
 *
 * cancel() deliberately raises this too when called on an already
 * COMPLETED/CANCELLED plan — see InstallmentPlan::cancel()'s docblock
 * for why calling it twice is treated as a caller bug, not a silent
 * no-op.
 */
final class InstallmentPlanNotActiveException extends RuntimeException
{
    public static function becauseOperationRequiresActiveStatus(
        string $planId,
        string $operation,
        InstallmentPlanStatus $actualStatus,
    ): self {
        return new self(
            "InstallmentPlan {$planId}: {$operation}() requires an ACTIVE plan, but this plan is {$actualStatus->value}."
        );
    }
}
