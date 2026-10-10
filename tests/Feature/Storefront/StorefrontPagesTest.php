<?php

namespace Tests\Feature\Storefront;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/** The three pages' content: key facts present, compact. */
class StorefrontPagesTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A Livewire test earlier in the same PHP process leaves this static flag set; a real request starts with it false
        // (and a storefront page renders no Livewire component), so it must not inject Livewire's <script>/<style> here.
        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;

        config(['filesystems.disks.public.url' => 'https://cdn.test/storage', 'app.url' => 'https://shop.test', 'app.name' => 'Raf Shop']);
        $this->seedPricing();
        $this->world();
    }

    private function world(): void
    {
        $acme = $this->makeBrand(1, 'Acme', 'acme');
        $clothing = $this->makeCategory(1, 'Clothing', 'clothing');
        $dresses = $this->makeCategory(2, 'Dresses', 'dresses', $clothing);
        $this->makeAttribute(1, 'Size', ['S', 'M']);

        $this->makeProduct(1, ['name' => 'Summer dress', 'slug' => 'summer-dress', 'brand' => $acme, 'categories' => [$dresses], 'price' => '100.00', 'sale' => '79.90', 'stock' => 3,
            'timeline' => '2026-03-01 10:00:00', 'short' => 'Light and airy', 'description' => '<p>Soft <strong>cotton</strong></p>']);
        $this->makeImage(1, 1, 0, alt: 'Front view');
        $this->makeImage(2, 1, 1);

        $this->makeProduct(2, ['type' => 'variable', 'name' => 'Linen shirt', 'slug' => 'linen-shirt', 'categories' => [$clothing], 'timeline' => '2026-02-01 10:00:00', 'variations' => [
            ['attrs' => [1 => 'S'], 'price' => '40.00', 'stock' => 0],
            ['attrs' => [1 => 'M'], 'price' => '45.00', 'stock' => 3],
        ]]);

        $this->makeProduct(3, ['name' => 'Sold-out scarf', 'slug' => 'sold-out-scarf', 'categories' => [$dresses], 'price' => '15.00', 'stock' => 0, 'timeline' => '2026-01-01 10:00:00']);
    }

    public function test_the_home_page_lists_the_newest_products_and_the_menu(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<html lang="'.str_replace('_', '-', app()->getLocale()).'">', $html);
        $this->assertStringContainsString('<title>Raf Shop</title>', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://shop.test/">', $html);
        $this->assertStringContainsString('href="/product-category/clothing/dresses"', $html, 'the menu');
        $this->assertSame(3, substr_count($html, 'class="sf-card"'));
        $this->assertLessThan(strpos($html, 'Linen shirt'), strpos($html, 'Summer dress'), 'newest first');
    }

    public function test_the_product_page_for_a_simple_product(): void
    {
        $html = $this->get('/product/summer-dress')->assertOk()->getContent();

        $this->assertStringContainsString('<title>Summer dress — Raf Shop</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="Light and airy">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://shop.test/product/summer-dress">', $html);
        $this->assertStringContainsString('<h1 class="sf-title">Summer dress</h1>', $html);
        $this->assertStringContainsString('Acme', $html);
        $this->assertStringContainsString('<s class="sf-price-regular">100.00 €</s>', $html);
        $this->assertStringContainsString('79.90 €', $html);
        $this->assertStringContainsString('sf-stock-in', $html);
        $this->assertStringContainsString('<strong>cotton</strong>', $html, 'the sanitised description is the one raw block');
        $this->assertStringContainsString('<li class="sf-breadcrumb sf-breadcrumb-current" aria-current="page">Summer dress</li>', $html);
        $this->assertStringContainsString('href="/product-category/clothing/dresses"', $html);
        $this->assertStringNotContainsString('<select', $html, 'a simple product has no variation select');
        $this->assertMatchesRegularExpression('/<button class="sf-add-to-cart" type="button" disabled data-variation-id="101">/', $html);
    }

    public function test_the_gallery_images_carry_size_srcset_and_loading_hints(): void
    {
        $html = $this->get('/product/summer-dress')->assertOk()->getContent();

        $this->assertSame(4, substr_count($html, '<img '), 'each image appears twice: once as the main image, once as a thumbnail (2 images)');
        $this->assertMatchesRegularExpression('/<img class="sf-image"\s+src="https:\/\/cdn\.test\/storage\/p\/1-medium\.webp"\s+srcset="[^"]*1-thumbnail\.webp 400w, [^"]*1-medium\.webp 900w, [^"]*1-large\.webp 1600w"\s+sizes="[^"]+"\s+width="900"\s+height="675"\s+alt="Front view"\s+loading="eager"\s+fetchpriority="high"\s+decoding="async">/s', $html);
        $this->assertMatchesRegularExpression('/alt="Summer dress — 2"\s+loading="lazy"/s', $html);
        $this->assertSame(1, substr_count($html, 'fetchpriority="high"'));
    }

    public function test_a_variable_product_shows_a_select_of_variations_with_unavailable_ones_disabled(): void
    {
        $html = $this->get('/product/linen-shirt')->assertOk()->getContent();

        $this->assertStringContainsString('<select class="sf-variation-select" id="sf-variation" name="variation">', $html);
        $this->assertMatchesRegularExpression('/<option value="201" disabled>\s*S — 40\.00 € \(unavailable\)\s*<\/option>/', $html);
        $this->assertMatchesRegularExpression('/<option value="202" >\s*M — 45\.00 €\s*<\/option>/', $html);
        $this->assertStringContainsString('data-variation-id="202"', $html, 'the first purchasable in-stock variation');
        $this->assertStringContainsString('40.00 € – 45.00 €', $html, 'from-to');
    }

    public function test_a_sold_out_product_says_so(): void
    {
        $page = $this->get('/product/sold-out-scarf')->assertOk()->getContent();

        $this->assertStringContainsString('sf-stock-out', $page);
        $this->assertStringContainsString('Out of stock', $page);
        $this->assertStringContainsString('data-variation-id=""', $page);
    }

    public function test_the_category_page_lists_its_products_with_breadcrumbs_and_the_sold_out_label(): void
    {
        $html = $this->get('/product-category/clothing/dresses')->assertOk()->getContent();

        $this->assertStringContainsString('<title>Dresses — Raf Shop</title>', $html);
        $this->assertStringContainsString('<h1 class="sf-title">Dresses</h1>', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://shop.test/product-category/clothing/dresses">', $html);
        $this->assertSame(2, substr_count($html, 'class="sf-card"'));
        $this->assertStringContainsString('Sold-out scarf', $html);
        $this->assertSame(1, substr_count($html, 'sf-stock sf-stock-out'));
        $this->assertStringContainsString('<s class="sf-price-regular">100.00 €</s>', $html, 'a discounted card');
        $this->assertMatchesRegularExpression('/<a href="\/product-category\/clothing">Clothing<\/a>.*aria-current="page">Dresses/s', $html);
    }

    public function test_the_parent_category_includes_descendants_and_links_its_children(): void
    {
        $html = $this->get('/product-category/clothing')->assertOk()->getContent();

        $this->assertSame(3, substr_count($html, 'class="sf-card"'));
        $this->assertStringContainsString('<li class="sf-subcategory"><a href="/product-category/clothing/dresses">Dresses</a></li>', $html);
    }

    public function test_pagination_shows_prev_next_and_numbered_pages_with_only_whitelisted_keys(): void
    {
        $html = $this->get('/product-category/clothing?per_page=12&utm_source=x&price_min=5')->assertOk()->getContent();
        $this->assertStringNotContainsString('sf-pagination', $html, 'one page: no pagination');

        foreach (range(10, 40) as $id) {
            $this->makeProduct($id, ['categories' => [2], 'price' => '1.00', 'stock' => 1, 'timeline' => '2026-04-01 10:00:00']);
        }

        $second = $this->get('/product-category/clothing/dresses?page=2&per_page=12&sort=name_asc&utm_source=x&price_min=5')->assertOk()->getContent();

        $this->assertStringContainsString('Page 2 of 3', $second);
        $this->assertStringContainsString('rel="prev" href="/product-category/clothing/dresses?per_page=12&amp;sort=name_asc"', $second);
        $this->assertStringContainsString('rel="next" href="/product-category/clothing/dresses?page=3&amp;per_page=12&amp;sort=name_asc"', $second);
        $this->assertStringContainsString('<link rel="canonical" href="https://shop.test/product-category/clothing/dresses?page=2">', $second);
        $this->assertStringNotContainsString('utm_source', $second);
        $this->assertStringNotContainsString('price_min', $second);
    }

    public function test_a_page_past_the_last_is_a_404(): void
    {
        $this->get('/product-category/clothing/dresses?page=2')->assertNotFound();
        $this->get('/product-category/clothing/dresses?page=1')->assertOk();
    }

    public function test_an_empty_home_is_still_a_page(): void
    {
        \Illuminate\Support\Facades\DB::table('catalog_products')->update(['catalog_visibility' => 'hidden']);

        $this->get('/')->assertOk()->assertSee('There are no products here yet.');
    }
}
