<?php

namespace App\Services\Exceptions;

use RuntimeException;

/** Thrown by CheckoutOrchestrator::place() when the cart has no lines. */
final class EmptyCartException extends RuntimeException
{
}
