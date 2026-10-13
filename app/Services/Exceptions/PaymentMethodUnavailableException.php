<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * The payment method exists but its descriptor says it is not available for THIS order (country, currency, total, delivery kind;
 * checkout-form-v2-design.md section 8.2). Thrown inside the placement transaction before anything is saved, so the whole attempt
 * rolls back and the customer can choose another method: 422 `payment_method_unavailable`. The page asks the same registry, but the
 * API never trusts the page.
 */
final class PaymentMethodUnavailableException extends RuntimeException
{
    public function __construct(string $method)
    {
        parent::__construct("The payment method \"{$method}\" is not available for this order.");
    }
}
