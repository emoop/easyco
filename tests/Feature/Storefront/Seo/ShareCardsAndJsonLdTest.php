<?php

namespace Tests\Feature\Storefront\Seo;

use App\Content\Models\PageModel;
use App\Http\Controllers\Storefront\PageController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/** SEO-1 §3 and §4: the Open Graph / Twitter tags and the JSON-LD block. */
class ShareCardsAndJsonLdTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        config(['app.url' => 'https://shop.test', 'app.name' => 'Raf Shop', 'storefront.search.enabled' => false]);
        app()->setLocale('bg');
        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();
        $this->makeCategory(1, 'Clothing', 'clothing');
    }

    private function product(int $id, array $o = []): int
    {
        return $this->makeProduct($id, $o + ['name' => "Product {$id}", 'slug' => "p-{$id}", 'categories' => [1], 'price' => '10.00', 'stock' => 3]);
    }

    /** @return array<string, string> property|name => content of the og:/product:/twitter: tags */
    private function tags(string $html): array
    {
        preg_match_all('#<meta (?:property|name)="((?:og|product|twitter):[^"]+)" content="([^"]*)">#u', $html, $m);

        return array_combine($m[1], array_map(static fn (string $v): string => html_entity_decode($v, ENT_QUOTES | ENT_HTML5), $m[2])) ?: [];
    }

    /** @return list<array<string, mixed>> the decoded JSON-LD blocks */
    private function blocks(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

        return array_map(static fn (string $json): array => json_decode($json, true, 512, JSON_THROW_ON_ERROR), $m[1]);
    }

    /** @return array<string, mixed> the node of a given @type in the first graph */
    private function node(string $html, string $type): array
    {
        foreach ($this->blocks($html)[0]['@graph'] ?? [] as $node) {
            if ($node['@type'] === $type) {
                return $node;
            }
        }

        $this->fail("no {$type} node");
    }

    // ---- Open Graph and Twitter ---------------------------------------------------------------------------------------------------------

    public function test_a_product_with_a_picture_has_the_full_card(): void
    {
        $this->makeBrand(1, 'Zara', 'zara');
        $this->product(1, ['name' => 'Red dress "Summer" <b>', 'short' => 'Short <i>text</i> here', 'brand' => 1]);
        $this->makeImage(1, 1, 0, 'ready', 'Front view');

        $html = $this->get('/product/p-1')->assertOk()->getContent();
        $tags = $this->tags($html);

        $this->assertSame('product', $tags['og:type']);
        $this->assertSame('Red dress "Summer" <b>', $tags['og:title'], 'escaped in the HTML, the real text after parsing');
        $this->assertStringNotContainsString('<b>"', $html);
        $this->assertSame('Short text here', $tags['og:description']);
        $this->assertSame('https://shop.test/product/p-1', $tags['og:url']);
        $this->assertSame('Raf Shop', $tags['og:site_name']);
        $this->assertSame('bg_BG', $tags['og:locale']);
        $this->assertSame('https://cdn.test/storage/p/1-medium.webp', $tags['og:image'], 'the stored size closest to 1200 wide (900 vs 1600: 900 is 300 away, 1600 is 400)');
        $this->assertSame('900', $tags['og:image:width']);
        $this->assertSame('675', $tags['og:image:height']);
        $this->assertSame('Front view', $tags['og:image:alt']);
        $this->assertSame('10.00', $tags['product:price:amount']);
        $this->assertSame('EUR', $tags['product:price:currency']);
        $this->assertSame('summary_large_image', $tags['twitter:card']);
        $this->assertSame('https://cdn.test/storage/p/1-medium.webp', $tags['twitter:image']);
        $this->assertArrayNotHasKey('twitter:site', $tags);
    }

    public function test_the_closest_size_is_chosen_by_width(): void
    {
        $this->product(1);
        $this->makeImage(1, 1, 0, 'ready', null, [
            ['tier' => 'thumbnail', 'width' => 400, 'height' => 300, 'quality' => 80, 'path' => 'p/a-thumbnail.webp'],
            ['tier' => 'medium', 'width' => 1100, 'height' => 825, 'quality' => 82, 'path' => 'p/a-medium.webp'],
            ['tier' => 'large', 'width' => 2000, 'height' => 1500, 'quality' => 85, 'path' => 'p/a-large.webp'],
        ]);

        $tags = $this->tags($this->get('/product/p-1')->getContent());

        $this->assertSame('https://cdn.test/storage/p/a-medium.webp', $tags['og:image']);
        $this->assertSame('1100', $tags['og:image:width']);
        $this->assertSame('825', $tags['og:image:height']);
    }

    public function test_a_product_without_a_picture_has_a_plain_summary_card_and_no_image_tags(): void
    {
        $this->product(1);

        $tags = $this->tags($this->get('/product/p-1')->getContent());

        $this->assertSame('summary', $tags['twitter:card']);
        $this->assertSame('product', $tags['og:type']);
        foreach (['og:image', 'og:image:width', 'og:image:height', 'og:image:alt', 'twitter:image', 'og:description'] as $absent) {
            $this->assertArrayNotHasKey($absent, $tags);
        }
    }

    public function test_the_category_the_home_page_and_a_content_page_are_website_cards_without_an_image(): void
    {
        $this->product(1);
        PageModel::query()->forceCreate(['slug' => 'terms', 'title' => 'Terms', 'body' => '<p>Body text</p>', 'status' => 'published', 'revision' => 1, 'noindex' => false, 'meta_description' => 'About the terms', 'published_at' => now()]);

        $category = $this->tags($this->get('/product-category/clothing')->getContent());
        $this->assertSame('website', $category['og:type']);
        $this->assertSame('Clothing', $category['og:title']);
        $this->assertSame('https://shop.test/product-category/clothing', $category['og:url']);
        $this->assertArrayNotHasKey('og:image', $category);
        $this->assertArrayNotHasKey('product:price:amount', $category);

        $home = $this->tags($this->get('/')->getContent());
        $this->assertSame('website', $home['og:type']);
        $this->assertSame('Raf Shop', $home['og:title']);
        $this->assertSame('https://shop.test/', $home['og:url']);

        $page = $this->tags($this->get('/info/terms')->getContent());
        $this->assertSame('Terms', $page['og:title']);
        $this->assertSame('About the terms', $page['og:description']);
        $this->assertSame('summary', $page['twitter:card']);
    }

    public function test_no_tags_on_a_filtered_listing_a_404_search_cart_checkout_a_preview_or_a_noindex_page(): void
    {
        $this->product(1);
        $preview = PageModel::query()->forceCreate(['slug' => 'draft', 'title' => 'Draft', 'body' => '<p>x</p>', 'status' => 'draft', 'revision' => 1, 'noindex' => false]);
        PageModel::query()->forceCreate(['slug' => 'secret', 'title' => 'Secret', 'body' => '<p>x</p>', 'status' => 'published', 'revision' => 1, 'noindex' => true, 'published_at' => now()]);
        config(['storefront.search.enabled' => true]);

        $pages = [
            '/product/does-not-exist' => 404,
            '/search?q=product' => 200,
            '/cart' => 200,
            '/checkout' => 200,
            '/info/secret' => 200,
            PageController::previewUrl($preview) => 200,
        ];

        foreach ($pages as $url => $status) {
            $response = $this->get($url);
            $response->assertStatus($status);
            $this->assertSame([], $this->tags($response->getContent()), "{$url}: no share tags");
            $this->assertSame([], $this->blocks($response->getContent()), "{$url}: no JSON-LD");
        }

        // a filtered category page: the SeoMeta result itself carries no `og` / `ld`
        $category = app(\App\Storefront\Reader\CatalogReader::class)->category('clothing');
        $listing = app(\App\Storefront\Reader\CatalogReader::class)->listing(\App\Storefront\ReadModels\ListingQuery::create(\App\Storefront\ReadModels\ListingScope::CATEGORY, 1));
        $filtered = app(\App\Storefront\Support\SeoMeta::class)->forCategory($category, $listing, true);
        $this->assertTrue($filtered['noindex']);
        $this->assertArrayNotHasKey('og', $filtered);
        $this->assertArrayNotHasKey('ld', $filtered);
    }

    // ---- JSON-LD --------------------------------------------------------------------------------------------------------------------------

    public function test_a_simple_product_is_a_product_with_an_offer_and_a_breadcrumb(): void
    {
        $this->makeBrand(1, 'Zara', 'zara');
        $this->product(1, ['name' => 'Red dress', 'short' => 'A <b>nice</b> dress', 'brand' => 1, 'price' => '19.90']);
        $this->makeImage(1, 1);
        $this->makeImage(2, 1, 1);

        $html = $this->get('/product/p-1')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'application/ld+json'), 'one block');
        $graph = $this->blocks($html)[0];

        $this->assertSame('https://schema.org', $graph['@context']);
        $product = $this->node($html, 'Product');
        $this->assertSame('Red dress', $product['name']);
        $this->assertSame('A nice dress', $product['description']);
        $this->assertSame('https://shop.test/product/p-1', $product['url']);
        $this->assertSame('BASE-1', $product['sku']);
        $this->assertSame(['@type' => 'Brand', 'name' => 'Zara'], $product['brand']);
        $this->assertSame(['https://cdn.test/storage/p/1-large.webp', 'https://cdn.test/storage/p/2-large.webp'], $product['image']);

        $offer = $product['offers'];
        $this->assertSame('Offer', $offer['@type']);
        $this->assertSame('19.90', $offer['price'], 'a decimal STRING from the minor units');
        $this->assertSame('EUR', $offer['priceCurrency']);
        $this->assertSame('https://schema.org/InStock', $offer['availability']);
        $this->assertSame('https://schema.org/NewCondition', $offer['itemCondition']);
        $this->assertSame('https://shop.test/product/p-1', $offer['url']);

        $crumbs = $this->node($html, 'BreadcrumbList')['itemListElement'];
        $this->assertSame(['Начало', 'Clothing', 'Red dress'], array_column($crumbs, 'name'));
        $this->assertSame([1, 2, 3], array_column($crumbs, 'position'));
        $this->assertSame('https://shop.test/', $crumbs[0]['item']);
        $this->assertSame('https://shop.test/product-category/clothing', $crumbs[1]['item']);
        $this->assertArrayNotHasKey('item', $crumbs[2], 'the last item has no url');
    }

    public function test_a_variable_product_with_a_price_range_is_an_aggregate_offer(): void
    {
        $this->makeAttribute(1, 'Size', ['S', 'M', 'L']);
        $this->product(1, ['type' => 'variable', 'variations' => [
            ['attrs' => [1 => 'S'], 'price' => '10.00', 'stock' => 2],
            ['attrs' => [1 => 'M'], 'price' => '25.50', 'stock' => 1],
            ['attrs' => [1 => 'L'], 'price' => '30.00', 'stock' => 0, 'purchasable' => false],
        ]]);

        $offer = $this->node($this->get('/product/p-1')->assertOk()->getContent(), 'Product')['offers'];

        $this->assertSame('AggregateOffer', $offer['@type']);
        $this->assertSame('10.00', $offer['lowPrice']);
        $this->assertSame(['EUR', 'https://schema.org/InStock'], [$offer['priceCurrency'], $offer['availability']]);
        $this->assertGreaterThanOrEqual(2, $offer['offerCount']);
        $this->assertArrayHasKey('highPrice', $offer);
        $this->assertGreaterThan((float) $offer['lowPrice'], (float) $offer['highPrice']);
    }

    public function test_a_sold_out_product_is_out_of_stock(): void
    {
        $this->showSoldOutProducts();
        $this->product(1, ['stock' => 0]);

        $offer = $this->node($this->get('/product/p-1')->assertOk()->getContent(), 'Product')['offers'];

        $this->assertSame('https://schema.org/OutOfStock', $offer['availability']);
    }

    public function test_a_product_name_cannot_break_out_of_the_script_element(): void
    {
        $evil = '</script><script>alert(1)</script> & "q" \'s\'';
        $this->product(1, ['name' => $evil, 'short' => $evil]);

        $html = $this->get('/product/p-1')->assertOk()->getContent();

        $this->assertSame(1, preg_match_all('#<script type="application/ld\+json">#', $html), 'still one JSON-LD block');
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $block = substr($html, strpos($html, '<script type="application/ld+json">'));
        $block = substr($block, 0, strpos($block, '</script>') + 9);
        $this->assertSame(1, substr_count($block, '</script>'), 'the only closing tag inside the block is its own');
        $this->assertStringContainsString('</script>', $block);
        $this->assertSame($evil, $this->node($html, 'Product')['name'], 'and the data decodes back to the real name');
    }

    public function test_the_category_has_a_breadcrumb_list_only(): void
    {
        $this->makeCategory(2, 'Dresses', 'dresses', 1);
        $this->product(1, ['categories' => [2]]);

        $html = $this->get('/product-category/clothing/dresses')->assertOk()->getContent();
        $graph = $this->blocks($html)[0]['@graph'];

        $this->assertCount(1, $graph);
        $this->assertSame('BreadcrumbList', $graph[0]['@type']);
        $this->assertSame(['Начало', 'Clothing', 'Dresses'], array_column($graph[0]['itemListElement'], 'name'));
    }

    public function test_the_home_page_has_a_website_and_an_organization_and_the_search_action_only_with_search_on(): void
    {
        $this->product(1);

        $off = $this->get('/')->assertOk()->getContent();
        $site = $this->node($off, 'WebSite');
        $this->assertSame(['@type' => 'WebSite', 'name' => 'Raf Shop', 'url' => 'https://shop.test/'], $site);
        $this->assertSame(['@type' => 'Organization', 'name' => 'Raf Shop', 'url' => 'https://shop.test/'], $this->node($off, 'Organization'));

        config(['storefront.search.enabled' => true]);
        $on = $this->node($this->get('/')->assertOk()->getContent(), 'WebSite');
        $this->assertSame('SearchAction', $on['potentialAction']['@type']);
        $this->assertSame('https://shop.test/search?q={search_term_string}', $on['potentialAction']['target']['urlTemplate']);
        $this->assertSame('required name=search_term_string', $on['potentialAction']['query-input']);
    }

    public function test_a_content_page_has_no_structured_data_and_the_tags_are_not_duplicated(): void
    {
        PageModel::query()->forceCreate(['slug' => 'terms', 'title' => 'Terms', 'body' => '<p>x</p>', 'status' => 'published', 'revision' => 1, 'noindex' => false, 'published_at' => now()]);

        $html = $this->get('/info/terms')->assertOk()->getContent();

        $this->assertSame([], $this->blocks($html));
        $this->assertSame(1, substr_count($html, 'property="og:title"'));
        $this->assertSame(1, DB::table('pages')->count());
    }
}
