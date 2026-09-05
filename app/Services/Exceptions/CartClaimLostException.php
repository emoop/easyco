<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * INTERNAL MARKER, NOT PART OF THE PUBLIC API — never escapes
 * CheckoutOrchestrator::place(). Thrown inside the checkout transaction
 * when CartRepository::claimForOrder() returns false (a concurrent
 * request claimed this cart first), purely to unwind everything this
 * attempt just wrote via the transaction rollback. place() catches this
 * itself and resolves it idempotently — see checkout-domain-design.md §6.
 */
final class CartClaimLostException extends RuntimeException
{
}
