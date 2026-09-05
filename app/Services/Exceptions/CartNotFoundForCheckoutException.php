<?php

namespace App\Services\Exceptions;

use RuntimeException;

/** Thrown by CheckoutOrchestrator::place() when the given cartId doesn't exist. */
final class CartNotFoundForCheckoutException extends RuntimeException
{
    public function __construct(string $cartId)
    {
        parent::__construct("No cart found with id \"{$cartId}\".");
    }
}
