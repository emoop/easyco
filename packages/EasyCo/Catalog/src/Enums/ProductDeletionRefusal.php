<?php

namespace EasyCo\Catalog\Enums;

/**
 * WHY an ARCHIVED product cannot be hard-deleted — catalog-domain-design.md
 * §3.19.3 (G-D3), §3.19.8 B/D. Two reasons, and only two:
 *
 *  - it is NOT ARCHIVED: G-D3's own two-step rule — archiving is the
 *    reversible "off sale" operation, deleting is the irreversible one, and
 *    the delete is offered only once the product already sits archived;
 *  - at least one of its variations BLOCKS the delete, because it has
 *    history or non-zero stock. The variation-level reasons themselves are
 *    `VariationDeletionRefusal`'s (reused, never re-worded) and travel with
 *    the exception as data — see ProductNotDeletableException.
 *
 * A REASON CODE, DELIBERATELY NOT A MESSAGE — the same split
 * `VariationDeletionRefusal` already documents: the merchant-facing
 * sentence is rendered in the current locale by the application layer
 * (App\Services\ProductDeletionRefusalMessage), while the exception keeps a
 * plain English sentence for logs and support. The VALUES are the
 * `products.deletion.product_refusal.{value}` key fragments, so the mapping
 * cannot drift.
 */
enum ProductDeletionRefusal: string
{
    /** The product's status is not ARCHIVED; the delete is only offered after archiving. */
    case NOT_ARCHIVED = 'not_archived';

    /** At least one variation has history or non-zero stock — see its own VariationDeletionRefusal. */
    case VARIATIONS_BLOCK_DELETION = 'blocked_by_variations';
}
