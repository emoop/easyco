<?php

namespace Tests\Feature;

use App\Services\ProductPriceRangeProvider;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * App\Services\ProductPriceRangeProvider — the app-layer composition
 * point that groups PriceRangeResolver's batched quotes back into one
 * PriceRange per Product.
 */
class ProductPriceRangeProviderTest extends TestCase
{
    use RefreshDatabase;

    private function provider(): ProductPriceRangeProvider
    {
        return app(ProductPriceRangeProvider::class);
    }

    private function seedRegularPricesList(): PriceList
    {
        $list = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);
        app(PriceListRepository::class)->save($list);

        return $list;
    }

    private function addItem(PriceList $list, PriceListItemTargetType $targetType, string $targetId, string $decimal): void
    {
        $item = new PriceListItem(null, $list->id(), $targetType, $targetId, Price::exclusiveOfTax(Money::fromDecimal($decimal, 'EUR'), 2000));
        app(PriceListItemRepository::class)->save($item);
    }

    public function test_nothing_priced_yields_an_empty_range(): void
    {
        $this->seedRegularPricesList();

        $product = Product::createSimple('Unpriced', 'SKU-UNPRICED', 'unpriced');
        app(ProductRepository::class)->save($product);

        $range = $this->provider()->forProduct($product->id());

        $this->assertTrue($range->isEmpty());
    }

    public function test_product_level_only_price(): void
    {
        $regularList = $this->seedRegularPricesList();

        $product = Product::createSimple('Priced', 'SKU-PRICED', 'priced');
        app(ProductRepository::class)->save($product);

        $this->addItem($regularList, PriceListItemTargetType::PRODUCT, $product->id(), '19.99');

        $range = $this->provider()->forProduct($product->id());

        $this->assertFalse($range->isEmpty());
        $this->assertTrue($range->lowestRegularPrice()->net()->equals(Money::fromDecimal('19.99', 'EUR')));
    }

    public function test_variation_override(): void
    {
        $regularList = $this->seedRegularPricesList();

        $product = Product::createSimple('Override', 'SKU-OVERRIDE', 'override');
        app(ProductRepository::class)->save($product);
        $variationId = $product->variations()[0]->id();

        $this->addItem($regularList, PriceListItemTargetType::PRODUCT, $product->id(), '19.99');
        $this->addItem($regularList, PriceListItemTargetType::VARIATION, $variationId, '24.99');

        $range = $this->provider()->forProduct($product->id());

        $this->assertTrue($range->lowestRegularPrice()->net()->equals(Money::fromDecimal('24.99', 'EUR')));
    }

    public function test_an_archived_variation_is_excluded(): void
    {
        $regularList = $this->seedRegularPricesList();

        $product = Product::createSimple('Archived', 'SKU-ARCHIVED', 'archived');
        app(ProductRepository::class)->save($product);
        $variation = $product->variations()[0];
        $this->addItem($regularList, PriceListItemTargetType::VARIATION, $variation->id(), '9.99');

        $variation->activate();
        $variation->archive();
        app(ProductRepository::class)->save($product);

        $range = $this->provider()->forProduct($product->id());

        $this->assertTrue($range->isEmpty(), 'an archived variation must never contribute to the range');
    }

    public function test_partially_priced_products_in_a_batch_each_get_their_own_correct_range(): void
    {
        $regularList = $this->seedRegularPricesList();

        $pricedProduct = Product::createSimple('Priced', 'SKU-PRICED-2', 'priced-2');
        app(ProductRepository::class)->save($pricedProduct);
        $this->addItem($regularList, PriceListItemTargetType::VARIATION, $pricedProduct->variations()[0]->id(), '14.99');

        $unpricedProduct = Product::createSimple('Unpriced', 'SKU-UNPRICED-2', 'unpriced-2');
        app(ProductRepository::class)->save($unpricedProduct);

        $ranges = $this->provider()->forProducts([$pricedProduct->id(), $unpricedProduct->id()]);

        $this->assertCount(2, $ranges);
        $this->assertFalse($ranges[$pricedProduct->id()]->isEmpty());
        $this->assertTrue($ranges[$unpricedProduct->id()]->isEmpty());
    }

    /** Locks that forProduct() is a thin wrapper delegating to forProducts(). */
    public function test_for_product_agrees_with_for_products(): void
    {
        $regularList = $this->seedRegularPricesList();

        $product = Product::createSimple('Priced', 'SKU-PRICED-3', 'priced-3');
        app(ProductRepository::class)->save($product);
        $this->addItem($regularList, PriceListItemTargetType::VARIATION, $product->variations()[0]->id(), '9.99');

        $viaSingular = $this->provider()->forProduct($product->id());
        $viaBatched = $this->provider()->forProducts([$product->id()])[$product->id()];

        $this->assertSame(
            $viaSingular->lowestRegularPrice()?->net()->decimalValue(),
            $viaBatched->lowestRegularPrice()?->net()->decimalValue()
        );
    }

    public function test_query_count_for_5_vs_25_products_is_equal(): void
    {
        $regularList = $this->seedRegularPricesList();

        $productIds = [];
        for ($i = 1; $i <= 25; $i++) {
            $product = Product::createSimple("Product {$i}", "SKU-QC-{$i}", "qc-product-{$i}");
            app(ProductRepository::class)->save($product);
            $this->addItem($regularList, PriceListItemTargetType::VARIATION, $product->variations()[0]->id(), '9.99');
            $productIds[] = $product->id();
        }

        $countQueries = function (callable $callback): int {
            $count = 0;
            DB::listen(function () use (&$count): void {
                $count++;
            });

            $callback();

            DB::flushQueryLog();

            return $count;
        };

        $queriesForFive = $countQueries(fn () => $this->provider()->forProducts(array_slice($productIds, 0, 5)));
        $queriesForTwentyFive = $countQueries(fn () => $this->provider()->forProducts($productIds));

        fwrite(STDERR, "\n[query-count] 5 products: {$queriesForFive} queries, 25 products: {$queriesForTwentyFive} queries\n");

        $this->assertSame($queriesForFive, $queriesForTwentyFive, 'query count must not grow with the number of products');
    }
}
