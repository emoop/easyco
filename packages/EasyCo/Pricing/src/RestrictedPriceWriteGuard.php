<?php

namespace EasyCo\Pricing;

use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Exceptions\RestrictedPriceExceedsRegularException;

/**
 * §4.8's write-time check: before a PriceListItem is saved into a
 * "restricted" FIXED_ITEMS list — any FIXED_ITEMS list that is not
 * "Regular Prices" itself, "Manual Sale" included, no exception —
 * reject the write if the new price would exceed the current regular
 * price for that same target and quantity tier. See
 * pricing-persistence-domain-design.md §4.8.
 *
 * WHY THIS IS NOT INSIDE EloquentPriceListItemRepository::save():
 * The repository stays persistence-only — mapping an already-valid
 * entity onto a row, nothing more. It has never enforced cross-entity
 * business rules; PriceListItem itself draws exactly this line in its
 * own "EXPLICITLY NOT THIS CLASS'S JOB" docblock note, deferring
 * cross-list price sanity to §4.8's write-time check plus the
 * health-check report — this class IS that write-time check. Baking it
 * into the repository would also tie the rule to one specific
 * persistence implementation and make it impossible to skip
 * deliberately (e.g. a data-migration/import path that must write
 * already-known historical data as-is). The calling code — a future
 * Admin UI/API endpoint — must call assertPriceIsSane() explicitly
 * before save(); nothing here calls save() itself.
 *
 * ASSUMES A SINGLE PROJECT-WIDE CURRENCY (documented precondition, not
 * enforced here): the two net() amounts are compared directly via
 * Money::minorValue() with no currency-equality check. Nothing else in
 * this domain handles multi-currency yet either (see DefaultCurrency's
 * own docblock) — if that changes, this comparison will need an
 * explicit same-currency assertion or conversion step first.
 */
final class RestrictedPriceWriteGuard
{
    private const REGULAR_PRICES_LIST_NAME = 'Regular Prices';

    public function __construct(
        private readonly PriceListRepository $priceListRepository,
        private readonly FixedItemsPriceLookup $fixedItemsPriceLookup,
    ) {
    }

    public function assertPriceIsSane(PriceListItem $item, PriceList $list, ?string $productId): void
    {
        if ($list->mode() !== PriceListMode::FIXED_ITEMS) {
            return;
        }

        $regularList = $this->priceListRepository->findSystemListByName(self::REGULAR_PRICES_LIST_NAME);

        if ($regularList === null || $regularList->id() === $list->id()) {
            return;
        }

        $regular = $this->fixedItemsPriceLookup->forTarget(
            $regularList,
            $item->targetId(),
            $productId,
            $item->minQuantity(),
        );

        if ($regular === null) {
            return;
        }

        // Normalized to net() before comparison — different tax bases
        // are not directly comparable even at the same currency.
        $newNet = $item->price()->net();
        $regularNet = $regular->net();

        if ($newNet->minorValue() > $regularNet->minorValue()) {
            throw RestrictedPriceExceedsRegularException::forTarget(
                $item->targetId(),
                $newNet->decimalValue().' '.$newNet->currency()->code(),
                $regularNet->decimalValue().' '.$regularNet->currency()->code(),
            );
        }
    }
}
