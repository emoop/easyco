<?php

namespace Tests\Feature\Storefront;

use App\Services\PriceSort\ProductSortPriceRefresher;
use App\Storefront\Reader\CatalogReader;
use App\Storefront\ReadModels\ListingQuery;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Contracts\PriceListScopeRepository;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Enums\PriceListScopeType;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P6a: the stored sort price (`catalog_products.price_from_minor`) is EQUAL to the «от» price of the card for every kind of product, the refresher fixes a corrupted value, writes only what changed, costs a
 * constant number of queries per chunk, and a product saved through the normal repository never loses it.
 */
class PriceSortRefresherTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->showSoldOutProducts();

        config(['filesystems.disks.public.url' => 'https://cdn.test/storage']);
        $this->seedPricing();
    }

    /** @return array<string, int|null> slug => the stored price */
    private function stored(): array
    {
        return DB::table('catalog_products')->orderBy('id')->pluck('price_from_minor', 'slug')->map(fn ($v) => $v === null ? null : (int) $v)->all();
    }

    /** @return array<string, int|null> slug => the card's «от» price (fromMinor), null when the card has no price */
    private function cards(): array
    {
        $this->app->forgetScopedInstances();
        $page = app(CatalogReader::class)->listing(ListingQuery::fromRequestArray(['per_page' => '12']));
        $cards = [];

        foreach ($page->items as $card) {
            $cards[$card->slug] = $card->price?->fromMinor;
        }

        ksort($cards);

        return $cards;
    }

    /** The mixed catalog of the owner's rule: simple, variable with a sold-out cheapest size, all sold out, sale price, a price list, unpriced, a hidden cheaper variation. */
    private function mixedCatalog(): void
    {
        $this->makeBrand(1, 'Acme', 'acme');
        $this->makeAttribute(1, 'Size', ['S', 'M', 'L']);

        $this->makeProduct(1, ['slug' => 'simple', 'price' => '20.00', 'stock' => 5]);
        $this->makeProduct(2, ['slug' => 'sold-out-cheapest', 'type' => 'variable', 'variations' => [
            ['attrs' => [1 => 'S'], 'price' => '10.00', 'stock' => 0],
            ['attrs' => [1 => 'M'], 'price' => '12.00', 'stock' => 3],
            ['attrs' => [1 => 'L'], 'price' => '14.00', 'stock' => 1],
        ]]);
        $this->makeProduct(3, ['slug' => 'all-sold-out', 'type' => 'variable', 'variations' => [
            ['attrs' => [1 => 'S'], 'price' => '30.00', 'stock' => 0],
            ['attrs' => [1 => 'M'], 'price' => '25.00', 'stock' => 0],
        ]]);
        $this->makeProduct(4, ['slug' => 'on-sale', 'price' => '50.00', 'sale' => '40.00', 'stock' => 1]);
        $this->makeProduct(5, ['slug' => 'unpriced', 'stock' => 2]);
        $this->makeProduct(6, ['slug' => 'acme-product', 'brand' => 1, 'price' => '100.00', 'stock' => 2]);
        $this->makeProduct(7, ['slug' => 'hidden-cheaper', 'type' => 'variable', 'variations' => [
            ['attrs' => [1 => 'S'], 'price' => '1.00', 'stock' => 3, 'status' => 'draft'],
            ['attrs' => [1 => 'M'], 'price' => '70.00', 'stock' => 2],
        ]]);

        $list = PriceList::create('Acme -20%', PriceListMode::PERCENTAGE_OFF_REGULAR, priority: 2000, percentageBasisPoints: 2000); // above the manual sale list's own priority (the pricing seeder: «above 1000 to override the manual sale»)
        app(PriceListRepository::class)->save($list);
        app(PriceListScopeRepository::class)->attach(new PriceListScope(id: null, priceListId: $list->id(), scopeType: PriceListScopeType::BRAND, scopeReferenceId: '1'));
    }

    private function corruptAndRefreshAll(): void
    {
        DB::table('catalog_products')->update(['price_from_minor' => 999]);
        app(ProductSortPriceRefresher::class)->refresh(DB::table('catalog_products')->pluck('id')->all());
    }

    /** ★ the stored value equals the card's price for the mixed catalog, in every case */
    public function test_the_stored_value_equals_the_cards_price_for_every_kind_of_product(): void
    {
        $this->mixedCatalog();
        $this->corruptAndRefreshAll();

        $this->assertSame([
            'acme-product' => 8000,
            'all-sold-out' => 2500,
            'hidden-cheaper' => 7000,
            'on-sale' => 4000,
            'simple' => 2000,
            'sold-out-cheapest' => 1200,
            'unpriced' => null,
        ], $this->cards(), 'the cards: the sold-out cheapest size is ignored, a sold-out product falls back to its lowest price, the price list and the sale count, the draft variation never');

        $stored = $this->stored();
        ksort($stored);

        $this->assertSame($this->cards(), $stored, 'the stored value IS the card price');
    }

    /** ★ the rule on its own cases */
    public function test_the_rule_cases_in_the_column(): void
    {
        $this->mixedCatalog();
        $this->corruptAndRefreshAll();
        $stored = $this->stored();

        $this->assertSame(1200, $stored['sold-out-cheapest'], 'a sold-out cheapest size is ignored while another size is available');
        $this->assertSame(2500, $stored['all-sold-out'], 'fully sold out: the fallback price');
        $this->assertNull($stored['unpriced'], 'unpriced: NULL');
    }

    /** ★ the refresher fixes a deliberately corrupted value, and writes ONLY the rows whose value changed (idempotent) */
    public function test_a_corrupted_value_is_fixed_and_only_changed_rows_are_written(): void
    {
        $this->mixedCatalog();
        app(ProductSortPriceRefresher::class)->refresh(DB::table('catalog_products')->pluck('id')->all());
        $good = $this->stored();

        DB::table('catalog_products')->where('slug', 'simple')->update(['price_from_minor' => 1]);
        DB::table('catalog_products')->where('slug', 'unpriced')->update(['price_from_minor' => 5]);

        $written = app(ProductSortPriceRefresher::class)->refresh(DB::table('catalog_products')->pluck('id')->all());

        $this->assertSame(2, $written, 'exactly the two corrupted rows');
        $this->assertSame($good, $this->stored());

        $updates = 0;
        DB::listen(function ($query) use (&$updates): void {
            preg_match('/^\s*update\s+`?catalog_products/i', $query->sql) === 1 && $updates++;
        });

        $this->assertSame(0, app(ProductSortPriceRefresher::class)->refresh(DB::table('catalog_products')->pluck('id')->all()), 'a second run writes nothing');
        $this->assertSame(0, $updates, 'and issues no write statement');
    }

    /** ★ a chunk costs the same number of queries for 1 and for 60 products (one grouped read per kind, ONE write for all the changed rows) */
    public function test_a_chunk_costs_the_same_number_of_queries_for_one_and_for_many_products(): void
    {
        $this->makeProduct(1, ['slug' => 'solo', 'price' => '10.00', 'stock' => 1]);

        for ($i = 2; $i <= 61; $i++) {
            $this->makeProduct($i, ['slug' => 'many-'.$i, 'price' => ($i * 3).'.00', 'stock' => $i % 3]);
        }

        $count = function (array $ids): int {
            DB::table('catalog_products')->update(['price_from_minor' => 999]);
            $n = 0;
            DB::listen(function () use (&$n): void {
                $n++;
            });
            app(ProductSortPriceRefresher::class)->refresh($ids);

            return $n;
        };

        $count([1]); // warm-up
        $one = $count([1]);
        $many = $count(DB::table('catalog_products')->pluck('id')->all());

        fwrite(STDERR, "\n[query-count] price refresh chunk: 1 product {$one}, 61 products {$many}\n");
        $this->assertSame($one, $many);
    }

    /** ★ the nightly command repairs a corrupted value and reports the counts */
    public function test_the_nightly_command_fixes_a_corrupted_value(): void
    {
        $this->mixedCatalog();
        $this->corruptAndRefreshAll();
        $good = $this->stored();
        DB::table('catalog_products')->update(['price_from_minor' => 12345]);

        $this->assertSame(0, Artisan::call('prices:refresh-from'));
        $this->assertSame($good, $this->stored());
        $this->assertStringContainsString('Done: 7 products, 7 rewritten.', Artisan::output());

        Artisan::call('prices:refresh-from');
        $this->assertStringContainsString('7 products, 0 rewritten', Artisan::output(), 'idempotent');
    }

    public function test_the_nightly_command_walks_every_product_in_chunks_of_about_two_hundred(): void
    {
        $this->assertSame(200, ProductSortPriceRefresher::CHUNK);

        for ($i = 1; $i <= 5; $i++) {
            $this->makeProduct($i, ['slug' => 'p'.$i, 'price' => ($i * 10).'.00', 'stock' => 1]);
        }

        $chunks = [];
        $result = app(ProductSortPriceRefresher::class)->refreshAll(function (int $products) use (&$chunks): void {
            $chunks[] = $products;
        });

        $this->assertSame(['products' => 5, 'written' => 0], $result);
        $this->assertSame([5], $chunks);
    }

    /** ★ a product saved through the normal repository does NOT lose the column (the repository never touches it) */
    public function test_saving_a_product_through_the_repository_does_not_reset_the_column(): void
    {
        $this->makeProduct(1, ['slug' => 'keeper', 'price' => '33.00', 'stock' => 4]);
        app(ProductSortPriceRefresher::class)->refresh([1]);
        $this->assertSame(3300, $this->stored()['keeper']);

        Queue::fake(); // no refresh job may repair a reset: the column itself must survive the save

        $products = app(ProductRepository::class);
        $product = $products->findById('1');
        $product->setCatalogVisibility(\EasyCo\Catalog\Enums\CatalogVisibility::HIDDEN);
        $products->save($product);

        $this->assertSame(3300, $this->stored()['keeper'], 'the save left the column alone');
        Queue::assertPushed(\App\Jobs\RefreshProductSortPrices::class, fn ($job) => $job->productIds === ['1']);
    }
}
