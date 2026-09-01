<?php

namespace EasyCo\Promotions\Enums;

/**
 * How a Promotion computes its discount amount — see
 * promotions-domain-design.md §2. PERCENTAGE stores a basis-points rate
 * (Promotion::percentageBasisPoints(), same convention as
 * EasyCo\Pricing\PriceList); FIXED_AMOUNT stores an EasyCo\Pricing\Money
 * instead. Exactly one of the two is ever set — see
 * Promotion::assertDiscountValueMatchesType().
 */
enum PromotionDiscountType: string
{
    case PERCENTAGE = 'percentage';
    case FIXED_AMOUNT = 'fixed_amount';
}
