<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * Thrown by AddressResolver::resolveExisting() for BOTH a genuinely
 * missing address AND one belonging to a different account — same
 * "don't reveal that a resource exists for someone else" reasoning
 * AddressController::update() already uses (404-not-403). A future
 * Checkout HTTP controller catches this and returns a 404, same shape.
 */
final class AddressNotFoundForCheckoutException extends RuntimeException
{
    public function __construct(string $addressId)
    {
        parent::__construct("No address \"{$addressId}\" found.");
    }
}
