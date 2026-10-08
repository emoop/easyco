<?php

namespace App\Services\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown by CheckoutOrchestrator when pricing the cart meets a price held in a currency other
 * than the store's (Money refuses to add them). The controller answers 422 `currency_mismatch`.
 * The Money exception is kept as $previous for the log; it is never put into a reply.
 */
final class CheckoutCurrencyMismatchException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('A price in the cart is not in the store currency.', 0, $previous);
    }
}
