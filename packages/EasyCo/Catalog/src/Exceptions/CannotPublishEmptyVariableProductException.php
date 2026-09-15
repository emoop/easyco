<?php

namespace EasyCo\Catalog\Exceptions;

use EasyCo\Catalog\Product;
use RuntimeException;

/**
 * Thrown when Product::publish() would put a VARIABLE product with no
 * sellable Variations live in front of customers.
 *
 * A VARIABLE product's Variations are entirely merchant-created (via
 * addStandardVariation() or a VariationCombinationGenerator run) —
 * unlike a SIMPLE product, whose single Universal variation always
 * exists from the moment createSimple() runs and is never deleted, only
 * archived alongside the whole product. That structural guarantee means
 * this exception can never be thrown for a SIMPLE product: the guard in
 * publish() only even runs the check for ProductType::VARIABLE.
 *
 * "No sellable Variations" means either zero variations exist at all,
 * or every one that does exist is ARCHIVED — an ARCHIVED variation is
 * never purchasable or visible (VariationStatus's own docblock), so a
 * VARIABLE product in that state is exactly as empty to a customer as
 * one with none.
 */
final class CannotPublishEmptyVariableProductException extends RuntimeException
{
    public static function forProduct(Product $product): self
    {
        return new self(
            "Product \"{$product->id()}\" cannot be published — it is a VARIABLE product with no active variations."
        );
    }
}
