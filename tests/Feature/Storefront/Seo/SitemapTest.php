<?php

namespace Tests\Feature\Storefront\Seo;

use App\Content\Models\PageModel;
use App\Http\Controllers\Storefront\PageController;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/** SEO-1 §2: the sitemap index and its three kinds of child file. */
class SitemapTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    private const SM = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        config(['app.url' => 'https://shop.test', 'app.name' => 'Raf Shop', 'easyco.noindex' => false, 'storefront.sitemap.chunk' => 2]);
        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();
        $this->makeCategory(1, 'Clothing', 'clothing');
    }

    private function product(int $id, array $o = []): int
    {
        return $this->makeProduct($id, $o + ['name' => "Product {$id}", 'slug' => "p-{$id}", 'categories' => [1], 'price' => '10.00', 'stock' => 3]);
    }

    private function page(string $slug, array $o = []): PageModel
    {
        return PageModel::query()->forceCreate($o + ['slug' => $slug, 'title' => "Title {$slug}", 'body' => '<p>x</p>', 'status' => 'published', 'revision' => 1, 'noindex' => false, 'published_at' => now()]);
    }

    /** @return array{0: DOMDocument, 1: DOMXPath} */
    private function xml(string $url, bool $image = false): array
    {
        $response = $this->get($url);
        $response->assertOk();
        $this->assertSame('application/xml; charset=UTF-8', $response->headers->get('Content-Type'));

        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($response->getContent()), 'well-formed XML');
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('s', self::SM);
        $xp->registerNamespace('i', 'http://www.google.com/schemas/sitemap-image/1.1');

        return [$dom, $xp];
    }

    /** @return list<string> the <loc> of the <url> entries */
    private function locs(string $url): array
    {
        [, $xp] = $this->xml($url);

        return array_map(static fn ($node): string => $node->textContent, iterator_to_array($xp->query('//s:url/s:loc')));
    }

    // ---- the index ---------------------------------------------------------------------------------------------------------------------

    public function test_the_index_lists_the_children_and_the_product_chunks(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->product($i);
        }

        $this->page('terms');
        [$dom, $xp] = $this->xml('/sitemap.xml');

        $this->assertSame('sitemapindex', $dom->documentElement->localName);
        $this->assertSame(self::SM, $dom->documentElement->namespaceURI);
        $locs = array_map(static fn ($n): string => $n->textContent, iterator_to_array($xp->query('//s:sitemap/s:loc')));
        $this->assertSame([
            'https://shop.test/sitemap/pages.xml',
            'https://shop.test/sitemap/categories.xml',
            'https://shop.test/sitemap/products-1.xml',
            'https://shop.test/sitemap/products-2.xml',
            'https://shop.test/sitemap/products-3.xml',
        ], $locs, '5 products in chunks of 2');
        $this->assertSame(2, $xp->query('//s:sitemap/s:lastmod')->length, 'lastmod for pages and categories, none for the product chunks');
    }

    public function test_chunks_are_by_id_and_a_chunk_past_the_end_is_a_404(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->product($i);
        }

        $this->assertSame(['https://shop.test/product/p-1', 'https://shop.test/product/p-2'], $this->locs('/sitemap/products-1.xml'));
        $this->assertSame(['https://shop.test/product/p-5'], $this->locs('/sitemap/products-3.xml'));
        $this->get('/sitemap/products-4.xml')->assertNotFound();
        $this->get('/sitemap/products-0.xml')->assertNotFound();
        $this->get('/sitemap/products-abc.xml')->assertNotFound();
    }

    // ---- what is listed -----------------------------------------------------------------------------------------------------------------

    public function test_a_product_its_own_page_would_hide_is_never_listed(): void
    {
        config(['storefront.sitemap.chunk' => 50]);
        $this->product(1);
        $this->product(2, ['status' => 'draft']);
        $this->product(3, ['visibility' => 'hidden']);
        $this->product(4, ['deleted' => true]);

        $this->assertSame(['https://shop.test/product/p-1'], $this->locs('/sitemap/products-1.xml'));
        $this->get('/product/p-2')->assertNotFound();
        $this->get('/product/p-3')->assertNotFound();
    }

    public function test_a_sold_out_product_whose_page_still_exists_stays_listed(): void
    {
        config(['storefront.sitemap.chunk' => 50]);
        $this->product(1, ['stock' => 0]);

        $this->assertSame(['https://shop.test/product/p-1'], $this->locs('/sitemap/products-1.xml'));
        $this->get('/product/p-1')->assertOk();
    }

    public function test_categories_are_the_ones_the_storefront_shows_and_nothing_filtered_or_paged(): void
    {
        $this->makeCategory(2, 'Dresses', 'dresses', 1);
        $this->makeCategory(3, 'Empty', 'empty');           // no product: the category page is a 404
        $this->makeCategory(4, 'Only hidden', 'only-hidden');
        $this->product(1, ['categories' => [2]]);
        $this->product(2, ['categories' => [4], 'visibility' => 'hidden']);

        $locs = $this->locs('/sitemap/categories.xml');

        $this->assertEqualsCanonicalizing(['https://shop.test/product-category/clothing', 'https://shop.test/product-category/clothing/dresses'], $locs);
        $this->get('/product-category/empty')->assertNotFound();
        $this->get('/product-category/only-hidden')->assertNotFound();

        foreach ($locs as $loc) {
            $this->assertStringNotContainsString('?', $loc);
        }
    }

    public function test_pages_list_the_home_page_and_published_indexable_content_pages_only(): void
    {
        $this->page('terms');
        $this->page('draft-page', ['status' => 'draft']);
        $this->page('hidden-page', ['noindex' => true]);
        $preview = $this->page('previewed', ['status' => 'draft']);

        $locs = $this->locs('/sitemap/pages.xml');

        $this->assertSame(['https://shop.test/', 'https://shop.test/info/terms'], $locs);
        $this->get('/info/draft-page')->assertNotFound();
        $this->assertNotContains('https://shop.test/info-preview/'.$preview->id, $locs, 'a preview is never listed');
        $this->assertSame(200, $this->get(PageController::previewUrl($preview))->getStatusCode());
    }

    // ---- the URLs and the XML ---------------------------------------------------------------------------------------------------------------

    public function test_a_cyrillic_slug_is_percent_encoded_and_the_value_is_xml_escaped(): void
    {
        config(['storefront.sitemap.chunk' => 50, 'app.url' => 'https://shop.test/?a=1&b=2']);
        $this->product(1, ['slug' => 'червена-рокля']);

        $loc = $this->locs('/sitemap/products-1.xml')[0];

        $this->assertStringContainsString('/product/%D1%87%D0%B5%D1%80%D0%B2%D0%B5%D0%BD%D0%B0-%D1%80%D0%BE%D0%BA%D0%BB%D1%8F', $loc);
        $this->assertStringContainsString('&b=2', $loc, 'the parsed value is the real one: the & was escaped in the XML');
        $this->assertStringContainsString('&amp;b=2', $this->get('/sitemap/products-1.xml')->getContent());
    }

    public function test_the_product_file_declares_the_image_namespace_and_lists_the_largest_picture_and_lastmod(): void
    {
        config(['storefront.sitemap.chunk' => 50]);
        $this->product(1);
        $this->makeImage(1, 1);
        $this->product(2);

        [$dom, $xp] = $this->xml('/sitemap/products-1.xml');

        $this->assertSame('http://www.google.com/schemas/sitemap-image/1.1', $dom->documentElement->lookupNamespaceURI('image'));
        $this->assertSame(1, $xp->query('//s:url/i:image/i:loc')->length, 'only the product with a picture has one');
        $this->assertSame('https://cdn.test/storage/p/1-large.webp', $xp->query('//s:url/i:image/i:loc')->item(0)->textContent, 'the largest stored size, absolute');
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', $xp->query('//s:url/s:lastmod')->item(0)->textContent);
        $this->assertSame(0, $xp->query('//s:url/s:changefreq | //s:url/s:priority')->length);
    }

    // ---- the switch, the cache, the cost -----------------------------------------------------------------------------------------------------

    public function test_with_the_noindex_switch_every_sitemap_url_is_a_404(): void
    {
        $this->product(1);
        config(['easyco.noindex' => true]);

        foreach (['/sitemap.xml', '/sitemap/pages.xml', '/sitemap/categories.xml', '/sitemap/products-1.xml'] as $url) {
            $this->get($url)->assertNotFound();
        }
    }

    public function test_every_file_is_cached_for_one_day(): void
    {
        config(['storefront.sitemap.chunk' => 50]);
        $this->product(1);
        // P8b: the setup's own writes collected a purge that a real request would have flushed already; start clean so the cached file is not versioned away
        app(\App\Storefront\Cache\CachePurgeCollector::class)->reset();
        $first = $this->get('/sitemap/products-1.xml')->getContent();

        DB::table('catalog_products')->where('id', 1)->update(['status' => 'draft']);
        $this->assertSame($first, $this->get('/sitemap/products-1.xml')->getContent(), 'served from the cache');

        Cache::flush();
        $this->get('/sitemap/products-1.xml')->assertNotFound();
        // P8b: 24 h (was 1 h): the sitemap caches follow the `sitemap` / `all` purges through a version key, so a change shows at once
        $this->assertSame(86400, \App\Storefront\Seo\Sitemap::TTL_SECONDS);
    }

    public function test_the_number_of_statements_does_not_grow_with_the_rows(): void
    {
        config(['storefront.sitemap.chunk' => 500]);

        for ($i = 1; $i <= 5; $i++) {
            $this->product($i);
            $this->makeImage($i, $i);
            $this->makeCategory(10 + $i, "Cat {$i}", "cat-{$i}");
            DB::table('catalog_product_categories')->insert(['product_id' => $i, 'category_id' => 10 + $i]);
            $this->page("page-{$i}");
        }

        $few = ['products' => $this->statements('/sitemap/products-1.xml'), 'categories' => $this->statements('/sitemap/categories.xml'), 'pages' => $this->statements('/sitemap/pages.xml'), 'index' => $this->statements('/sitemap.xml')];

        for ($i = 6; $i <= 50; $i++) {
            $this->product($i);
            $this->makeImage($i, $i);
            $this->makeCategory(10 + $i, "Cat {$i}", "cat-{$i}");
            DB::table('catalog_product_categories')->insert(['product_id' => $i, 'category_id' => 10 + $i]);
            $this->page("page-{$i}");
        }

        $many = ['products' => $this->statements('/sitemap/products-1.xml'), 'categories' => $this->statements('/sitemap/categories.xml'), 'pages' => $this->statements('/sitemap/pages.xml'), 'index' => $this->statements('/sitemap.xml')];

        fwrite(STDERR, "\n[query-count] sitemap files, 5 vs 50 products: ".json_encode($few).' vs '.json_encode($many)."\n");
        $this->assertSame($few, $many);
        $this->assertSame(4, $many['products'], 'the chunk page with its first picture and the assets of the chunk (2) + the two settings reads of the locale / timezone middleware (2)');
    }

    private function statements(string $url): int
    {
        Cache::flush();
        $this->app->forgetScopedInstances();
        $n = 0;
        DB::listen(function () use (&$n): void {
            $n++;
        });
        $this->get($url)->assertOk();

        return $n;
    }
}
