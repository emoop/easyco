<?php

namespace EasyCo\Catalog\Exceptions;

use RuntimeException;

/**
 * Thrown when Product::declareVariationAxes() would change the axis set
 * out from under one or more existing STANDARD Variations.
 *
 * A distinct invariant from UnsafeProductTypeTransitionException, not a
 * reuse of it: that one guards SIMPLE<->VARIABLE type transitions; this
 * one guards the axis declaration itself. Same underlying reasoning,
 * though — once a Variation exists whose attribute combination depends on
 * the current axis declaration, changing that declaration risks silently
 * orphaning the combination (a value that was valid when the Variation
 * was created may no longer even be a declared axis). Archiving a
 * STANDARD variation does not erase this risk — the archived row's
 * combination still depended on the axes it was created under — so the
 * check is by TYPE (has one ever existed), not by current status,
 * mirroring exactly how Product::attemptConvertToSimple() decides.
 *
 * v1 has no migration path for changing axes out from under existing
 * variations (re-validating/migrating existing combinations to a new
 * axis set is explicitly out of scope). The only way to change axes in
 * v1 is for the Product to have zero STANDARD variations.
 */
final class UnsafeAxisRedeclarationException extends RuntimeException
{
    public static function becauseStandardVariationsExist(string $productId): self
    {
        return new self(
            "Product {$productId} cannot redeclare its variation axes: it has one or more STANDARD ".
            'variations whose attribute combinations depend on the current axis declaration. '.
            'Changing axes while such variations exist risks orphaning their combinations, and v1 has '.
            'no migration path for that — axes can only be (re)declared while the Product has zero '.
            'STANDARD variations.'
        );
    }
}
