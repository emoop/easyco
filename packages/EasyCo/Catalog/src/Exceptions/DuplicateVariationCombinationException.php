<?php

namespace EasyCo\Catalog\Exceptions;

use RuntimeException;

/**
 * Thrown when a Variation combination (product_id + attribute signature)
 * already exists.
 *
 * This can be raised in two situations:
 *  1. Application-layer pre-check inside Product/VariationCombinationGenerator
 *     (fast-fail, better error message for the caller).
 *  2. Translated from a caught DB unique-constraint violation on
 *     (product_id, attribute_signature) — the actual race-condition-safe
 *     guarantee. See catalog-domain-design.md §"Variation combination
 *     uniqueness" for why the DB constraint, not the app-layer check
 *     alone, is the source of truth.
 */
final class DuplicateVariationCombinationException extends RuntimeException
{
    public static function forSignature(string $productId, string $signature): self
    {
        return new self(
            "Product {$productId} already has a variation with attribute signature {$signature}."
        );
    }

    public static function fromDatabaseConstraintViolation(string $productId, \Throwable $previous): self
    {
        return new self(
            "Product {$productId}: a variation with this exact attribute combination already exists (database constraint).",
            previous: $previous
        );
    }
}
