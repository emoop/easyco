<?php

namespace EasyCo\Catalog\Exceptions;

use RuntimeException;

/**
 * Thrown when a Product::attemptConvertToSimple() call would risk breaking
 * historical references to existing STANDARD Variations (Orders, POS
 * transactions, inventory records, ...). Catalog cannot see into those
 * other domains, so it takes the conservative position: once a Product has
 * ever had a non-Universal Variation created, VARIABLE -> SIMPLE is
 * refused by the normal API.
 *
 * See Product::forceConvertToSimple() for the explicit, separately-named
 * escape hatch — never triggered implicitly.
 */
final class UnsafeProductTypeTransitionException extends RuntimeException
{
    public static function becauseStandardVariationsExist(string $productId): self
    {
        return new self(
            "Product {$productId} cannot be converted back to SIMPLE: it has one or more STANDARD ".
            'variations that may already be referenced by Orders, POS or Inventory. '.
            'Use Product::forceConvertToSimple() only if you have verified no external references exist.'
        );
    }
}
