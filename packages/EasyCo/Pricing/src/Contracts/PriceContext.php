<?php

namespace EasyCo\Pricing\Contracts;

use DateTimeImmutable;

/**
 * Everything PriceResolver::resolve() needs to pick the right price for one
 * priceable item — see pricing-domain-design.md §4.1.
 *
 * WHY $matchingScopeReferenceIds EXISTS: PriceList scope matching
 * (pricing-persistence-domain-design.md §4.1/§4.6) needs to know, for a
 * BRAND/CATEGORY/TAG/ATTRIBUTE_VALUE scope, whether this priceableId's
 * product currently belongs to a given brand/category/tag/attribute
 * value. That is Catalog data, and this package must never depend on
 * Catalog directly (§1, CLAUDE.md rule 9) — so the caller (which does
 * have Catalog access) computes the match set up front and hands it in
 * here as plain strings, keeping PriceResolver's own implementation
 * Catalog-agnostic. CUSTOMER_GROUP/CHANNEL/PRODUCT scope matching does
 * not need this array — those already have their own dedicated fields.
 */
final class PriceContext
{
    /** @param array<string, string[]> $matchingScopeReferenceIds */
    public function __construct(
        public readonly string $priceableId,
        public readonly int $quantity,
        public readonly string $currency,
        public readonly ?string $customerGroupId = null,
        public readonly ?string $channelId = null,
        public readonly ?DateTimeImmutable $at = null,
        public readonly ?string $productId = null,
        /**
         * @var array<string, string[]> Keyed by PriceListScopeType value
         *   ('brand', 'category', 'tag', 'attribute_value') — every
         *   Catalog value this priceableId currently matches for that
         *   dimension. Computed by the CALLER (which has Catalog
         *   access); this package never queries Catalog directly, per
         *   §1. CUSTOMER_GROUP/CHANNEL/PRODUCT scope matching uses the
         *   dedicated customerGroupId/channelId/productId fields
         *   instead — not this array.
         */
        public readonly array $matchingScopeReferenceIds = [],
    ) {
    }
}
