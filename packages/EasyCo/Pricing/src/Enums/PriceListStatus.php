<?php

namespace EasyCo\Pricing\Enums;

/**
 * A PriceList's manual on/off lifecycle flag — distinct from
 * validFrom/validUntil (a time window), see
 * pricing-persistence-domain-design.md §3. INACTIVE lets a merchant
 * disable a list without deleting its history/configuration.
 */
enum PriceListStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
}
