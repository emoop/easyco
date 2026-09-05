<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * Thrown by PaymentMethodAdapterResolver::resolve() when no adapter is
 * bound for the given method — never falls back to a default adapter:
 * silently charging someone via a method they didn't choose is far worse
 * than a loud failure.
 */
final class UnknownPaymentMethodException extends RuntimeException
{
    public function __construct(string $method)
    {
        parent::__construct("No PaymentMethodAdapter is bound for method \"{$method}\".");
    }
}
