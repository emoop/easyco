<?php

namespace EasyCo\Catalog\Exceptions;

use RuntimeException;

/**
 * Thrown by Product::restoreArchivedVariation() when the ARCHIVED
 * variation's own attributeAssignments() no longer validate against
 * this Product's CURRENT declared variation axes — the axes drifted
 * (an axis or a value the archived combination depended on was removed,
 * or a new axis was added that the archived combination has no value
 * for) since the variation was archived. See
 * Product::assertAxisChangeIsSafe()/UnsafeAxisRedeclarationException:
 * ARCHIVED variations never block an axis change while it happens, so
 * this is the fail-loud check that happens instead, at the point
 * someone actually tries to bring the variation back — not silently
 * allowing a restore into an invalid state.
 */
final class VariationNotRestorableException extends RuntimeException
{
    public static function becauseItsCombinationIsNoLongerValid(
        string $variationId,
        string $variationSku,
        string $reason
    ): self {
        return new self(
            "Variation \"{$variationId}\" (sku \"{$variationSku}\") cannot be restored from the archive: ".
            "its combination no longer matches this Product's current declared variation axes ({$reason}). ".
            're-enable the removed axis or value first (via declareVariationAxes()) if you want this '.
            'variation to be restorable again.'
        );
    }
}
