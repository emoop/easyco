<?php

namespace EasyCo\Inventory\Exceptions;

use RuntimeException;

/**
 * Thrown by EloquentStockLevelRepository::decrease() when the current
 * quantity is less than the requested amount — including when no
 * stock_levels row exists at all (equivalent to 0 available). See
 * inventory-domain-design.md §6.
 */
final class InsufficientStockException extends RuntimeException
{
    public static function forVariation(string $variationId, int $requested): self
    {
        return new self(
            "Cannot decrease stock for variation \"{$variationId}\" by {$requested}: insufficient quantity available."
        );
    }
}
