<?php

namespace Tests\Feature\Storefront;

use App\Filament\Pages\Settings\CatalogSettings;
use App\Services\PriceSort\ProductSortPriceRefresher;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Reader\CatalogReader;
use App\Storefront\Reader\CategoryIndex;
use App\Storefront\ReadModels\ListingQuery;
use App\Storefront\ReadModels\ListingScope;
use App\Storefront\ReadModels\ListingSort;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Persistence\Eloquent\VariationModel;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P6c: «Скрий продуктите без наличност». The stored `catalog_products.is_available` (a SHOWN variation with stock > 0, kept by ProductSortPriceRefresher in the same pass as `price_from_minor`) is the ONE condition every
 * LIST adds while the setting is ON (the default): listings, home grids, carousels, «Подобни продукти», and every number derived from them. The product page and direct lookups never use it.
 *
 * Fixture: category 1 «Clothing» (+ category 2 «Shoes» with only a sold-out product), brand 1 (+ brand 2 only on the sold-out product), tag 1 (+ tag 2 only on the sold-out product).
 *   p1 simple, stock 3 · p2 simple, SOLD OUT · p3 variable, sizes: 0 and 1 (ONE size left) · p4 variable, every size 0 (sold out)
 */
class SoldOutHidingTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.public.url' => 'https://cdn.test/storage']);
        $this->withHeader('Referer', 'http://localhost/');
        $this->seedPricing();
        $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeCategory(2, 'Shoes', 'shoes');
        $this->makeBrand(1, 'Alpha', 'alpha');
        $this->makeBrand(2, 'Beta', 'beta');
        $this->makeTag(1, 'New', 'new');
        $this->makeTag(2, 'Old', 'old');
        $this->makeAttribute(1, 'Size', ['S', 'M']);

        $this->makeProduct(1, ['slug' => 'in-stock', 'price' => '10.00', 'stock' => 3, 'categories' => [1], 'brand' => 1, 'tags' => [1], 'featured' => true]);
        $this->makeProduct(2, ['slug' => 'sold-out', 'price' => '20.00', 'stock' => 0, 'categories' => [1, 2], 'brand' => 2, 'tags' => [2], 'featured' => true]);
        $this->makeProduct(3, ['slug' => 'one-size', 'type' => 'variable', 'categories' => [1], 'brand' => 1, 'tags' => [1], 'featured' => true, 'variations' => [
            ['attrs' => [1 => 'S'], 'price' => '30.00', 'stock' => 0], ['attrs' => [1 => 'M'], 'price' => '30.00', 'stock' => 1],
        ]]);
        $this->makeProduct(4, ['slug' => 'all-sizes-out', 'type' => 'variable', 'categories' => [1], 'brand' => 1, 'tags' => [1], 'featured' => true, 'variations' => [
            ['attrs' => [1 => 'S'], 'price' => '40.00', 'stock' => 0], ['attrs' => [1 => 'M'], 'price' => '40.00', 'stock' => 0],
        ]]);
        $this->refreshAll();
    }

    private function refreshAll(): void
    {
        app(ProductSortPriceRefresher::class)->refresh(DB::table('catalog_products')->pluck('id')->all());
    }

    /** ON / OFF exactly as the admin page does it: write the setting, replace the cached appearance payload. */
    private function hide(bool $on): void
    {
        app(SiteSettingsRepository::class)->set('catalog.hide_sold_out', $on ? '1' : '0');
        // (no payload to replace: the list queries read the setting row themselves)
    }

    private function reader(): CatalogReader
    {
        $this->app->forgetScopedInstances();

        return app(CatalogReader::class);
    }

    /** @return list<string> */
    private function slugs(array $cards): array
    {
        $slugs = array_map(fn ($card) => $card->slug, $cards);
        sort($slugs);

        return $slugs;
    }

    private function listing(ListingScope $scope = ListingScope::ALL, ?int $id = null, array $filters = [])
    {
        return $this->reader()->listing(ListingQuery::create($scope, $id, ListingSort::NEWEST), $filters);
    }

    private function available(): array
    {
        return DB::table('catalog_products')->orderBy('id')->pluck('is_available', 'id')->map(fn ($v) => (bool) $v)->all();
    }

    // ---- the stored fact -----------------------------------------------------------------------------------------

    /** ★ is_available: true when ONE shown variation has stock; a variable product with a single size left is available */
    public function test_the_refresher_stores_the_fact(): void
    {
        $this->assertSame([1 => true, 2 => false, 3 => true, 4 => false], $this->available());
    }

    /** ★ the migration default is TRUE: a product is never hidden before the first refresh */
    public function test_a_new_product_is_not_hidden_before_the_first_refresh(): void
    {
        $this->makeProduct(5, ['slug' => 'brand-new', 'stock' => 0, 'categories' => [1]]); // no price row: nothing queues a refresh

        $this->assertTrue((bool) DB::table('catalog_products')->where('id', 5)->value('is_available'));
        $this->assertContains('brand-new', $this->slugs($this->listing()->items));
    }

    // ---- ON: every list and every number -------------------------------------------------------------------------------

    /** ★ setting ON: the sold-out products are absent from every list; the single-size product stays */
    public function test_on_hides_sold_out_products_from_every_list(): void
    {
        $this->hide(true);
        $shown = ['in-stock', 'one-size'];

        $this->assertSame($shown, $this->slugs($this->listing()->items), 'the «all» listing');
        $this->assertSame($shown, $this->slugs($this->listing(ListingScope::CATEGORY, 1)->items), 'a category listing');
        $this->assertSame($shown, $this->slugs($this->listing(ListingScope::BRAND, 1)->items), 'a brand listing');
        $this->assertSame($shown, $this->slugs($this->listing(ListingScope::TAG, 1)->items), 'a tag listing');
        $this->assertSame([], $this->listing(ListingScope::BRAND, 2)->items, 'a brand with only a sold-out product');

        foreach (['newest' => null, 'featured' => null, 'category' => 1, 'brand' => 1, 'tag' => 1] as $kind => $entity) {
            $this->assertSame($shown, $this->slugs($this->reader()->homeProducts($kind, $entity, 'newest', 12, '100vw')), "home grid / carousel: {$kind}");
        }

        $this->assertSame(['one-size'], $this->slugs($this->reader()->homeProducts('similar', null, 'newest', 12, '100vw', null, 1)), '«Подобни продукти» of the in-stock product');
    }

    /** ★ setting ON: the listing total, the pages, the category counts and the filter facets never count a hidden product */
    public function test_on_the_numbers_never_count_a_hidden_product(): void
    {
        $this->hide(true);

        $page = $this->listing();
        $this->assertSame(2, $page->total);
        $this->assertSame(1, $page->lastPage);

        for ($id = 10; $id < 22; $id++) {
            $this->makeProduct($id, ['slug' => "extra-{$id}", 'price' => '5.00', 'stock' => 1, 'categories' => [1]]);
        }

        $first = $this->reader()->listing(ListingQuery::fromRequestArray(['per_page' => '12', 'page' => '1']));
        $second = $this->reader()->listing(ListingQuery::fromRequestArray(['per_page' => '12', 'page' => '2']));
        $this->assertSame([14, 2, 12, 2], [$first->total, $first->lastPage, count($first->items), count($second->items)], 'pagination: 14 listed products (the 2 hidden ones are not counted), 12 per page');
        $this->assertSame(14, $this->reader()->category('clothing')->productCount);

        $this->assertSame(14, $this->app->make(CategoryIndex::class)->productCount(1), 'category «Clothing»');
        $this->assertSame(0, $this->app->make(CategoryIndex::class)->productCount(2), 'category «Shoes» holds only a sold-out product');

        $this->assertSame([1], $this->reader()->filterValueIds(ListingQuery::create(), [], 'tag'), 'the tag facet offers only tag 1');
        $this->assertSame([1], $this->reader()->filterValueIds(ListingQuery::create(), [], 'brand'), 'the brand facet offers only brand 1');
        $this->assertSame([['name' => 'Clothing', 'url' => '/product-category/clothing']], $this->reader()->cloud(ListingScope::BRAND, 1), 'the cloud links only categories with listed products');
    }

    /** a category that holds only sold-out products stays reachable (its structure is unchanged) and lists nothing */
    public function test_a_category_with_only_sold_out_products_stays_shown_with_a_count_of_zero(): void
    {
        $this->hide(true);

        $this->assertNotNull($this->reader()->category('shoes'));
        $this->assertSame(0, $this->reader()->category('shoes')->productCount);
        $this->assertSame([], $this->listing(ListingScope::CATEGORY, 2)->items);
    }

    // ---- OFF ------------------------------------------------------------------------------------------------------------

    /** ★ setting OFF: everything is shown as before, the sold-out card keeps its P6a fallback price, the numbers count everything */
    public function test_off_shows_everything(): void
    {
        $this->hide(false);
        $all = ['all-sizes-out', 'in-stock', 'one-size', 'sold-out'];

        $this->assertSame($all, $this->slugs($this->listing()->items));
        $this->assertSame($all, $this->slugs($this->listing(ListingScope::CATEGORY, 1)->items));
        $this->assertSame(4, $this->listing()->total);
        $this->assertSame(4, $this->app->make(CategoryIndex::class)->productCount(1) ?: $this->reader()->category('clothing')->productCount);
        $this->assertSame([1, 2], $this->reader()->filterValueIds(ListingQuery::create(), [], 'tag'));
        $this->assertSame($all, $this->slugs($this->reader()->homeProducts('newest', null, 'newest', 12, '100vw')));

        $soldOut = collect($this->listing()->items)->firstWhere('slug', 'sold-out');
        $this->assertFalse($soldOut->inStock);
        $this->assertSame(2000, $soldOut->price?->fromMinor, 'the lowest price of a sold-out card (P6a)');
    }

    // ---- automatic and fast ---------------------------------------------------------------------------------------------

    /** ★ stock crossing zero through the stock repository hides the product (after the queue ran) and restocking brings it back */
    public function test_stock_crossing_zero_hides_and_restores_the_product(): void
    {
        $this->hide(true);
        $stock = app(StockLevelRepository::class);

        $stock->decrease('101', 2);
        $this->assertContains('in-stock', $this->slugs($this->listing()->items), 'still 1 left');

        $stock->decrease('101', 1);
        $this->assertNotContains('in-stock', $this->slugs($this->listing()->items), 'stock 0: hidden');
        $this->assertFalse($this->available()[1]);

        $stock->increase('101', 4);
        $this->assertContains('in-stock', $this->slugs($this->listing()->items), 'restocked from zero: back');
        $this->assertTrue($this->available()[1]);
    }

    /** ★ a variation added with stock, the only stocked variation deactivated, and a product save behave the same */
    public function test_variation_and_product_changes_update_the_fact(): void
    {
        $this->hide(true);

        // the only stocked size of p3 is deactivated → the product leaves the listing; activated → returns
        VariationModel::query()->whereKey(302)->first()->update(['status' => 'inactive']);
        $this->assertNotContains('one-size', $this->slugs($this->listing()->items));
        VariationModel::query()->whereKey(302)->first()->update(['status' => 'active']);
        $this->assertContains('one-size', $this->slugs($this->listing()->items));

        // a variation WITH stock is added to the sold-out p2 → it returns
        $variation = VariationModel::query()->forceCreate([
            'id' => 299, 'product_id' => 2, 'sort_order' => 5, 'type' => 'universal', 'status' => 'active', 'attribute_signature' => hash('sha256', 'extra'),
            'sku' => 'SKU-EXTRA', 'is_visible' => false, 'is_purchasable' => true,
        ]);
        $this->assertNotContains('sold-out', $this->slugs($this->listing()->items), 'a stock-less variation changes nothing');
        DB::table('stock_levels')->insert(['variation_id' => 299, 'quantity' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $variation->update(['sku' => 'SKU-EXTRA-2']);
        $this->assertTrue($this->available()[2], 'the variation save refreshed the product');

        // a product save repairs a wrong stored fact
        DB::table('catalog_products')->where('id', 1)->update(['is_available' => 0]);
        ProductModel::query()->whereKey(1)->first()->update(['name' => 'In stock, renamed']);
        $this->assertTrue($this->available()[1]);
    }

    // ---- the product page --------------------------------------------------------------------------------------------

    /** ★ the direct link of a hidden product answers 200 with «Изчерпан» and no enabled buy button — with the setting ON and OFF */
    public function test_the_product_page_of_a_sold_out_product_stays_reachable(): void
    {
        foreach ([true, false] as $on) {
            $this->hide($on);
            $html = $this->get('/product/sold-out')->assertOk()->getContent();

            $this->assertStringContainsString('sf-stock sf-stock-out', $html, 'setting '.($on ? 'ON' : 'OFF'));
            $this->assertStringContainsString(__('storefront.out_of_stock'), $html);
            $this->assertMatchesRegularExpression('/<button class="sf-add-to-cart sf-buy" type="button" disabled /', $html, 'the only buy button is disabled');
            $this->assertStringNotContainsString('noindex', $html);
        }
    }

    // ---- the nightly pass ------------------------------------------------------------------------------------------------

    /** ★ prices:refresh-from repairs a wrong stored fact and writes only what changed */
    public function test_the_nightly_pass_repairs_and_writes_only_changed_rows(): void
    {
        DB::table('catalog_products')->where('id', 1)->update(['is_available' => 0]);
        DB::table('catalog_products')->where('id', 2)->update(['is_available' => 1]);

        Artisan::call('prices:refresh-from');
        $this->assertStringContainsString('2 rewritten', Artisan::output());
        $this->assertSame([1 => true, 2 => false, 3 => true, 4 => false], $this->available());

        Artisan::call('prices:refresh-from');
        $this->assertStringContainsString('0 rewritten', Artisan::output(), 'a second run writes nothing');
    }

    /** ★ a chunk costs the same queries for 1 and for 200 products, with both columns written by ONE statement */
    public function test_a_chunk_costs_the_same_queries_for_one_and_for_many_products(): void
    {
        for ($id = 100; $id < 300; $id++) {
            $this->makeProduct($id, ['slug' => "bulk-{$id}", 'price' => '9.00', 'stock' => $id % 2, 'categories' => [1]]);
        }

        $count = function (array $ids): int {
            $n = 0;
            DB::listen(function () use (&$n): void {
                $n++;
            });
            app(ProductSortPriceRefresher::class)->refresh($ids);

            return $n;
        };

        $many = range(100, 299);
        $this->assertCount(200, $many);
        $count([100]); // warm-up (the resolvers' first reads)
        DB::table('catalog_products')->where('id', 100)->update(['is_available' => 1]);

        // 200 products, half of them sold out (the default said «available»): a chunk with 100 changes
        $manyQueries = $count($many);
        $this->assertSame(1, (int) DB::table('catalog_products')->where('id', 101)->value('is_available'));
        $this->assertSame(0, (int) DB::table('catalog_products')->where('id', 100)->value('is_available'));

        // ONE product with a wrong stored fact: a chunk with 1 change
        DB::table('catalog_products')->where('id', 100)->update(['is_available' => 1]);
        $oneQueries = $count([100]);

        $this->assertSame($oneQueries, $manyQueries, 'a constant number of queries per chunk');
        $this->assertSame(0, (int) DB::table('catalog_products')->where('id', 100)->value('is_available'));
    }

    // ---- no query, no disturbance ------------------------------------------------------------------------------------------

    /** ★ the condition adds NO query: a listing costs the same with the setting ON and OFF */
    public function test_the_condition_adds_no_query(): void
    {
        $count = function (bool $on): int {
            $this->hide($on);
            $this->listing(); // warm-up
            $n = 0;
            DB::listen(function () use (&$n): void {
                $n++;
            });
            $this->listing(ListingScope::CATEGORY, 1);

            return $n;
        };

        $on = $count(true);
        $off = $count(false);

        fwrite(STDERR, "\n[query-count] category listing: setting ON {$on}, OFF {$off}\n");
        $this->assertSame($off, $on);
    }

    /** ★ the two stored columns do not disturb each other: the price sort still orders by price_from_minor, and a stock change keeps a right price */
    public function test_the_price_sort_and_the_availability_do_not_disturb_each_other(): void
    {
        $this->hide(true);
        $order = fn () => array_map(fn ($c) => $c->slug, $this->reader()->listing(ListingQuery::create(ListingScope::ALL, null, ListingSort::PRICE_DESC))->items);

        $this->assertSame(['one-size', 'in-stock'], $order());
        app(StockLevelRepository::class)->decrease('101', 3);

        $this->assertSame(['one-size'], $order());
        $this->assertSame(1000, (int) DB::table('catalog_products')->where('id', 1)->value('price_from_minor'), 'the sold-out product keeps its fallback price');
        $this->assertFalse($this->available()[1]);
    }

    // ---- the setting page ------------------------------------------------------------------------------------------------

    /** ★ the page: ON by default; saving OFF stores `0`, replaces the cached payload and shows everything at once */
    public function test_the_settings_page_switches_the_storefront_at_once(): void
    {
        $this->actingAs(\App\Filament\StaffPanelUser::find($this->staff()), 'staff');

        Livewire::test(CatalogSettings::class)->assertSchemaStateSet(['hide_sold_out' => true]);
        $this->assertSame(2, $this->listing()->total);

        Livewire::test(CatalogSettings::class)->fillForm(['hide_sold_out' => false])->call('save')->assertHasNoFormErrors();
        $this->assertSame('0', app(SiteSettingsRepository::class)->get('catalog.hide_sold_out'));
        $this->assertSame(4, $this->listing()->total);

        Livewire::test(CatalogSettings::class)->fillForm(['hide_sold_out' => true])->call('save');
        $this->assertSame(2, $this->listing()->total);
    }

    private function staff(): int
    {
        $roles = app(\EasyCo\Staff\Contracts\RoleRepository::class);
        app(\EasyCo\Staff\Seeders\StaffSystemRolesSeeder::class)->run($roles);
        $role = $roles->findSystemRoleByName('Administrator');
        $staff = \EasyCo\Staff\Staff::create('admin@example.com', app(\EasyCo\Staff\Contracts\PasswordHasher::class)->hash('password123'), 'Administrator', $role);
        app(\EasyCo\Staff\Contracts\StaffRepository::class)->save($staff);

        return (int) $staff->id();
    }
}
