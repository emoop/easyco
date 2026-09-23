<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * Thrown by ProductTimelinePromoter::promote() when the target Product
 * is ARCHIVED — an archived product is not shown in any merchant-facing
 * timeline in the first place, so moving its position is meaningless
 * and never silently allowed.
 */
final class CannotPromoteArchivedProductException extends RuntimeException
{
    public function __construct(string $productId)
    {
        parent::__construct("Cannot promote Product \"{$productId}\": it is archived.");
    }
}
