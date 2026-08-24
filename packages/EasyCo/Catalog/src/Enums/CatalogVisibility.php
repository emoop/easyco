<?php

namespace EasyCo\Catalog\Enums;

/**
 * Whether a Product appears in customer-facing catalog surfaces
 * (storefront listing/search). This is intentionally independent of
 * whether any of its Variations are purchasable — a HIDDEN product can
 * still be sold through POS or an authorized direct-order flow.
 *
 * VISIBLE/HIDDEN here is the Catalog-owned signal only. Per-channel
 * availability (Web, Google, Meta, AI...) is a future Channel &
 * Distribution domain concern layered on top of this, not a replacement
 * for it.
 */
enum CatalogVisibility: string
{
    case VISIBLE = 'visible';
    case HIDDEN = 'hidden';
}
