<?php

namespace EasyCo\Promotions\Enums;

/**
 * Whether a PromotionScope condition includes or excludes the matched
 * items — see promotions-domain-design.md §3's resolution rule. Has no
 * equivalent on EasyCo\Pricing\PriceListScope: Pricing's scope matching
 * is AND-only inline logic with no EXCLUDE concept at all (§3.1) — this
 * is the one real, deliberate structural difference between the two,
 * not an oversight.
 */
enum PromotionScopeMode: string
{
    case INCLUDE = 'include';
    case EXCLUDE = 'exclude';
}
