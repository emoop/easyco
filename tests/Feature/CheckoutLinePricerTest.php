<?php

namespace Tests\Feature;

use App\Services\CheckoutLinePricer;
use EasyCo\Catalog\Brand;
use EasyCo\Catalog\Contracts\BrandRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\VariationRepository;
use EasyCo\Catalog\Product;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Contracts\PriceListScopeRepository;
use EasyCo\Pricing\Contracts\ProductCostRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Enums\PriceListScopeType;
use EasyCo\Pricing\Exceptions\PriceNotConfiguredException;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListItem;
use EasyCo\Pricing\PriceListScope;
use EasyCo\Pricing\ProductCost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * App\Services\CheckoutLinePricer — per-line price + profit resolution
 * for Checkout, per checkout-domain-design.md §8.3 step 3 / §9.3. Real
 * MySQL Feature test, reusing CartControllerTest's exact fixture-helper
 * shape rather than reinventing it.
 */
class CheckoutLinePricerTest extends TestCase
{
    use RefreshDatabase;

    private static int $productCounter = 0;

    private ?PriceList $priceList = null;

    private function pricer(): CheckoutLinePricer
    {
        return app(CheckoutLinePricer::class);
    }

    private function variationId(): string
    {
        self::$productCounter++;
        $suffix = (string) self::$productCounter;

        $product = Product::createSimple("Product {$suffix}", "SKU-{$suffix}", "product-slug-{$suffix}");
        app(ProductRepository::class)->save($product);

        return $product->variations()[0]->id();
    }

    private function setPrice(string $variationId, string $decimalAmount): PriceListItem
    {
        if ($this->priceList === null) {
            $this->priceList = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);
            app(PriceListRepository::class)->save($this->priceList);
        }

        $item = new PriceListItem(
            null,
            $this->priceList->id(),
            PriceListItemTargetType::VARIATION,
            $variationId,
            Price::exclusiveOfTax(Money::fromDecimal($decimalAmount, 'EUR'), 0),
        );
        app(PriceListItemRepository::class)->save($item);

        return $item;
    }

    private function setStock(string $variationId, int $quantity): void
    {
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, $quantity));
    }

    private function pricedPurchasableVariation(string $decimalAmount = '10.00', int $stock = 10): string
    {
        $variationId = $this->variationId();
        $this->setPrice($variationId, $decimalAmount);
        $this->setStock($variationId, $stock);

        return $variationId;
    }

    private function setCost(string $variationId, string $decimalAmount, string $currency = 'EUR'): void
    {
        $cost = new ProductCost(id: null, priceableId: $variationId, cost: Money::fromDecimal($decimalAmount, $currency));
        app(ProductCostRepository::class)->save($cost);
    }

    /**
     * checkout-domain-design.md §9.3's own "known distortion, visible
     * not hidden" point: no recorded cost means profit == amount
     * (100%-margin), and costRecorded() surfaces that it's not a real
     * number.
     */
    public function test_a_line_with_no_recorded_cost_shows_the_full_amount_as_profit_and_flags_cost_not_recorded(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00');

        $result = $this->pricer()->priceLine($variationId, 2, 'EUR');

        $this->assertSame(2000, $result->amount()->minorValue());
        $this->assertSame(2000, $result->profit()->minorValue());
        $this->assertFalse($result->costRecorded());
    }

    /**
     * The real correction this task makes: cost must scale by quantity,
     * the same way amount already does. quantity 3 * (10.00 - 4.00) =
     * 18.00 profit — NOT 30.00 - 4.00 = 26.00, the wrong answer the
     * original, unscaled §9.3 formula would have given.
     */
    public function test_cost_scales_with_quantity_not_just_amount(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00');
        $this->setCost($variationId, '4.00');

        $result = $this->pricer()->priceLine($variationId, 3, 'EUR');

        $this->assertSame(3000, $result->amount()->minorValue());
        $this->assertSame(1800, $result->profit()->minorValue());
        $this->assertNotSame(2600, $result->profit()->minorValue());
        $this->assertTrue($result->costRecorded());
    }

    public function test_a_simple_quantity_one_line_with_a_recorded_cost(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00');
        $this->setCost($variationId, '4.00');

        $result = $this->pricer()->priceLine($variationId, 1, 'EUR');

        $this->assertSame(1000, $result->amount()->minorValue());
        $this->assertSame(600, $result->profit()->minorValue());
        $this->assertTrue($result->costRecorded());
    }

    public function test_product_id_matches_the_lines_real_product(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00');
        $productId = app(VariationRepository::class)->findById($variationId)->productId();

        $result = $this->pricer()->priceLine($variationId, 1, 'EUR');

        $this->assertSame($productId, $result->productId());
    }

    public function test_matching_scope_reference_ids_is_populated_when_the_product_has_a_real_brand(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00');
        $product = app(ProductRepository::class)->findById(
            app(VariationRepository::class)->findById($variationId)->productId()
        );
        $brand = new Brand(id: null, name: 'Nike', slug: 'nike-line-pricer-test');
        app(BrandRepository::class)->save($brand);
        $product->assignBrand($brand->id());
        app(ProductRepository::class)->save($product);

        $result = $this->pricer()->priceLine($variationId, 1, 'EUR');

        $this->assertSame([$brand->id()], $result->matchingScopeReferenceIds()['brand']);
    }

    public function test_is_discounted_is_false_for_a_plain_regular_priced_line(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00');

        $result = $this->pricer()->priceLine($variationId, 1, 'EUR');

        $this->assertFalse($result->isDiscounted());
    }

    /**
     * Mirrors CartControllerTest::test_get_resolves_a_brand_scoped_price_over_the_plain_regular_price()'s
     * own brand-scoped PriceList setup — proves isDiscounted() reflects
     * a real discounting PriceList actually winning, not just a flag
     * that's always false.
     */
    public function test_is_discounted_is_true_when_a_real_discounting_price_list_applies(): void
    {
        $variationId = $this->variationId();
        $this->setPrice($variationId, '10.00');
        $this->setStock($variationId, 10);

        $product = app(ProductRepository::class)->findById(
            app(VariationRepository::class)->findById($variationId)->productId()
        );
        $brand = new Brand(id: null, name: 'Nike', slug: 'nike-discounted-line-pricer-test');
        app(BrandRepository::class)->save($brand);
        $product->assignBrand($brand->id());
        app(ProductRepository::class)->save($product);

        $brandList = PriceList::create(
            'Nike -20%',
            PriceListMode::PERCENTAGE_OFF_REGULAR,
            priority: 10,
            percentageBasisPoints: 2000,
        );
        app(PriceListRepository::class)->save($brandList);

        $scope = new PriceListScope(
            id: null,
            priceListId: $brandList->id(),
            scopeType: PriceListScopeType::BRAND,
            scopeReferenceId: $brand->id(),
        );
        app(PriceListScopeRepository::class)->attach($scope);

        $result = $this->pricer()->priceLine($variationId, 1, 'EUR');

        $this->assertTrue($result->isDiscounted());
        $this->assertSame(800, $result->unitPrice()->minorValue());
    }

    public function test_a_variation_with_no_price_configured_throws(): void
    {
        // Seeds the system PriceList via an unrelated, actually-priced
        // variation first — without this, EloquentPriceResolver throws
        // its own "system list not seeded" RuntimeException before ever
        // reaching the "no matching item for this variation" case this
        // test actually wants to exercise.
        $this->pricedPurchasableVariation();

        $variationId = $this->variationId();

        $this->expectException(PriceNotConfiguredException::class);

        $this->pricer()->priceLine($variationId, 1, 'EUR');
    }
}
