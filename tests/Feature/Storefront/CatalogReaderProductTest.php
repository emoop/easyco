<?php

namespace Tests\Feature\Storefront;

use App\Storefront\Reader\CatalogReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogReaderProductTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.public.url' => 'https://cdn.test/storage']);
        $this->seedPricing();
    }

    private function reader(): CatalogReader
    {
        $this->app->forgetScopedInstances();

        return app(CatalogReader::class);
    }

    public function test_a_simple_product_page_has_its_universal_variation_price_stock_and_gallery(): void
    {
        $brand = $this->makeBrand(1, 'Acme', 'acme');
        $dresses = $this->makeCategory(10, 'Dresses', 'dresses');
        $this->makeProduct(100, ['name' => 'Summer dress', 'slug' => 'summer-dress', 'brand' => $brand, 'categories' => [$dresses], 'price' => '49.90', 'stock' => 3, 'description' => '<p>Soft</p>']);
        $this->makeImage(1, 100, 0, alt: 'Front view');
        $this->makeImage(2, 100, 1);

        $page = $this->reader()->product('summer-dress');

        $this->assertNotNull($page);
        $this->assertSame('/product/summer-dress', $page->url);
        $this->assertSame(['name' => 'Acme', 'slug' => 'acme'], $page->brand);
        $this->assertSame(4990, $page->price->fromMinor);
        $this->assertSame(4990, $page->price->toMinor);
        $this->assertNull($page->price->regularFromMinor);
        $this->assertTrue($page->inStock);
        $this->assertSame([], $page->options);
        $this->assertCount(1, $page->variations);
        $this->assertSame('10001', $page->variations[0]->id);
        $this->assertSame([], $page->variations[0]->attributes);
        $this->assertCount(2, $page->images);
        $this->assertSame('Front view', $page->images[0]->alt);
        $this->assertSame('Summer dress — 2', $page->images[1]->alt);
        $this->assertSame(['/product-category/dresses', '/product/summer-dress'], array_map(fn ($b) => $b->url, $page->breadcrumbs));
    }

    public function test_a_variable_product_lists_its_shown_variations_with_options_prices_and_stock(): void
    {
        $this->makeAttribute(1, 'Size', ['S', 'M', 'L']);
        $this->makeProduct(200, ['type' => 'variable', 'name' => 'Shirt', 'slug' => 'shirt', 'variations' => [
            ['attrs' => [1 => 'M'], 'price' => '30.00', 'stock' => 2],
            ['attrs' => [1 => 'S'], 'price' => '25.00', 'stock' => 0],
            ['attrs' => [1 => 'L'], 'price' => '35.00', 'stock' => 5, 'visible' => false],   // is_visible = 0: not shown
            ['attrs' => [1 => 'L'], 'price' => '35.00', 'status' => 'draft'],                  // draft: not shown
        ]]);

        $page = $this->reader()->product('shirt');

        $this->assertSame('variable', $page->type);
        $this->assertSame(['M', 'S'], array_map(fn ($v) => $v->label, $page->variations), 'in sort order, only the shown ones');
        $this->assertSame([['name' => 'Size', 'values' => ['S', 'M']]], $page->options, 'values in the attribute value order');
        $this->assertSame([true, false], array_map(fn ($v) => $v->inStock, $page->variations));
        $this->assertSame([3000, 2500], array_map(fn ($v) => $v->price->fromMinor, $page->variations));
        $this->assertSame([2500, 3000], [$page->price->fromMinor, $page->price->toMinor], 'exact range on a product page');
        $this->assertTrue($page->inStock, 'one shown size has stock');
        $this->assertSame([['name' => 'Size', 'value' => 'M']], $page->variations[0]->attributes);
    }

    public function test_a_discounted_product_carries_the_regular_price(): void
    {
        $this->makeProduct(300, ['slug' => 'on-sale', 'price' => '100.00', 'sale' => '80.00', 'stock' => 1]);

        $page = $this->reader()->product('on-sale');

        $this->assertSame(8000, $page->price->fromMinor);
        $this->assertSame(10000, $page->price->regularFromMinor);
        $this->assertSame(8000, $page->variations[0]->price->fromMinor);
        $this->assertSame(10000, $page->variations[0]->price->regularFromMinor);
    }

    public function test_an_out_of_stock_product_is_visible_and_says_so(): void
    {
        $this->makeProduct(400, ['slug' => 'gone', 'price' => '10.00', 'stock' => 0]);

        $page = $this->reader()->product('gone');

        $this->assertNotNull($page);
        $this->assertFalse($page->inStock);
        $this->assertFalse($page->variations[0]->inStock);
    }

    public function test_a_product_without_an_image_has_an_empty_gallery(): void
    {
        $this->makeProduct(500, ['slug' => 'plain', 'price' => '10.00']);

        $this->assertSame([], $this->reader()->product('plain')->images);
    }

    public function test_images_still_processing_failed_or_videos_are_not_in_the_gallery(): void
    {
        $this->makeProduct(600, ['slug' => 'mixed', 'name' => 'Mixed', 'price' => '10.00']);
        $this->makeImage(1, 600, 0, status: 'processing');
        $this->makeImage(2, 600, 1, status: 'failed');
        $this->makeImage(3, 600, 2, type: 'video');
        $this->makeImage(4, 600, 3);

        $images = $this->reader()->product('mixed')->images;

        $this->assertCount(1, $images);
        $this->assertSame('Mixed', $images[0]->alt, 'the first USABLE image is the main one');
        $this->assertStringContainsString('p/4-medium.webp', $images[0]->src);
    }

    public function test_a_product_with_an_unpriced_variation_has_no_price_for_it(): void
    {
        $this->makeProduct(700, ['slug' => 'unpriced', 'stock' => 1]);

        $page = $this->reader()->product('unpriced');

        $this->assertNull($page->price);
        $this->assertNull($page->variations[0]->price);
    }

    public function test_a_hidden_draft_archived_or_deleted_product_is_not_found(): void
    {
        $this->makeProduct(801, ['slug' => 'hidden', 'visibility' => 'hidden']);
        $this->makeProduct(802, ['slug' => 'draft', 'status' => 'draft']);
        $this->makeProduct(803, ['slug' => 'archived', 'status' => 'archived']);
        $this->makeProduct(804, ['slug' => 'deleted', 'deleted' => true]);

        foreach (['hidden', 'draft', 'archived', 'deleted', 'never-existed'] as $slug) {
            $this->assertNull($this->reader()->product($slug), $slug);
        }
    }

    public function test_the_breadcrumb_follows_the_deepest_shown_category(): void
    {
        $root = $this->makeCategory(1, 'Clothing', 'clothing');
        $child = $this->makeCategory(2, 'Dresses', 'dresses', $root);
        $this->makeProduct(900, ['slug' => 'deep', 'name' => 'Deep', 'categories' => [$root, $child], 'price' => '10.00']);

        $crumbs = $this->reader()->product('deep')->breadcrumbs;

        $this->assertSame(['Clothing', 'Dresses', 'Deep'], array_map(fn ($b) => $b->label, $crumbs));
        $this->assertSame(['/product-category/clothing', '/product-category/clothing/dresses', '/product/deep'], array_map(fn ($b) => $b->url, $crumbs));
    }

    public function test_a_slug_is_matched_by_the_database_collation_and_the_page_carries_the_stored_slug(): void
    {
        $this->makeProduct(950, ['slug' => 'summer-dress', 'price' => '10.00']);

        $page = $this->reader()->product('Summer-Dress');

        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->assertNull($page, 'SQLite compares case-sensitively');

            return;
        }

        $this->assertNotNull($page, 'MySQL utf8mb4_unicode_ci compares case-insensitively');
        $this->assertSame('summer-dress', $page->slug, 'the stored slug, so a caller can redirect to the canonical url');
        $this->assertSame('/product/summer-dress', $page->url);
    }
}
