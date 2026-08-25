<?php

namespace EasyCo\OperationalSales\Exceptions;

use RuntimeException;

/**
 * Thrown when a SaleLine's Money currency does not match the currency
 * already established by the other lines on an InstallmentPlan. All of
 * a plan's reserved and payment lines must share exactly one currency —
 * otherwise outstandingBalance()'s Money subtraction (which itself
 * refuses to mix currencies) could never be computed.
 */
final class CurrencyMismatchException extends RuntimeException
{
    public static function becauseCurrencyDoesNotMatch(string $planId, string $expectedCurrencyCode, string $actualCurrencyCode): self
    {
        return new self(
            "InstallmentPlan {$planId} is denominated in {$expectedCurrencyCode}, but the given SaleLine is denominated in {$actualCurrencyCode}."
        );
    }
}
