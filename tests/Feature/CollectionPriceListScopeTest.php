<?php

namespace Tests\Feature;

use App\Jobs\RefreshProductSortPrices;
use App\Services\CatalogScopeLookup;
use App\Services\CatalogScopeResolver;
use App\Services\MassEdit\MassEditSelection;
use App\Services\MassEdit\ProductMassEditor;
use App\Services\PriceListAdminReader;
use App\Services\PriceListWriter;
use App\Services\PriceSort\SortPriceRefreshDispatcher;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Pricing\Contracts\PriceContext;
use EasyCo\Pricing\Enums\PriceListScopeType;
use EasyCo\Pricing\Contracts\PriceRangeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * P6e-3b: the price-list scope COLLECTION. A list scoped to collections prices exactly the products of those collections (OR inside the kind, AND with the other kinds); while the collections are OFF it never
 * applies (the collection ids ride in the scope resolver's EXISTING products read, with the switch inside the statement: no query of their own); the pivot triggers refresh the stored sort price.
 *
 * World: products 1..5 (100.00). Collection Wedding (1) holds 1, 2, 3; Summer (2) holds 3, 4; product 5 is in none. Brand Acme (1) has products 1 and 4.
 */
class CollectionPriceListScopeTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    private static int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.public.url' => 'https://cdn.test/storage']);
        $this->seedPricing();
        $this->makeBrand(1, 'Acme', 'acme');
        $this->makeCategory(1, 'Clothing', 'clothing');

        foreach ([1 => 'Wedding', 2 => 'Summer'] as $id => $name) {
            DB::table('catalog_collections')->insert(['id' => $id, 'name' => $name, 'slug' => strtolower($name), 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);
        }

        foreach ([1 => [1, [1]], 2 => [null, [1]], 3 => [null, [1, 2]], 4 => [1, [2]], 5 => [null, []]] as $id => [$brand, $collections]) {
            $this->makeProduct($id, ['slug' => 'p'.$id, 'price' => '100.00', 'stock' => 3, 'brand' => $brand, 'categories' => [1]]);

            foreach ($collections as $collection) {
                DB::table('catalog_product_collections')->insert(['product_id' => $id, 'collection_id' => $collection]);
            }
        }

        $this->on();
    }

    private function on(bool $on = true): void
    {
        $on ? app(SiteSettingsRepository::class)->set('catalog.collections_enabled', '1') : app(SiteSettingsRepository::class)->forget('catalog.collections_enabled');
        $this->app->forgetScopedInstances();
    }

    private function campaign(array $scopes, bool $active = true): string
    {
        return (string) app(PriceListWriter::class)->create([
            'name' => 'Campaign '.(++self::$n), 'percentage' => '30', 'is_active' => $active, 'replaces_manual_sale' => false, 'confirm_all_products' => false, 'scopes' => $scopes,
        ])->id();
    }

    private function finalMinor(int $variationId): ?int
    {
        $data = app(CatalogScopeResolver::class)->forVariations([(string) $variationId])[(string) $variationId];
        $quotes = app(PriceRangeResolver::class)->resolveQuotes([new PriceContext(priceableId: (string) $variationId, quantity: 1, currency: 'EUR', productId: $data['productId'], matchingScopeReferenceIds: $data['matchingScopeReferenceIds'])]);

        return isset($quotes[(string) $variationId]) ? $quotes[(string) $variationId]->final->gross()->minorValue() : null;
    }

    /** @return list<int> the products the active campaigns discount (−30% = 7000) */
    private function discounted(): array
    {
        $this->app->forgetScopedInstances();
        $hit = [];

        foreach ([1, 2, 3, 4, 5] as $product) {
            $this->finalMinor($product * 100 + 1) === 7000 && $hit[] = $product;
        }

        return $hit;
    }

    private function scope(string $kind, int|string $id): array
    {
        return ['type' => $kind, 'reference' => (string) $id];
    }

    public function test_a_collection_scoped_list_prices_exactly_the_products_of_that_collection(): void
    {
        $this->campaign([$this->scope('collection', 1)]);

        $this->assertSame([1, 2, 3], $this->discounted());
    }

    public function test_inside_the_kind_it_is_or_and_a_product_in_two_collections_matches_either(): void
    {
        $this->campaign([$this->scope('collection', 1), $this->scope('collection', 2)]);
        $this->assertSame([1, 2, 3, 4], $this->discounted());
    }

    public function test_a_product_in_two_collections_matches_a_list_of_either(): void
    {
        $id = $this->campaign([$this->scope('collection', 2)]);
        $this->assertSame([3, 4], $this->discounted());
        app(PriceListWriter::class)->setActive($id, false);
    }

    public function test_between_kinds_it_is_and(): void
    {
        $this->campaign([$this->scope('collection', 2), $this->scope('brand', 1)]);

        $this->assertSame([4], $this->discounted(), 'Summer AND Acme: only product 4');
    }

    public function test_off_the_list_never_applies_and_on_again_restores_it(): void
    {
        $this->campaign([$this->scope('collection', 1)]);
        $this->assertSame([1, 2, 3], $this->discounted());

        $this->on(false);
        $this->assertSame([], $this->discounted(), 'OFF: the regular price everywhere');

        $this->on();
        $this->assertSame([1, 2, 3], $this->discounted());
    }

    public function test_a_collection_scope_with_a_brand_does_not_widen_while_off(): void
    {
        $this->campaign([$this->scope('collection', 1), $this->scope('brand', 1)]);
        $this->assertSame([1], $this->discounted());

        $this->on(false);
        $this->assertSame([], $this->discounted(), 'AND with a kind that cannot match: nothing (it must not fall back to the brand alone)');
    }

    public function test_the_admin_shows_a_switched_off_scope_as_a_feature_note_and_keeps_its_label(): void
    {
        $id = $this->campaign([$this->scope('collection', 1)]);
        app()->setLocale('en');
        $reader = app(PriceListAdminReader::class);

        $this->assertSame(['Wedding'], $reader->scopeNames([$id])[$id]['collection']);

        $this->on(false);
        $this->assertSame(['Wedding (feature switched off)'], $reader->scopeNames([$id])[$id]['collection']);
        $this->assertSame('Wedding', app(CatalogScopeLookup::class)->label(PriceListScopeType::COLLECTION, '1'), 'a stored scope keeps its label');
        $this->assertTrue(app(CatalogScopeLookup::class)->exists(PriceListScopeType::COLLECTION, '1'), 'and can still be saved');
    }

    public function test_the_lookup_offers_the_kind_only_while_on(): void
    {
        $lookup = app(CatalogScopeLookup::class);

        $this->assertContains(PriceListScopeType::COLLECTION, CatalogScopeLookup::KINDS);
        $this->assertSame(['1' => 'Wedding'], $lookup->search(PriceListScopeType::COLLECTION, 'wed'));

        $this->on(false);
        $this->assertSame([], $lookup->search(PriceListScopeType::COLLECTION, 'wed'));
    }

    public function test_the_dispatcher_finds_the_collections_products_for_a_scope_change(): void
    {
        $dispatcher = app(SortPriceRefreshDispatcher::class);

        $this->assertEqualsCanonicalizing(['1', '2', '3'], $dispatcher->productsMatching(PriceListScopeType::COLLECTION, '1'));
        $this->assertEqualsCanonicalizing(['3', '4'], $dispatcher->productsMatching('collection', '2'));
        // AND with a brand: only the products both kinds allow
        $this->assertSame(['4'], $dispatcher->productsOfScopes([['collection', '2'], ['brand', '1']]));
    }

    /** the scope resolver reads the collections with the products read it already makes: no query of its own, whatever the switch or the scope rows */
    public function test_the_resolver_costs_the_same_queries_on_off_and_with_a_collection_scope(): void
    {
        $count = function (): int {
            app(CatalogScopeResolver::class)->forVariations(['101']);
            $n = 0;
            DB::listen(function () use (&$n): void {
                $n++;
            });
            app(CatalogScopeResolver::class)->forVariations(['101', '201', '301']);

            return $n;
        };

        $none = $count();
        $this->campaign([$this->scope('collection', 1)]);
        $withScope = $count();
        $this->on(false);
        $off = $count();

        $this->assertSame($none, $withScope, 'ON with a collection scope = no scope');
        $this->assertSame($none, $off, 'OFF = ON');
        $this->on();
        $data = app(CatalogScopeResolver::class)->forVariations(['301']);
        $this->assertEqualsCanonicalizing(['1', '2'], $data['301']['matchingScopeReferenceIds']['collection']);
        $this->assertArrayNotHasKey('collection', app(CatalogScopeResolver::class)->forVariations(['501'])['501']['matchingScopeReferenceIds'], 'a product in no collection has no key');
        $this->on(false);
        $this->assertArrayNotHasKey('collection', app(CatalogScopeResolver::class)->forVariations(['301'])['301']['matchingScopeReferenceIds'], 'OFF: no collection ids at all');
    }

    /** @return array{0: int, 1: string} the queries of a page (second visit) and its HTML */
    private function page(string $url): array
    {
        $this->app->forgetScopedInstances();
        $this->get($url)->assertOk();
        $this->app->forgetScopedInstances();
        $n = 0;
        DB::listen(function () use (&$n): void {
            $n++;
        });
        $html = $this->get($url)->assertOk()->getContent();

        return [$n, $html];
    }

    /** ★ the pages cost the same queries with no scope, with a COLLECTION scope ON and with it OFF — and the price follows the switch */
    public function test_the_priced_pages_keep_their_query_counts_with_a_collection_scope_on_and_off(): void
    {
        $this->withoutVite();
        $urls = ['product' => '/product/p1', 'category listing' => '/product-category/clothing', 'collection page' => '/collection/wedding'];
        $without = array_map(fn (string $u): int => $this->page($u)[0], $urls);

        $this->campaign([$this->scope('collection', 1)]);
        $this->app->forgetScopedInstances();

        foreach ($urls as $name => $url) {
            [$n, $html] = $this->page($url);
            $this->assertSame($without[$name], $n, "{$name}: ON with a collection scope costs the queries of no scope");
            $this->assertStringContainsString('70.00', $html, "{$name}: the sale price shows");
        }

        $this->on(false);

        foreach (['product' => '/product/p1', 'category listing' => '/product-category/clothing'] as $name => $url) {
            [$n, $html] = $this->page($url);
            $this->assertSame($without[$name], $n, "{$name}: OFF costs the same");
            $this->assertStringNotContainsString('70.00', $html, "{$name}: OFF: the regular price");
        }

        fwrite(STDERR, "\n[query-count] P6e-3b: no scope ".json_encode($without)." = scope ON = scope OFF\n");
    }

    public function test_changing_a_products_collections_refreshes_its_sort_price_through_the_pivot_trigger(): void
    {
        $this->campaign([$this->scope('collection', 2)]);

        // product 5 joins Summer: the pivot trigger queues a refresh of THAT product
        Queue::fake();
        DB::table('catalog_product_collections')->where('product_id', 5)->doesntExist();
        $model = \EasyCo\Catalog\Persistence\Eloquent\ProductCollectionModel::query()->create(['product_id' => 5, 'collection_id' => 2]);
        $this->assertCount(1, Queue::pushed(RefreshProductSortPrices::class));
        $this->assertSame(['5'], Queue::pushed(RefreshProductSortPrices::class)->first()->productIds);

        Queue::fake();
        $model->delete();
        $this->assertSame(['5'], Queue::pushed(RefreshProductSortPrices::class)->first()->productIds, 'detach refreshes too');
    }

    public function test_the_mass_add_and_remove_refresh_once_per_chunk_and_the_stored_price_follows(): void
    {
        $this->campaign([$this->scope('collection', 2)]);
        $editor = app(ProductMassEditor::class);

        Queue::fake();
        $editor->start(ProductMassEditor::ADD_COLLECTION, '2', new MassEditSelection(['1', '2', '5'], true, false));
        $this->assertCount(1, Queue::pushed(RefreshProductSortPrices::class), 'muted per row, ONE dispatcher call for the chunk');
        $this->assertEqualsCanonicalizing(['1', '2', '5'], Queue::pushed(RefreshProductSortPrices::class)->first()->productIds);

        // the real refresh: the stored «from» price of the products that joined Summer is now the campaign's
        $this->app->forgetScopedInstances();
        app(\App\Services\PriceSort\ProductSortPriceRefresher::class)->refresh(['1', '2', '5']);
        $stored = fn (int $id): int => (int) DB::table('catalog_products')->where('id', $id)->value('price_from_minor');
        $this->assertSame([7000, 7000, 7000], [$stored(1), $stored(2), $stored(5)]);

        Queue::fake();
        $editor->start(ProductMassEditor::REMOVE_COLLECTION, '2', new MassEditSelection(['5'], true, false));
        $this->assertSame(['5'], Queue::pushed(RefreshProductSortPrices::class)->first()->productIds);
        $this->app->forgetScopedInstances();
        app(\App\Services\PriceSort\ProductSortPriceRefresher::class)->refresh(['5']);
        $this->assertSame(10000, $stored(5), 'out of the collection: the regular price again');
    }
}
