<?php

namespace EasyCo\Promotions\Enums;

/**
 * The dimension a single PromotionScope condition is expressed against
 * — see promotions-domain-design.md §3. `scope_reference_id` (on
 * PromotionScope itself) is interpreted differently depending on which
 * of these a given row uses; this package never validates that the
 * referenced id actually exists in whatever domain owns it (Catalog,
 * Account) — that's a cross-domain concern outside this entity, same
 * posture as EasyCo\Pricing\Enums\PriceListScopeType.
 *
 * ACCOUNT is the one case with no PriceListScopeType equivalent — see
 * promotions-domain-design.md §3 ("the WooCommerce 'Allowed emails'
 * equivalent, expressed as a real Account id").
 */
enum PromotionScopeType: string
{
    case BRAND = 'brand';
    case CATEGORY = 'category';
    case TAG = 'tag';
    case ATTRIBUTE_VALUE = 'attribute_value';
    case PRODUCT = 'product';
    case ACCOUNT = 'account';
}
