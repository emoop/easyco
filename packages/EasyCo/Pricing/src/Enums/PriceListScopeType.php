<?php

namespace EasyCo\Pricing\Enums;

/**
 * The dimension a single PriceListScope condition is expressed against
 * — see pricing-persistence-domain-design.md §3/§4.1.
 * `scope_reference_id` (on PriceListScope itself) is interpreted
 * differently depending on which of these a given row uses; this
 * package never validates that the referenced id actually exists in
 * whatever domain owns it (Catalog, OperationalSales, ...) — that's a
 * cross-domain concern outside this entity, per §1.
 */
enum PriceListScopeType: string
{
    case BRAND = 'brand';
    case CATEGORY = 'category';
    case TAG = 'tag';
    case ATTRIBUTE_VALUE = 'attribute_value';
    case CUSTOMER_GROUP = 'customer_group';
    case CHANNEL = 'channel';
    case PRODUCT = 'product';
}
