<?php

namespace EasyCo\Cart\Exceptions;

use RuntimeException;

/**
 * Thrown when a requested cart quantity exceeds the current stock
 * available for a Variation — see cart-domain-design.md §7. This is a
 * SOFT, add-time-only check: no stock is held or decremented, so this
 * exception says nothing about whether the requested amount will still
 * be available by the time Checkout actually runs its own
 * authoritative check.
 */
final class InsufficientStockForCartException extends RuntimeException
{
    public function __construct(
        public readonly string $variationId,
        public readonly int $requested,
        public readonly int $available,
    ) {
        parent::__construct(
            "Cannot add {$requested} of variation \"{$variationId}\" to cart: only {$available} available."
        );
    }
}
