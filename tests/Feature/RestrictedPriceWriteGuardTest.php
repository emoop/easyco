<?php

namespace Tests\Feature;

use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Exceptions\RestrictedPriceExceedsRegularException;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListItem;
use EasyCo\Pricing\RestrictedPriceWriteGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestrictedPriceWriteGuardTest extends TestCase
{
    use RefreshDatabase;

    private function guard(): RestrictedPriceWriteGuard
    {
        return app(RestrictedPriceWriteGuard::class);
    }

    private function priceListRepository(): PriceListRepository
    {
        return app(PriceListRepository::class);
    }

    private function itemRepository(): PriceListItemRepository
    {
        return app(PriceListItemRepository::class);
    }

    private function seedRegularPricesList(): PriceList
    {
        $list = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);
        $this->priceListRepository()->save($list);

        return $list;
    }

    private function addItem(
        PriceList $list,
        PriceListItemTargetType $targetType,
        string $targetId,
        Price $price,
        int $minQuantity = 1,
    ): PriceListItem {
        $item = new PriceListItem(null, $list->id(), $targetType, $targetId, $price, $minQuantity);
        $this->itemRepository()->save($item);

        return $item;
    }

    private function price(string $amount, int $taxRateBasisPoints = 2000, bool $inclusive = false): Price
    {
        $money = Money::fromDecimal($amount, 'EUR');

        return $inclusive
            ? Price::inclusiveOfTax($money, $taxRateBasisPoints)
            : Price::exclusiveOfTax($money, $taxRateBasisPoints);
    }

    public function test_restricted_list_item_priced_below_regular_does_not_throw(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem($regularList, PriceListItemTargetType::VARIATION, 'variation-1', $this->price('29.99'));

        $wholesaleList = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);
        $this->priceListRepository()->save($wholesaleList);

        $candidate = new PriceListItem(null, $wholesaleList->id(), PriceListItemTargetType::VARIATION, 'variation-1', $this->price('19.99'));

        $this->expectNotToPerformAssertions();
        $this->guard()->assertPriceIsSane($candidate, $wholesaleList, null);
    }

    public function test_restricted_list_item_priced_above_regular_throws(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem($regularList, PriceListItemTargetType::VARIATION, 'variation-1', $this->price('19.99'));

        $wholesaleList = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);
        $this->priceListRepository()->save($wholesaleList);

        $candidate = new PriceListItem(null, $wholesaleList->id(), PriceListItemTargetType::VARIATION, 'variation-1', $this->price('29.99'));

        $this->expectException(RestrictedPriceExceedsRegularException::class);
        $this->guard()->assertPriceIsSane($candidate, $wholesaleList, null);
    }

    public function test_restricted_list_item_priced_exactly_equal_to_regular_does_not_throw(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem($regularList, PriceListItemTargetType::VARIATION, 'variation-1', $this->price('19.99'));

        $wholesaleList = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);
        $this->priceListRepository()->save($wholesaleList);

        $candidate = new PriceListItem(null, $wholesaleList->id(), PriceListItemTargetType::VARIATION, 'variation-1', $this->price('19.99'));

        $this->expectNotToPerformAssertions();
        $this->guard()->assertPriceIsSane($candidate, $wholesaleList, null);
    }

    public function test_percentage_off_regular_list_never_throws(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem($regularList, PriceListItemTargetType::VARIATION, 'variation-1', $this->price('19.99'));

        $discountList = PriceList::create('Guess -20%', PriceListMode::PERCENTAGE_OFF_REGULAR, priority: 10, percentageBasisPoints: 2000);
        $this->priceListRepository()->save($discountList);

        // A PriceListItem is only ever meaningful for FIXED_ITEMS lists in
        // practice, but the guard's mode check must fire before it even
        // looks at the item's price — an absurdly high price proves it
        // really did short-circuit rather than happening not to trip.
        $candidate = new PriceListItem(null, $discountList->id(), PriceListItemTargetType::VARIATION, 'variation-1', $this->price('999999.99'));

        $this->expectNotToPerformAssertions();
        $this->guard()->assertPriceIsSane($candidate, $discountList, null);
    }

    public function test_writing_directly_into_regular_prices_itself_never_throws(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem($regularList, PriceListItemTargetType::VARIATION, 'variation-1', $this->price('19.99'));

        // A second, much higher price for the same target, written into
        // "Regular Prices" itself — nothing to compare it against but
        // itself, so this must never throw regardless of the value.
        $candidate = new PriceListItem(null, $regularList->id(), PriceListItemTargetType::VARIATION, 'variation-1', $this->price('999999.99'));

        $this->expectNotToPerformAssertions();
        $this->guard()->assertPriceIsSane($candidate, $regularList, null);
    }

    public function test_manual_sale_priced_above_regular_throws(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem($regularList, PriceListItemTargetType::VARIATION, 'variation-1', $this->price('19.99'));

        $manualSaleList = PriceList::createSystemList('Manual Sale', PriceListMode::FIXED_ITEMS, priority: 1000);
        $this->priceListRepository()->save($manualSaleList);

        $candidate = new PriceListItem(null, $manualSaleList->id(), PriceListItemTargetType::VARIATION, 'variation-1', $this->price('24.99'));

        $this->expectException(RestrictedPriceExceedsRegularException::class);
        $this->guard()->assertPriceIsSane($candidate, $manualSaleList, null);
    }

    public function test_no_regular_price_configured_for_target_does_not_throw(): void
    {
        $regularList = $this->seedRegularPricesList();
        // "Regular Prices" exists but has no item at all for this target.

        $wholesaleList = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);
        $this->priceListRepository()->save($wholesaleList);

        $candidate = new PriceListItem(null, $wholesaleList->id(), PriceListItemTargetType::VARIATION, 'variation-unpriced', $this->price('9.99'));

        $this->expectNotToPerformAssertions();
        $this->guard()->assertPriceIsSane($candidate, $wholesaleList, null);
    }

    public function test_variation_level_item_compares_against_product_level_regular_fallback(): void
    {
        $regularList = $this->seedRegularPricesList();
        // Only a PRODUCT-level regular price exists — no VARIATION-level
        // override for 'variation-1' — so the guard must resolve the
        // fallback correctly (§4.3) before comparing.
        $this->addItem($regularList, PriceListItemTargetType::PRODUCT, 'product-1', $this->price('24.99'));

        $wholesaleList = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);
        $this->priceListRepository()->save($wholesaleList);

        $candidate = new PriceListItem(null, $wholesaleList->id(), PriceListItemTargetType::VARIATION, 'variation-1', $this->price('29.99'));

        $this->expectException(RestrictedPriceExceedsRegularException::class);
        $this->expectExceptionMessage('24.99 EUR');
        $this->guard()->assertPriceIsSane($candidate, $wholesaleList, 'product-1');
    }

    public function test_mixed_tax_basis_normalizes_to_net_before_comparing(): void
    {
        $regularList = $this->seedRegularPricesList();
        // Regular price stored tax-exclusive: net = 20.00 EUR.
        $this->addItem($regularList, PriceListItemTargetType::VARIATION, 'variation-1', $this->price('20.00', 2000, inclusive: false));

        $wholesaleList = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);
        $this->priceListRepository()->save($wholesaleList);

        // Candidate stored tax-inclusive: gross 24.00 EUR at 20% tax nets
        // to exactly 20.00 EUR too — a naive comparison of raw stored
        // Money (2400 vs 2000 minor units) would wrongly throw; the
        // correct net()-normalized comparison must not.
        $candidate = new PriceListItem(null, $wholesaleList->id(), PriceListItemTargetType::VARIATION, 'variation-1', $this->price('24.00', 2000, inclusive: true));

        $this->expectNotToPerformAssertions();
        $this->guard()->assertPriceIsSane($candidate, $wholesaleList, null);
    }

    public function test_comparison_uses_the_same_quantity_tier_not_the_base_regular_price(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem($regularList, PriceListItemTargetType::VARIATION, 'variation-1', $this->price('29.99'), minQuantity: 1);
        // A lower regular price at the qty=10 tier — the candidate below
        // is also minQuantity=10, so it must be compared against THIS
        // tier (18.00), not the base qty=1 tier (29.99).
        $this->addItem($regularList, PriceListItemTargetType::VARIATION, 'variation-1', $this->price('18.00'), minQuantity: 10);

        $wholesaleList = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);
        $this->priceListRepository()->save($wholesaleList);

        // 19.00 is below the base tier (29.99) but above the qty=10 tier
        // (18.00) — a bug comparing against the base tier would miss this.
        $candidate = new PriceListItem(null, $wholesaleList->id(), PriceListItemTargetType::VARIATION, 'variation-1', $this->price('19.00'), minQuantity: 10);

        $this->expectException(RestrictedPriceExceedsRegularException::class);
        $this->expectExceptionMessage('18.00 EUR');
        $this->guard()->assertPriceIsSane($candidate, $wholesaleList, null);
    }
}
