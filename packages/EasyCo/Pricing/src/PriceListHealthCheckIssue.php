<?php

namespace EasyCo\Pricing;

use EasyCo\Pricing\Enums\PriceListItemTargetType;

/**
 * One cross-list price inconsistency surfaced by PriceListHealthCheck —
 * a restricted list's item price now exceeds the current "Regular
 * Prices" price for the same target, per
 * pricing-persistence-domain-design.md §4.8.
 */
final class PriceListHealthCheckIssue
{
    public function __construct(
        public readonly string $priceListId,
        public readonly string $priceListName,
        public readonly PriceListItemTargetType $targetType,
        public readonly string $targetId,
        public readonly Price $itemPrice,
        public readonly Price $regularPrice,
    ) {
    }
}
