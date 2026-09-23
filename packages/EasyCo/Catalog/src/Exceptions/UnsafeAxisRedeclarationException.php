<?php

namespace EasyCo\Catalog\Exceptions;

use RuntimeException;

/**
 * Thrown by Product::assertAxisChangeIsSafe() (called from
 * declareVariationAxes()) when a proposed axis re-declaration is unsafe
 * for at least one LIVE (non-ARCHIVED) STANDARD variation — see
 * catalog-domain-design.md §3.17 for the full rule table this class's
 * three factories correspond to:
 *
 *   - becauseLiveVariationsWouldLoseAnAxis(): a currently-declared axis
 *     is absent from the new set, and at least one live STANDARD
 *     variation still supplies a value for it — every such variation
 *     would become an illegal PARTIAL combination.
 *   - becauseNewAxisWouldInvalidateLiveVariations(): the new set
 *     introduces an axis this Product never declared before, and at
 *     least one live STANDARD variation exists — that variation has no
 *     value for the new axis and assertValidCombination() requires
 *     every declared axis to be supplied; v1 has no migration path that
 *     invents a value for an existing combination.
 *   - becauseLiveVariationsUseRemovedValues(): a definition present in
 *     both the current and new set had one or more allowed values
 *     removed, and at least one live STANDARD variation's own
 *     attributeAssignments() still uses one of those removed values.
 *
 * What is now ALLOWED that v1.4 refused outright: re-declaring the
 * identical axis/value set (a no-op — this is what keeps
 * Product::reconstituteFromStorage()-style reloads and admin re-saves
 * harmless), adding a brand-new allowed value to an already-declared
 * axis while live variations exist, removing an axis or a value that no
 * LIVE variation actually depends on, and any change at all once every
 * STANDARD variation on the Product has been archived first.
 *
 * ARCHIVED variations deliberately never trip any of these three rules
 * — an archived variation is a historical record that is never
 * re-validated against a changing axis declaration, and its
 * catalog_variation_attribute_values rows are never touched by an axis
 * change. A merchant who retires a value by removing it from the axis
 * simply cannot restore (Product::restoreArchivedVariation()) the
 * variations that used it afterward — restoreArchivedVariation() itself
 * re-validates the archived variation's combination against the
 * CURRENT axes and throws VariationNotRestorableException if it no
 * longer fits. Fail-loud at the point of restoration, not silently
 * blocked at the point of archiving.
 */
final class UnsafeAxisRedeclarationException extends RuntimeException
{
    /** @param string[] $liveVariationIds */
    public static function becauseLiveVariationsWouldLoseAnAxis(
        string $productId,
        string $attributeDefinitionId,
        array $liveVariationIds
    ): self {
        $ids = implode(', ', $liveVariationIds);

        return new self(
            "Product {$productId} cannot remove variation axis \"{$attributeDefinitionId}\": ".
            "live (non-archived) STANDARD variation(s) [{$ids}] still supply a value for it, and removing ".
            'the axis would make every one of them an incomplete combination. This is not caused by any '.
            'ARCHIVED variation — those are never checked. Archive the listed variation(s) first if you '.
            'intend to remove this axis.'
        );
    }

    /** @param string[] $liveVariationIds */
    public static function becauseNewAxisWouldInvalidateLiveVariations(
        string $productId,
        string $attributeDefinitionId,
        array $liveVariationIds
    ): self {
        $ids = implode(', ', $liveVariationIds);

        return new self(
            "Product {$productId} cannot add variation axis \"{$attributeDefinitionId}\": ".
            "live (non-archived) STANDARD variation(s) [{$ids}] exist and none of them has a value for this ".
            'new axis, which would make every one of them an incomplete combination. v1 has no migration '.
            'path that invents a value for an existing combination. This is not caused by any ARCHIVED '.
            'variation — those are never checked. Archive the listed variation(s) first if you intend to '.
            'add this axis.'
        );
    }

    /**
     * @param string[] $removedValueIds
     * @param string[] $dependentVariationIds
     */
    public static function becauseLiveVariationsUseRemovedValues(
        string $productId,
        string $attributeDefinitionId,
        array $removedValueIds,
        array $dependentVariationIds
    ): self {
        $values = implode(', ', $removedValueIds);
        $ids = implode(', ', $dependentVariationIds);

        return new self(
            "Product {$productId} cannot remove value(s) [{$values}] from variation axis ".
            "\"{$attributeDefinitionId}\": live (non-archived) STANDARD variation(s) [{$ids}] currently use ".
            'one of those values. This is not caused by any ARCHIVED variation — those are never checked. '.
            'Archive the listed variation(s) first if you intend to remove these value(s).'
        );
    }
}
