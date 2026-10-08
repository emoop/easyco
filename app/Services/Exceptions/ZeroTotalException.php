<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * Thrown by CheckoutOrchestrator before anything is written when the cart's total after
 * discount is not positive: a Payment cannot be for zero (Payment::assertPositiveAmount),
 * so there is nothing to place. Judged on the order total as it is today — shipping is not
 * part of the order yet (shipping-domain-design.md §9.1, O8). The controller answers 422
 * `zero_total`; the message carries no customer input.
 */
final class ZeroTotalException extends RuntimeException
{
}
