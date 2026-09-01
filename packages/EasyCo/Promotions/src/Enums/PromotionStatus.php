<?php

namespace EasyCo\Promotions\Enums;

/**
 * A Promotion's manual on/off lifecycle flag — distinct from
 * validFrom/validUntil (a time window), same pattern as
 * EasyCo\Pricing\Enums\PriceListStatus. INACTIVE lets a merchant disable
 * a code without deleting its configuration/history.
 */
enum PromotionStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
}
