<?php

namespace EasyCo\Pricing\Exceptions;

use RuntimeException;

/**
 * Thrown when application code attempts to rename or deactivate one of
 * the two reserved system PriceLists ("Regular Prices", "Manual Sale" —
 * see pricing-persistence-domain-design.md §4.5). Those two lists are
 * seeded once per store and carry the simple two-field ("Regular
 * Price"/"Sale Price") UX every other PriceList's PERCENTAGE_OFF_REGULAR
 * resolution depends on (§4.6 step 4) — a merchant renaming one by
 * accident, or a bug that resolves the wrong list, would silently break
 * that resolution for the whole catalog. `PriceList::activate()` is
 * deliberately NOT guarded the same way — see that method's own
 * docblock for why turning a system list back on is always safe.
 */
final class CannotModifySystemPriceListException extends RuntimeException
{
    public static function cannotRename(string $listId): self
    {
        return new self(
            "PriceList {$listId} is a reserved system list and cannot be renamed."
        );
    }

    public static function cannotDeactivate(string $listId): self
    {
        return new self(
            "PriceList {$listId} is a reserved system list and cannot be deactivated."
        );
    }
}
