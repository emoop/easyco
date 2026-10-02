<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * The refund path was handed a payment method whose adapter is not offline.
 * The refund runs inside the order-locked database transaction, and an online
 * provider call must never run there (shipping-domain-design.md §7.2.3); the
 * REQUESTED -> call-after-commit path belongs to R5 and does not exist yet.
 * Refused loudly and by name rather than called inside the transaction.
 */
final class NonOfflineRefundAdapterException extends RuntimeException
{
    public static function forMethod(string $method): self
    {
        return new self(
            "Refunds: payment method \"{$method}\" is not offline, and an online refund must not be executed inside the database transaction. ".
            'The online refund path (request after commit) is not built yet; nothing was refunded.'
        );
    }
}
