<?php

namespace EasyCo\Pricing\Enums;

/**
 * What a PriceListItem's targetId identifies — see
 * pricing-persistence-domain-design.md §3/§4.3. A PRODUCT-level item
 * covers every current and future Variation of that product
 * automatically; a VARIATION-level item is an explicit override for one
 * specific Variation, checked first before falling back to a
 * PRODUCT-level item (§4.3's fallback resolution — not implemented by
 * this entity, see PriceListItem's class docblock).
 */
enum PriceListItemTargetType: string
{
    case PRODUCT = 'product';
    case VARIATION = 'variation';
}
