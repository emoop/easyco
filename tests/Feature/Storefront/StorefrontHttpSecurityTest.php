<?php

namespace Tests\Feature\Storefront;

use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StorefrontHttpSecurityTest extends TestCase
{
    use AssertsSafeHtml;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A Livewire test earlier in the same PHP process leaves this static flag set; a real request starts with it false
        // (and a storefront page renders no Livewire component), so it must not inject Livewire's <script>/<style> here.
        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;

        config(['filesystems.disks.public.url' => 'https://cdn.test/storage']);
        $this->seedPricing();

        $clothing = $this->makeCategory(1, 'Clothing', 'clothing');
        $dresses = $this->makeCategory(2, 'Dresses', 'dresses', $clothing);
        $this->makeCategory(3, 'Empty', 'empty');
        $hiddenCategory = $this->makeCategory(4, 'Hidden only', 'hidden-only');

        $this->makeProduct(1, ['name' => 'Summer dress', 'slug' => 'summer-dress', 'categories' => [$dresses], 'price' => '10.00', 'stock' => 1]);
        $this->makeImage(1, 1);
        $this->makeProduct(2, ['slug' => 'hidden', 'visibility' => 'hidden', 'categories' => [$hiddenCategory]]);
        $this->makeProduct(3, ['slug' => 'draft', 'status' => 'draft']);
        $this->makeProduct(4, ['slug' => 'archived', 'status' => 'archived']);
        $this->makeProduct(5, ['slug' => 'deleted', 'deleted' => true]);
    }

    /** @return list<string> every storefront url that renders a page */
    private function pageUrls(): array
    {
        return ['/', '/product/summer-dress', '/product-category/clothing', '/product-category/clothing/dresses', '/product/missing', '/product-category/missing'];
    }

    public function test_no_storefront_route_sets_a_cookie_or_starts_a_session(): void
    {
        foreach ([...$this->pageUrls(), '/product/Summer-Dress', '/product-category/wrong/dresses', '/product/hidden', '/product-category/clothing?page=99'] as $url) {
            $response = $this->get($url);

            $this->assertFalse($response->headers->has('Set-Cookie'), "{$url}: Set-Cookie");
            $this->assertSame([], $response->headers->getCookies(), "{$url}: cookies");
            $this->assertFalse($this->app['session']->driver()->isStarted(), "{$url}: the session was started");
        }
    }

    public function test_a_request_carrying_session_and_xsrf_cookies_still_gets_none_back(): void
    {
        $response = $this->withUnencryptedCookies(['laravel_session' => 'abc', 'XSRF-TOKEN' => 'xyz'])->get('/product/summer-dress');

        $response->assertOk();
        $this->assertFalse($response->headers->has('Set-Cookie'));
        $this->assertFalse($this->app['session']->driver()->isStarted());
    }

    public function test_no_page_carries_a_csrf_token(): void
    {
        foreach ($this->pageUrls() as $url) {
            $html = $this->get($url)->getContent();

            $this->assertDoesNotMatchRegularExpression('/csrf|_token|xsrf/i', $html, $url);
            $this->assertStringNotContainsString('<form', $html, $url);
        }
    }

    public function test_every_storefront_response_has_the_security_headers_and_no_caching_headers(): void
    {
        foreach ([...$this->pageUrls(), '/product/Summer-Dress', '/product-category/wrong/dresses', '/product/hidden'] as $url) {
            $response = $this->get($url);

            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), $url);
            $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'), $url);

            $cacheControl = (string) $response->headers->get('Cache-Control');
            $this->assertStringNotContainsString('public', $cacheControl, $url);
            $this->assertStringNotContainsString('s-maxage', $cacheControl, $url);
            $this->assertStringNotContainsString('max-age=', str_replace('s-maxage', '', $cacheControl), $url);
            $this->assertFalse($response->headers->has('ETag'), $url);
            $this->assertFalse($response->headers->has('Last-Modified'), $url);
            $this->assertFalse($response->headers->has('Expires'), $url);
        }
    }

    public function test_every_way_to_miss_a_page_is_the_identical_404(): void
    {
        $reference = $this->get('/product/never-existed');
        $reference->assertNotFound();
        $body = $reference->getContent();

        $urls = [
            '/product/hidden', '/product/draft', '/product/archived', '/product/deleted',
            '/product/a%20b', '/product/%00', '/product/a%2Fb', '/product/..', '/product/'.str_repeat('a', 5000),
            '/product-category/missing', '/product-category/empty', '/product-category/hidden-only', '/product-category/a/b/c/d/e/f/g/h/i',
            '/product-category/clothing?page=0', '/product-category/clothing?page=abc', '/product-category/clothing?page=2',
            '/product-category/clothing?sort=price_asc', '/product-category/clothing?sort=nope', '/product-category/clothing?per_page=100',
        ];

        foreach ($urls as $url) {
            $response = $this->get($url);

            $this->assertSame(404, $response->getStatusCode(), $url);
            $this->assertSame($body, $response->getContent(), "{$url}: the 404 body differs");
        }
    }

    public function test_garbage_in_the_path_or_the_query_is_a_404_or_a_redirect_never_a_500(): void
    {
        $garbage = [
            '/product/%F0%9F%98%80', '/product/%E0%A4%A', '/product/%00%00', '/product/%0A', '/product/%0D%0Aset-cookie:x=1', '/product/..%2F..%2Fetc%2Fpasswd',
            '/product/%2e%2e', '/product/a%5Cb', '/product/%20', '/product/%C0%AF', "/product/<script>", '/product/;', "/product/a'b",
            '/product-category/%2e%2e/%2e%2e', '/product-category/%00', '/product-category/a%2Fb', '/product-category/%20', '/product-category//',
            '/product-category/'.str_repeat('я/', 50), '/product-category/'.str_repeat('a', 100000),
            '/product-category/clothing?page[]=1', '/product-category/clothing?sort[]=x', '/product-category/clothing?per_page=99999999999999999999999',
            '/product-category/clothing?page=%00', '/product-category/clothing?page=-1', '/product-category/clothing?page=1e2', '/product-category/clothing?sort=%F0%9F',
            '/product-category/clothing?x='.str_repeat('a', 100000),
        ];

        foreach ($garbage as $url) {
            $status = $this->get($url)->getStatusCode();

            $this->assertContains($status, [200, 301, 400, 404], substr($url, 0, 80).' answered '.$status);
        }
    }

    public function test_an_unknown_query_key_and_a_price_filter_are_ignored_and_a_price_sort_is_refused(): void
    {
        $plain = $this->get('/product-category/clothing');
        $noisy = $this->get('/product-category/clothing?price_min=5&price_max=9&utm_source=x&q=dress&category=3&brand=1&tag=2');

        $plain->assertOk();
        $noisy->assertOk();
        $this->assertSame($plain->getContent(), $noisy->getContent());

        $this->get('/product-category/clothing?sort=price_desc')->assertNotFound();
        $this->get('/product-category/clothing?sort=price_asc&price_min=5')->assertNotFound();
    }

    public function test_a_different_case_of_a_product_slug_is_a_301_to_the_canonical_url(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite compares slugs case-sensitively: a different case is a 404 there.');
        }

        $response = $this->get('/product/Summer-Dress');

        $response->assertStatus(301);
        $this->assertSame(url('/product/summer-dress'), $response->headers->get('Location'));
        $this->assertFalse($response->headers->has('Set-Cookie'));
    }

    public function test_a_wrong_category_prefix_or_case_is_a_301_keeping_only_whitelisted_query_keys(): void
    {
        $this->get('/product-category/wrong/dresses')->assertStatus(301)->assertHeader('Location', url('/product-category/clothing/dresses'));
        $this->get('/product-category/dresses')->assertStatus(301)->assertHeader('Location', url('/product-category/clothing/dresses'));
        $this->get('/product-category/Clothing/Dresses')->assertStatus(301)->assertHeader('Location', url('/product-category/clothing/dresses'));

        $response = $this->get('/product-category/wrong/dresses?per_page=12&sort=name_asc&utm_source=x&price_min=1');

        $response->assertStatus(301);
        $this->assertSame(url('/product-category/clothing/dresses?per_page=12&sort=name_asc'), $response->headers->get('Location'));
        $this->get('/product-category/clothing/dresses')->assertOk();
        $this->get('/product-category/clothing/dresses/')->assertOk();
    }

    public function test_hostile_text_in_every_field_never_becomes_active_content(): void
    {
        $payloads = [
            '<script>alert(1)</script>',
            '"><img src=x onerror=alert(1)>',
            '<svg onload=alert(1)>',
            '</title><script>alert(1)</script>',
            "' onmouseover='alert(1)",
            'javascript:alert(1)',
            'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
            '<iframe src="https://evil.example"></iframe>',
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            '<scr<script>ipt>alert(1)</scr</script>ipt>',
        ];

        foreach ($payloads as $i => $payload) {
            DB::table('catalog_brands')->where('id', 1)->delete();
            $brand = $this->makeBrand(1, $payload, 'brand-x');
            DB::table('catalog_categories')->where('id', 9)->delete();
            $category = $this->makeCategory(9, $payload, 'hostile-cat');
            $slug = 'x'.$i;
            DB::table('catalog_products')->where('id', 50)->delete();
            $this->makeProduct(50, [
                'name' => $payload, 'slug' => $slug, 'brand' => $brand, 'categories' => [$category], 'price' => '5.00', 'stock' => 1,
                'short' => $payload, 'description' => '<p>ok</p>'.$payload.'<a href="'.$payload.'">link</a><p style="x:y" onclick="z()">t</p>',
            ]);
            $this->makeImage(900 + $i, 50, 0, alt: $payload);

            foreach (['/', '/product/'.$slug, '/product-category/hostile-cat'] as $url) {
                $response = $this->get($url);
                $response->assertOk();
                $this->assertNoActiveContent($response->getContent(), "{$url} with payload {$i}");
            }

            DB::table('catalog_media')->where('id', 900 + $i)->delete();
            DB::table('stock_levels')->where('variation_id', 5001)->delete();
            DB::table('catalog_variations')->where('product_id', 50)->delete();
            DB::table('catalog_product_categories')->where('product_id', 50)->delete();
            DB::table('catalog_products')->where('id', 50)->delete();
        }
    }

    public function test_the_text_is_escaped_and_the_description_is_the_only_raw_output(): void
    {
        $brand = $this->makeBrand(7, 'Brand <b>x</b>', 'brand-seven');
        $this->makeProduct(60, [
            'name' => '<script>alert(1)</script> & "q"', 'slug' => 'escaped', 'brand' => $brand, 'price' => '5.00', 'stock' => 1,
            'short' => '<em>short</em> <script>x</script>', 'description' => '<p>fine <strong>bold</strong></p><script>alert(2)</script><a href="javascript:alert(3)">l</a>',
        ]);
        $this->makeImage(990, 60, 0, alt: 'a "quoted" <alt>');

        $html = $this->get('/product/escaped')->assertOk()->getContent();

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;q&quot;', $html);
        $this->assertStringContainsString('Brand &lt;b&gt;x&lt;/b&gt;', $html);
        $this->assertStringContainsString('alt="a &quot;quoted&quot; &lt;alt&gt;"', $html);
        $this->assertStringContainsString('<p>fine <strong>bold</strong></p>', $html, 'the sanitised description');
        $this->assertStringNotContainsString('alert(2)', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('<meta name="description" content="short x">', $html, 'plain text, tags stripped, escaped by the view');
        $this->assertNoActiveContent($html);
    }

    public function test_a_hostile_slug_in_the_database_is_url_encoded_in_every_link(): void
    {
        $this->makeProduct(70, ['slug' => '"><script>alert(1)</script>', 'price' => '5.00', 'stock' => 1, 'timeline' => '2026-09-01 10:00:00']);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('href="/product/%22%3E%3Cscript%3Ealert%281%29%3C%2Fscript%3E"', $html);
        $this->assertNoActiveContent($html);
        $this->get('/product/'.rawurlencode('"><script>alert(1)</script>'))->assertNotFound();
    }

    public function test_the_pagination_links_are_built_from_whitelisted_keys_only(): void
    {
        foreach (range(10, 40) as $id) {
            $this->makeProduct($id, ['categories' => [2], 'price' => '1.00', 'stock' => 1]);
        }

        $html = $this->get('/product-category/clothing/dresses?page=2&per_page=12&evil="><script>=1&sort=name_asc')->assertOk()->getContent();

        preg_match_all('/<a [^>]*href="([^"]*)"/', $html, $matches);
        $links = array_filter($matches[1], fn (string $href) => str_contains($href, '?'));

        $this->assertNotEmpty($links);

        foreach ($links as $href) {
            parse_str((string) parse_url(html_entity_decode($href), PHP_URL_QUERY), $query);
            $this->assertSame([], array_diff(array_keys($query), ['page', 'per_page', 'sort']), $href);
        }

        $this->assertNoActiveContent($html);
    }

    public function test_the_store_locale_is_applied_without_a_session(): void
    {
        app(SiteSettingsRepository::class)->set('site.locale', 'bg');

        $html = $this->get('/product/summer-dress')->assertOk()->getContent();

        $this->assertStringContainsString('<html lang="bg">', $html);
        $this->assertStringContainsString('Начало', $html);
        $this->assertStringContainsString('Добави в количката', $html);
        $this->assertFalse($this->app['session']->driver()->isStarted());
    }
}
