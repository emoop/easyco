<?php

namespace EasyCo\Pricing\Enums;

/**
 * How a PriceList determines the final price — see
 * pricing-persistence-domain-design.md §3/§4.6. FIXED_ITEMS prices come
 * from explicit PriceListItem rows (not yet implemented — a separate
 * prompt); PERCENTAGE_OFF_REGULAR computes a discount off the resolved
 * "Regular Prices" system list price instead of storing any price of
 * its own.
 */
enum PriceListMode: string
{
    case FIXED_ITEMS = 'fixed_items';
    case PERCENTAGE_OFF_REGULAR = 'percentage_off_regular';
}
