<?php

namespace Tests\Feature\Storefront\Cache;

use App\Http\Middleware\LimitFilteredCategoryRequests;
use App\Storefront\Cache\StorefrontCacheHeaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** P8b: which responses the page cache may keep, and the headers it gets (storefront-cache-contract.md §1). */
class CacheHeadersTest extends TestCase
{
    use BuildsCacheShop;
    use RefreshDatabase;

    private const PINNED_404 = 3;

    private const CACHEABLE = 'max-age=0, public, s-maxage=604800';

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop();
    }

    /** @return array<string, array{0: string, 1: list<string>}> the url and the keys it must carry besides `all` */
    public static function cacheable(): array
    {
        return [
            'home' => ['/', ['home']],
            'product' => ['/product/alpha', ['p1', 'b1', 's1', 'c2', 'c1']],
            'category' => ['/product-category/clothing', ['c1']],
            'sub category' => ['/product-category/clothing/dresses', ['c2', 'c1', 'p1', 'p2']],
            'category with bounded parameters' => ['/product-category/clothing?page=1&sort=newest&per_page=24', ['c1']],
            'brand index' => ['/brand', ['idx-brand']],
            'brand' => ['/brand/acme', ['b1', 'p1']],
            'season' => ['/season/summer', ['s1', 'p1']],
            'tag' => ['/product-tag/new', ['t1', 'p1']],
            'collection index' => ['/collection', ['idx-collection']],
            'collection' => ['/collection/col-one', ['col1', 'p1']],
            'info page' => ['/info/about', []],
            'robots' => ['/robots.txt', []],
            'sitemap' => ['/sitemap.xml', ['sitemap']],
            'sitemap pages' => ['/sitemap/pages.xml', ['sitemap']],
            'sitemap products' => ['/sitemap/products-1.xml', ['sitemap']],
        ];
    }

    /** @param  list<string>  $keys */
    #[DataProvider('cacheable')]
    public function test_a_cacheable_route_gets_the_public_header_its_keys_and_no_cookie(string $url, array $keys): void
    {
        $this->fresh();
        $response = $this->get($url);

        $response->assertOk();
        $this->assertSame(self::CACHEABLE, $response->headers->get('Cache-Control'), $url);
        $this->assertSame([], $response->headers->getCookies(), $url.' must not set a cookie');
        $this->assertFalse($response->headers->has('Set-Cookie'), $url);

        $carried = explode(' ', (string) $response->headers->get('xkey'));
        $this->assertContains('all', $carried, $url);

        foreach ($keys as $key) {
            $this->assertContains($key, $carried, "{$url}: {$key} in ".implode(' ', $carried));
        }
    }

    public function test_the_info_page_carries_its_own_key(): void
    {
        $id = DB::table('pages')->where('slug', 'about')->value('id');

        $this->assertContains('pg'.$id, $this->xkeys('/info/about'));
    }

    public function test_a_slug_case_301_is_cacheable_too(): void
    {
        $this->fresh();
        $response = $this->get('/brand/ACME');

        $response->assertStatus(301);
        $this->assertSame(self::CACHEABLE, $response->headers->get('Cache-Control'));
        $this->assertContains('b1', explode(' ', (string) $response->headers->get('xkey')));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function passing(): array
    {
        return [
            'filtered listing' => ['GET', '/product-category/clothing?filter[brand]=1'],
            'filtered subject listing' => ['GET', '/brand/acme?filter[tag]=1'],
            'search' => ['GET', '/search?q=alpha'],
            'search page without q' => ['GET', '/search'],
            'cart' => ['GET', '/cart'],
            'checkout' => ['GET', '/checkout'],
            'missing product (404)' => ['GET', '/product/never-existed'],
            'missing category (404)' => ['GET', '/product-category/never-existed'],
            'page beyond the last (404)' => ['GET', '/product-category/clothing?page=9'],
            'card sizes' => ['GET', '/storefront/card-sizes?ids=1,2'],
            'api cart' => ['GET', '/api/cart'],
            'post to a listing' => ['POST', '/product-category/clothing'],
            'post to home' => ['POST', '/'],
        ];
    }

    #[DataProvider('passing')]
    public function test_a_pass_route_never_gets_an_xkey_or_the_cacheable_header(string $method, string $url): void
    {
        $this->fresh();
        $response = $this->call($method, $url);

        $this->assertFalse($response->headers->has('xkey'), "{$method} {$url}");
        $this->assertNotSame(self::CACHEABLE, $response->headers->get('Cache-Control'), "{$method} {$url}");
    }

    public function test_the_existing_no_store_headers_are_left_as_they_are(): void
    {
        foreach (['/cart', '/checkout'] as $url) {
            $this->fresh();
            $this->assertSame('no-store, private', $this->get($url)->headers->get('Cache-Control'), $url);
        }

        $this->fresh();
        $this->assertSame('max-age=60, public, s-maxage=60', $this->get('/storefront/card-sizes?ids=1,2')->headers->get('Cache-Control'));
    }

    public function test_a_404_keeps_its_url_has_no_redirect_and_is_not_cacheable(): void
    {
        $this->fresh();
        $response = $this->get('/product/never-existed');

        $response->assertNotFound();
        $this->assertFalse($response->isRedirection());
        $this->assertFalse($response->headers->has('xkey'));
        $this->assertStringNotContainsString('s-maxage', (string) $response->headers->get('Cache-Control'));
    }

    public function test_the_404_costs_a_pinned_number_of_queries(): void
    {
        $this->get('/product/never-existed'); // warm: the appearance cache
        $this->fresh();
        DB::enableQueryLog();
        $this->get('/product/never-existed')->assertNotFound();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // the 404 shows no catalog content (`categories => []`), so none of these is a catalog query
        $this->assertSame(self::PINNED_404, $count, 'the 404 query count is pinned');
    }

    public function test_a_cacheable_response_with_a_cookie_is_downgraded_and_logged(): void
    {
        Log::spy();
        Route::get('/p8b-cookie-test', fn () => response('ok')->cookie('x', '1'))->middleware(StorefrontCacheHeaders::class)->name('storefront.page');

        $this->fresh();
        $response = $this->get('/p8b-cookie-test');

        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertFalse($response->headers->has('xkey'));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => ($context['route'] ?? null) === 'storefront.page')->once();
    }

    public function test_featured_is_limited_like_filter(): void
    {
        $key = 'filtered-category:127.0.0.1';
        RateLimiter::clear($key);

        for ($i = 0; $i < LimitFilteredCategoryRequests::LIMIT; $i++) {
            RateLimiter::hit($key, 60);
        }

        $this->fresh();
        $this->get('/product-category/clothing?featured=1')->assertStatus(429);
        $this->fresh();
        $this->get('/product-category/clothing?filter[brand]=1')->assertStatus(429);
        // a request with neither parameter is not touched
        $this->fresh();
        $this->get('/product-category/clothing')->assertOk();
    }
}
