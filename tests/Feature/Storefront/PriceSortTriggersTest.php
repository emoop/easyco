<?php

namespace Tests\Feature\Storefront;

use App\Jobs\RefreshProductSortPrices;
use App\Services\PriceSort\ProductSortPriceRefresher;
use App\Services\ProductPricingAndStock;
use EasyCo\Catalog\Contracts\ProductTagRepository;
use EasyCo\Catalog\Persistence\Eloquent\VariationModel;
use EasyCo\Catalog\ProductTag;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Contracts\PriceListScopeRepository;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Enums\PriceListScopeType;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P6a, the FAST PATH: after an event the stored sort price of the affected product(s) is right at once (the queue is sync in tests). Each trigger of SortPriceTriggers / StockRefreshingRepository has a test; a price
 * list's write refreshes the products it can touch and no others.
 */
class PriceSortTriggersTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPricing();
    }

    private function stored(string $slug): ?int
    {
        $value = DB::table('catalog_products')->where('slug', $slug)->value('price_from_minor');

        return $value === null ? null : (int) $value;
    }

    private function refreshAll(): void
    {
        app(ProductSortPriceRefresher::class)->refresh(DB::table('catalog_products')->pluck('id')->all());
    }

    /** Variable product 2 «sizes» (S 10.00, M 12.00, both in stock 2): variations 201 and 202. */
    private function sizes(): void
    {
        $this->makeAttribute(1, 'Size', ['S', 'M']);
        $this->makeProduct(2, ['slug' => 'sizes', 'type' => 'variable', 'variations' => [
            ['attrs' => [1 => 'S'], 'price' => '10.00', 'stock' => 2],
            ['attrs' => [1 => 'M'], 'price' => '12.00', 'stock' => 2],
        ]]);
        $this->refreshAll();
    }

    /** ★ a regular / sale price saved (the admin's writer) changes the value */
    public function test_a_sale_price_saved_changes_the_value(): void
    {
        $this->makeProduct(1, ['slug' => 'priced', 'price' => '50.00', 'stock' => 1]);
        $this->refreshAll();
        $this->assertSame(5000, $this->stored('priced'));

        app(ProductPricingAndStock::class)->writeSalePrice('101', '40.00');
        $this->assertSame(4000, $this->stored('priced'), 'the sale price');

        app(ProductPricingAndStock::class)->writeSalePrice('101', null);
        $this->assertSame(5000, $this->stored('priced'), 'the sale price removed');

        app(ProductPricingAndStock::class)->writeRegularPrice('101', '55.00');
        $this->assertSame(5500, $this->stored('priced'), 'the regular price');
    }

    /** ★ a stock level that goes to 0 and back changes the value (the admin's save) */
    public function test_a_stock_save_to_zero_and_back_changes_the_value(): void
    {
        $this->sizes();
        $this->assertSame(1000, $this->stored('sizes'));

        app(StockLevelRepository::class)->save(StockLevel::forVariation('201', 0));
        $this->assertSame(1200, $this->stored('sizes'), 'the cheapest size is sold out');

        app(StockLevelRepository::class)->save(StockLevel::forVariation('201', 3));
        $this->assertSame(1000, $this->stored('sizes'), 'and back');
    }

    /** ★ checkout takes the last piece (decrease) and a return puts one back (increase): the value follows */
    public function test_a_decrease_to_zero_and_an_increase_from_zero_change_the_value(): void
    {
        $this->sizes();

        app(StockLevelRepository::class)->decrease('201', 1);
        $this->assertSame(1000, $this->stored('sizes'), 'one left: still available');

        app(StockLevelRepository::class)->decrease('201', 1);
        $this->assertSame(1200, $this->stored('sizes'), 'none left: the next size sets the price');

        app(StockLevelRepository::class)->increase('201', 1);
        $this->assertSame(1000, $this->stored('sizes'), 'restocked');
    }

    /** a stock change that does not cross zero queues nothing (checkout pays no refresh for it) */
    public function test_a_stock_change_that_does_not_cross_zero_queues_nothing(): void
    {
        $this->sizes();
        Queue::fake();

        app(StockLevelRepository::class)->decrease('201', 1); // 2 -> 1
        app(StockLevelRepository::class)->increase('201', 4); // 1 -> 5

        Queue::assertNothingPushed();
    }

    /** ★ a variation deactivated / activated changes the value */
    public function test_a_variation_deactivated_and_activated_changes_the_value(): void
    {
        $this->sizes();

        $variation = VariationModel::query()->findOrFail(201);
        $variation->status = 'draft';
        $variation->save();
        $this->assertSame(1200, $this->stored('sizes'));

        $variation->status = 'active';
        $variation->save();
        $this->assertSame(1000, $this->stored('sizes'));
    }

    /** ★ a variation removed changes the value */
    public function test_a_variation_removed_changes_the_value(): void
    {
        $this->sizes();

        VariationModel::query()->findOrFail(201)->delete();

        $this->assertSame(1200, $this->stored('sizes'));
    }

    /** ★ ANY product save through the repository queues a refresh of that product (the admin's product form saves this way) */
    public function test_any_product_save_queues_a_refresh_of_the_product(): void
    {
        $this->makeProduct(1, ['slug' => 'priced', 'price' => '50.00', 'stock' => 1]);
        Queue::fake();

        $products = app(\EasyCo\Catalog\Contracts\ProductRepository::class);
        $product = $products->findById('1');
        $product->setCatalogVisibility(\EasyCo\Catalog\Enums\CatalogVisibility::HIDDEN);
        $products->save($product);

        Queue::assertPushed(RefreshProductSortPrices::class, fn ($job) => $job->productIds === ['1']);
    }

    /** ★ saving a product with a NEW tag that an undated active list scopes changes its value at once */
    public function test_a_new_tag_that_a_list_scopes_changes_the_value(): void
    {
        $this->makeTag(5, 'Summer', 'summer');
        $this->makeProduct(1, ['slug' => 'tagged-later', 'price' => '100.00', 'stock' => 1]);
        $this->makeProduct(2, ['slug' => 'other', 'price' => '100.00', 'stock' => 1]);
        $this->refreshAll();

        $list = PriceList::create('Summer -25%', PriceListMode::PERCENTAGE_OFF_REGULAR, priority: 2000, percentageBasisPoints: 2500);
        app(PriceListRepository::class)->save($list);
        app(PriceListScopeRepository::class)->attach(new PriceListScope(null, $list->id(), PriceListScopeType::TAG, '5'));
        $this->assertSame(10000, $this->stored('tagged-later'), 'not tagged yet: the list does not apply');

        app(ProductTagRepository::class)->save(new ProductTag(null, '1', '5'));

        $this->assertSame(7500, $this->stored('tagged-later'), 'the tag makes the list apply');
        $this->assertSame(10000, $this->stored('other'));
    }

    /** ★ a price list WITHOUT dates takes effect when it is saved: an undated active list scoped to a brand changes its products at once */
    public function test_an_undated_list_changes_its_scoped_products_at_once(): void
    {
        $this->makeBrand(1, 'Acme', 'acme');
        $this->makeProduct(1, ['slug' => 'acme-one', 'brand' => 1, 'price' => '100.00', 'stock' => 1]);
        $this->makeProduct(2, ['slug' => 'other', 'price' => '100.00', 'stock' => 1]);
        $this->refreshAll();

        $list = PriceList::create('Acme -20%', PriceListMode::PERCENTAGE_OFF_REGULAR, priority: 2000, percentageBasisPoints: 2000);
        app(PriceListRepository::class)->save($list);
        app(PriceListScopeRepository::class)->attach(new PriceListScope(null, $list->id(), PriceListScopeType::BRAND, '1'));

        $this->assertSame(8000, $this->stored('acme-one'));
        $this->assertSame(10000, $this->stored('other'));
    }

    /** ★ a list write refreshes exactly the products its scope matches and no others (the others keep even a wrong value: they were never touched) */
    public function test_a_list_write_refreshes_only_the_products_of_its_scope(): void
    {
        $this->makeBrand(1, 'Acme', 'acme');
        $this->makeProduct(1, ['slug' => 'acme-one', 'brand' => 1, 'price' => '100.00', 'stock' => 1]);
        $this->makeProduct(2, ['slug' => 'other', 'price' => '100.00', 'stock' => 1]);

        $list = PriceList::create('Acme -20%', PriceListMode::PERCENTAGE_OFF_REGULAR, priority: 2000, percentageBasisPoints: 2000);
        app(PriceListRepository::class)->save($list);
        app(PriceListScopeRepository::class)->attach(new PriceListScope(null, $list->id(), PriceListScopeType::BRAND, '1'));
        DB::table('catalog_products')->update(['price_from_minor' => 999]);

        // the list is switched off: its products (and only they) are recomputed
        $list->deactivate();
        app(PriceListRepository::class)->save($list);

        $this->assertSame(10000, $this->stored('acme-one'), 'a scoped product is refreshed');
        $this->assertSame(999, $this->stored('other'), 'a product outside the scope is not touched');

        // and on again
        $list->activate();
        app(PriceListRepository::class)->save($list);
        $this->assertSame(8000, $this->stored('acme-one'));
        $this->assertSame(999, $this->stored('other'));
    }

    /** a list WITHOUT a scope can touch every product: ALL of them are queued, in chunks */
    public function test_a_list_without_a_scope_queues_every_product(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->makeProduct($i, ['slug' => 'p'.$i, 'price' => '10.00', 'stock' => 1]);
        }

        Queue::fake();
        $list = PriceList::create('Everything -10%', PriceListMode::PERCENTAGE_OFF_REGULAR, priority: 2000, percentageBasisPoints: 1000);
        app(PriceListRepository::class)->save($list);

        Queue::assertPushed(RefreshProductSortPrices::class, fn ($job) => $job->productIds === ['1', '2', '3']);
    }

    /** ★ a queue that is DOWN never breaks the write that caused the refresh (a checkout takes stock, the admin saves): the failure is logged and the nightly pass repairs the value */
    public function test_a_queue_that_is_down_never_breaks_the_write(): void
    {
        $this->sizes();

        $queue = \Mockery::mock(\Illuminate\Contracts\Queue\Queue::class)->shouldIgnoreMissing();
        $queue->shouldReceive('push')->andThrow(new \RuntimeException('queue is down'));
        $queue->shouldReceive('pushOn')->andThrow(new \RuntimeException('queue is down'));
        $factory = \Mockery::mock(\Illuminate\Contracts\Queue\Factory::class);
        $factory->shouldReceive('connection')->andReturn($queue);
        $original = app('queue');
        $this->app->instance('queue', $factory);

        // the stock write, a price write and a product save all complete (no exception)
        app(StockLevelRepository::class)->decrease('201', 2);
        app(ProductPricingAndStock::class)->writeSalePrice('201', '9.00');
        $products = app(\EasyCo\Catalog\Contracts\ProductRepository::class);
        $products->save($products->findById('2'));

        $this->app->instance('queue', $original);
        $this->assertSame(0, (int) DB::table('stock_levels')->where('variation_id', '201')->value('quantity'), 'the stock write stands');

        // and the nightly pass repairs the value the failed queue could not
        app(ProductSortPriceRefresher::class)->refresh([2]);
        $this->assertSame(1200, $this->stored('sizes'), 'S (sold out, now on sale at 9.00) is ignored: the only available size is M at 12.00');
    }

    /** a list whose valid_until is in the past is ignored by the resolver: the value does not change */
    public function test_a_list_that_has_already_ended_changes_nothing(): void
    {
        $this->makeProduct(1, ['slug' => 'p1', 'price' => '100.00', 'stock' => 1]);
        $this->refreshAll();

        $list = PriceList::create('Over', PriceListMode::PERCENTAGE_OFF_REGULAR, priority: 2000, validFrom: new \DateTimeImmutable('-10 days'), validUntil: new \DateTimeImmutable('-1 day'), percentageBasisPoints: 5000);
        app(PriceListRepository::class)->save($list);

        $this->assertSame(10000, $this->stored('p1'));
    }
    /** ★ the triggers never stop the OTHER listeners of a model event (Laravel stops the rest when one returns `false`: the menus listen to the same events), muted or not */
    public function test_the_triggers_do_not_stop_other_listeners_of_the_same_event(): void
    {
        $this->makeProduct(1, ['slug' => 'priced', 'price' => '50.00', 'stock' => 1]);
        $ran = [];
        \EasyCo\Catalog\Persistence\Eloquent\ProductModel::saved(function () use (&$ran): void {
            $ran[] = 'later listener';
        });
        $products = app(\EasyCo\Catalog\Contracts\ProductRepository::class);

        $product = $products->findById('1');
        $product->setCatalogVisibility(\EasyCo\Catalog\Enums\CatalogVisibility::HIDDEN);
        $products->save($product);
        \App\Services\PriceSort\SortPriceTriggers::muted(function () use ($products): void {
            $product = $products->findById('1');
            $product->setCatalogVisibility(\EasyCo\Catalog\Enums\CatalogVisibility::VISIBLE);
            $products->save($product);
        });

        $this->assertSame(['later listener', 'later listener'], $ran, 'a listener registered after the triggers still runs, while the triggers are active AND while they are muted');
    }
}
