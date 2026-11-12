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
    /** P6b: a product's season (an entity of its own, `catalog_products.season_id`). */
    case SEASON = 'season';
    /** P6e-3b: a product's collections (many-to-many, the pivot `catalog_product_collections`); it matches only while the collections are switched ON. */
    case COLLECTION = 'collection';
}
