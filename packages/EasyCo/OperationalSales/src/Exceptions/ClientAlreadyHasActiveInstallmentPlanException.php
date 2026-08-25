<?php

namespace EasyCo\OperationalSales\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when saving an InstallmentPlan would give a client a second
 * ACTIVE plan — translated from a caught unique-constraint violation on
 * operational_sales_installment_plans.active_client_id (see
 * EloquentInstallmentPlanRepository::save()), the real, race-condition
 * -safe guarantee. "At most one ACTIVE InstallmentPlan per client" is
 * NOT enforced by the domain aggregate itself (InstallmentPlan::open()
 * has no knowledge of any other plan for the same client) — the DB
 * constraint is the only thing that actually holds under concurrent
 * writes, mirroring catalog-domain-design.md's
 * DuplicateVariationCombinationException::fromDatabaseConstraintViolation().
 */
final class ClientAlreadyHasActiveInstallmentPlanException extends RuntimeException
{
    public static function fromDatabaseConstraintViolation(string $clientId, Throwable $previous): self
    {
        return new self(
            "Client {$clientId} already has an ACTIVE InstallmentPlan (database constraint).",
            previous: $previous
        );
    }
}
