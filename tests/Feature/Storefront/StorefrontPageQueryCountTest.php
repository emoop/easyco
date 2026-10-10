<?php

namespace Tests\Feature\Storefront;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Query counts of the three PAGES (the reader's ceilings of StorefrontQueryCountTest plus the request's own reads: the
 * store locale, the store time zone and the currency-symbol position, each one `site_settings` read), measured over a
 * real request with 5 and with 24 products, and identical for both.
 */
class StorefrontPageQueryCountTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    /** Pinned CEILINGS: the measured counts (see the printed lines); they must never grow. */
    public const HOME = 24;

    public const CATEGORY = 24;

    public const PRODUCT = 25;

    protected function setUp(): void
    {
        parent::setUp();

        // A Livewire test earlier in the same PHP process leaves this static flag set; a real request starts with it false
        // (and a storefront page renders no Livewire component), so it must not inject Livewire's <script>/<style> here.
        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;

        config(['filesystems.disks.public.url' => 'https://cdn.test/storage']);
        $this->seedPricing();
        $this->makeAttribute(1, 'Size', ['S', 'M', 'L']);
        $categories = [$this->makeCategory(1, 'Clothing', 'clothing'), $this->makeCategory(2, 'Dresses', 'dresses', 1)];

        for ($i = 1; $i <= 24; $i++) {
            $variable = $i % 3 === 0;
            $this->makeProduct($i, [
                'type' => $variable ? 'variable' : 'simple', 'slug' => "product-{$i}", 'brand' => $this->makeBrand($i, "Brand {$i}", "brand-{$i}"),
                'categories' => $categories, 'timeline' => sprintf('2026-01-%02d 10:00:00', $i), 'price' => $variable ? null : '10.00',
                'sale' => $i % 4 === 0 && ! $variable ? '8.00' : null, 'stock' => $i % 5 === 0 ? 0 : 3,
                'variations' => $variable ? [['attrs' => [1 => 'S'], 'price' => '10.00', 'stock' => 1], ['attrs' => [1 => 'M'], 'price' => '12.00']] : null,
            ]);
            $this->makeImage($i, $i);
        }
    }

    /** @return list<string> */
    private function queriesFor(string $url): array
    {
        // A fresh "request": scoped instances and the controllers cached on the routes are dropped.
        $this->app->forgetScopedInstances();

        foreach (Route::getRoutes() as $route) {
            $route->flushController();
        }

        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        $this->get($url)->assertOk();

        return $sql;
    }

    public function test_the_home_and_category_pages_cost_the_same_for_5_and_24_products(): void
    {
        $results = [];

        foreach (['/' => self::HOME, '/product-category/clothing' => self::CATEGORY] as $url => $ceiling) {
            DB::table('catalog_products')->update(['catalog_visibility' => 'visible']);
            $with24 = count($this->queriesFor($url));

            DB::table('catalog_products')->where('id', '<=', 19)->update(['catalog_visibility' => 'hidden']);
            $with5 = count($this->queriesFor($url));

            fwrite(STDERR, "\n[query-count] storefront page {$url}: 24 products = {$with24} queries, 5 products = {$with5} queries (pinned ceiling {$ceiling})\n");

            $this->assertSame($with24, $with5, "{$url}: the page query count must not grow with the number of products");
            $this->assertLessThanOrEqual($ceiling, $with24, $url);
            $results[$url] = $with24;
        }
    }

    public function test_the_product_page_costs_the_same_with_few_and_many_variations_and_images(): void
    {
        $few = count($this->queriesFor('/product/product-3'));
        $simple = count($this->queriesFor('/product/product-1'));

        foreach (range(1, 6) as $n) {
            $this->makeImage(500 + $n, 3, $n);
        }

        foreach (['L', 'S', 'M'] as $n => $size) {
            DB::table('catalog_variations')->insert([
                'id' => 9000 + $n, 'product_id' => 3, 'sort_order' => 5 + $n, 'type' => 'standard', 'status' => 'active', 'attribute_signature' => hash('sha256', "extra-{$n}"),
                'sku' => "EXTRA-{$n}", 'is_visible' => true, 'is_purchasable' => true, 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-01 10:00:00',
            ]);
            DB::table('catalog_variation_attribute_values')->insert([
                'variation_id' => 9000 + $n, 'attribute_definition_id' => 1, 'attribute_value_id' => 1001 + $n, 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-01 10:00:00',
            ]);
        }

        $many = count($this->queriesFor('/product/product-3'));

        fwrite(STDERR, "
[query-count] storefront page /product: variable product 1 image = {$few} queries, with 7 images and 5 variations = {$many} queries; simple product = {$simple} (pinned ceiling ".self::PRODUCT.")
");

        $this->assertSame($few, $many, 'the product page query count must not grow with variations or images');
        $this->assertLessThanOrEqual(self::PRODUCT, max($few, $simple));
    }

    public function test_the_menu_costs_no_extra_query_once_the_category_index_is_loaded(): void
    {
        foreach (['/product/product-1', '/product-category/clothing/dresses'] as $url) {
            $sql = $this->queriesFor($url);

            $structure = array_filter($sql, fn (string $q) => str_contains($q, 'from `catalog_categories`') && ! str_contains($q, 'catalog_product_categories'));
            $pairs = array_filter($sql, fn (string $q) => str_contains($q, 'from `catalog_product_categories` inner join `catalog_products`'));

            $this->assertCount(1, $structure, "{$url}: the category structure is read once (breadcrumbs, listing and menu share it)");
            $this->assertCount(1, $pairs, "{$url}: the visible-product pairs are read once");
        }
    }
}
