<?php

namespace EasyCo\Pricing;

use DateTimeImmutable;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Enums\PriceListMode;

/**
 * On-demand, catalog-wide cross-list price sanity report — the
 * mitigation §4.8 adopts instead of full bidirectional write-time
 * blocking (see pricing-persistence-domain-design.md §4.8): surfaces
 * every restricted-list item whose price now exceeds the current
 * "Regular Prices" price for its target, however that inconsistency
 * arose (e.g. an unrelated later change to the regular price that
 * RestrictedPriceWriteGuard could not have caught at write time,
 * because it didn't exist yet).
 *
 * WHY THIS IS A QUERY LAYER, NOT A DOMAIN AGGREGATE: direct precedent,
 * operational-sales-domain-design.md §3.8 ("Reporting is a query layer,
 * not a domain") — mirrored here exactly. This class owns no
 * persistence state of its own; it only reads already-persisted
 * PriceList/PriceListItem rows through the existing repository
 * contracts and existing lookup logic (FixedItemsPriceLookup, itself
 * shared with RestrictedPriceWriteGuard) and reports what it finds.
 * Nothing here writes anything, and nothing here is itself an entity
 * with an id or a lifecycle — PriceListHealthCheckIssue is a plain
 * report row, not a persisted record.
 */
final class PriceListHealthCheck
{
    private const REGULAR_PRICES_LIST_NAME = 'Regular Prices';

    public function __construct(
        private readonly PriceListRepository $priceListRepository,
        private readonly PriceListItemRepository $priceListItemRepository,
        private readonly FixedItemsPriceLookup $fixedItemsPriceLookup,
    ) {
    }

    /**
     * @param array<string, string> $variationToProductMap Variation id =>
     *   owning product id, supplied by the CALLER (Catalog access lives
     *   outside this package, per §1). A VARIATION-targeted item whose id
     *   is missing from this map still gets checked at the VARIATION level
     *   directly (that part needs no map), but skips the PRODUCT-level
     *   fallback rather than guessing.
     * @return PriceListHealthCheckIssue[]
     */
    public function run(DateTimeImmutable $at, array $variationToProductMap = []): array
    {
        $regularList = $this->priceListRepository->findSystemListByName(self::REGULAR_PRICES_LIST_NAME);

        if ($regularList === null) {
            // Diagnostic report, not a hard dependency like resolve()/the
            // write-time guard — "nothing to check yet" is a valid,
            // non-exceptional result here.
            return [];
        }

        $issues = [];

        foreach ($this->priceListRepository->findAllActiveAndValidAt($at) as $list) {
            if ($list->mode() !== PriceListMode::FIXED_ITEMS) {
                continue;
            }

            if ($list->id() === $regularList->id()) {
                // "Regular Prices" is never restricted relative to itself.
                continue;
            }

            foreach ($this->priceListItemRepository->findByPriceListId($list->id()) as $item) {
                if ($item->targetType() === PriceListItemTargetType::VARIATION) {
                    $priceableId = $item->targetId();
                    $productId = $variationToProductMap[$item->targetId()] ?? null;
                } else {
                    // '' sentinel: PriceListItem::__construct rejects an
                    // empty targetId for any real item, so '' can never
                    // accidentally match a genuine VARIATION target below
                    // — this guarantees the VARIATION-level branch of
                    // forTarget() always misses here, falling through to
                    // its PRODUCT-level lookup against $productId instead.
                    $priceableId = '';
                    $productId = $item->targetId();
                }

                $regular = $this->fixedItemsPriceLookup->forTarget(
                    $regularList,
                    $priceableId,
                    $productId,
                    $item->minQuantity(),
                );

                if ($regular === null) {
                    continue;
                }

                if ($item->price()->net()->minorValue() > $regular->net()->minorValue()) {
                    $issues[] = new PriceListHealthCheckIssue(
                        priceListId: $list->id(),
                        priceListName: $list->name(),
                        targetType: $item->targetType(),
                        targetId: $item->targetId(),
                        itemPrice: $item->price(),
                        regularPrice: $regular,
                    );
                }
            }
        }

        return $issues;
    }
}
