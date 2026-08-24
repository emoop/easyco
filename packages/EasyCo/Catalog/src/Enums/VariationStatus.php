<?php

namespace EasyCo\Catalog\Enums;

/**
 * Minimal lifecycle state for a Variation, distinct from Product::$status
 * and from the is_visible / is_purchasable flags:
 *
 *   DRAFT    => combination exists (e.g. just generated from axis values)
 *               but the merchant has not finished configuring it. A DRAFT
 *               variation is never purchasable or visible regardless of
 *               its flags.
 *   ACTIVE   => merchant-confirmed, normal operating state. Flags decide
 *               actual visibility/purchasability from here.
 *   ARCHIVED => retired but never deleted, so historical references
 *               (Orders, POS transactions, inventory records, ...) stay
 *               valid forever. Always non-visible, non-purchasable.
 */
enum VariationStatus: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case ARCHIVED = 'archived';
}
