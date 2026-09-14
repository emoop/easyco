<?php

namespace EasyCo\Catalog\Exceptions;

use RuntimeException;

/**
 * Thrown when Product::removeDescriptiveAttribute() is called for an
 * AttributeDefinition that is currently declared as one of this
 * Product's variation axes.
 *
 * WHY THIS GUARD EXISTS: a variation axis in real use has real
 * Variation rows depending on it — each STANDARD variation's
 * attribute_signature is derived from the axis/value combination this
 * definition participates in. removeDescriptiveAttribute() only ever
 * touches catalog_product_attributes(is_variation_axis=false) rows
 * (mirrors setDescriptiveAttribute()'s own scope); silently accepting
 * a call for an axis-declared definition here would either be a no-op
 * that misleads the caller into thinking usage was removed, or would
 * require this method to reach into axis/Variation territory it has
 * no business touching. The only safe path to actually removing an
 * axis is resolving/removing the Variations that depend on it first —
 * separate, future work this exception deliberately does not attempt
 * to shortcut.
 *
 * Same "distinct invariant, not a reuse" posture as
 * UnsafeAxisRedeclarationException relative to
 * UnsafeProductTypeTransitionException — this guards
 * removeDescriptiveAttribute() specifically, not axis declaration or
 * type transitions.
 */
final class CannotRemoveVariationAxisAttributeException extends RuntimeException
{
    public static function forDefinition(string $definitionCode): self
    {
        return new self(
            "Attribute definition \"{$definitionCode}\" is currently declared as a variation axis of this ".
            'Product and cannot be removed as a descriptive attribute through this method. Real Variation '.
            'rows depend on this axis — resolve/remove those Variations first; removing the axis itself is '.
            'a separate operation this method does not attempt.'
        );
    }
}
