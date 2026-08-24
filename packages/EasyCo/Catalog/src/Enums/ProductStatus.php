<?php

namespace EasyCo\Catalog\Enums;

/**
 * Lifecycle status of the Product record itself. Deliberately separate from
 * CatalogVisibility (storefront display) and Variation::isPurchasable()
 * (sellability) — see the domain design doc §"Visibility vs sellability".
 */
enum ProductStatus: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case ARCHIVED = 'archived';
}
