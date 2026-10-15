<?php

namespace Tests\Feature\Storefront;

use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/** The S3 pages: /cart (a server shell), the header cart link, the add-to-cart button's data, the real home page. */
class StorefrontCartPageTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // See StorefrontHttpSecurityTest::setUp: a static flag left by a Livewire test in the same process.
        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;

        config(['filesystems.disks.public.url' => 'https://cdn.test/storage', 'app.name' => 'Raf Shop']);
        $this->seedPricing();

        $clothing = $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeCategory(2, 'Shoes', 'shoes');
        $this->makeAttribute(1, 'Size', ['S', 'M', 'L']);

        $this->makeProduct(1, ['name' => 'Summer dress', 'slug' => 'summer-dress', 'categories' => [$clothing], 'price' => '49.90', 'stock' => 3, 'timeline' => '2026-03-01 10:00:00']);
        $this->makeProduct(2, ['name' => 'Sold-out scarf', 'slug' => 'sold-out-scarf', 'categories' => [$clothing], 'price' => '15.00', 'stock' => 0, 'timeline' => '2026-02-01 10:00:00']);
        $this->makeProduct(3, ['type' => 'variable', 'name' => 'Linen shirt', 'slug' => 'linen-shirt', 'categories' => [$clothing], 'timeline' => '2026-01-01 10:00:00', 'variations' => [
            ['attrs' => [1 => 'S'], 'price' => '40.00', 'stock' => 0],
            ['attrs' => [1 => 'M'], 'price' => '45.00', 'stock' => 3],
            ['attrs' => [1 => 'L'], 'price' => '45.00', 'stock' => 1],
        ]]);
        $this->makeProduct(4, ['type' => 'variable', 'name' => 'Sold-out vest', 'slug' => 'sold-out-vest', 'timeline' => '2025-12-01 10:00:00', 'variations' => [
            ['attrs' => [1 => 'S'], 'price' => '20.00', 'stock' => 0],
            ['attrs' => [1 => 'M'], 'price' => '20.00', 'stock' => 0, 'purchasable' => false],
        ]]);
    }

    // ---- /cart -----------------------------------------------------------------------------------------------

    public function test_the_cart_page_is_a_cookie_free_shell_that_is_private_no_store_and_noindex(): void
    {
        $response = $this->get('/cart')->assertOk();
        $html = $response->getContent();

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);
        $this->assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $html);
        $this->assertStringNotContainsString('rel="canonical"', $html);
        $this->assertFalse($response->headers->has('Set-Cookie'));
        $this->assertSame([], $response->headers->getCookies());
        $this->assertFalse($this->app['session']->driver()->isStarted());

        $this->assertStringContainsString('<title>Your cart — Raf Shop</title>', $html);
        $this->assertStringContainsString('data-sf-cart-page', $html);
        $this->assertStringContainsString('data-sf-cart-content', $html);
        $this->assertStringContainsString('<noscript>', $html);
        $this->assertStringContainsString('needs JavaScript', $html);
        $this->assertStringContainsString('href="/product-category/clothing"', $html, 'the menu');
        $this->assertStringNotContainsString('<form', $html, 'the shell has no form: the script builds the promo form');
    }

    public function test_the_cart_route_is_in_the_cookie_free_group(): void
    {
        $route = Route::getRoutes()->getByName('storefront.cart');

        $this->assertNotNull($route);
        $this->assertSame('cart', $route->uri());
        $this->assertSame(['GET', 'HEAD'], $route->methods());

        $this->withUnencryptedCookies(['laravel_session' => 'abc', 'XSRF-TOKEN' => 'xyz'])->get('/cart')->assertOk()->assertCookieMissing('XSRF-TOKEN');
    }

    public function test_the_page_hands_the_script_the_shops_price_format_and_its_texts(): void
    {
        $html = $this->get('/cart')->assertOk()->getContent();

        $this->assertStringContainsString('data-price-template="0.00 €"', $html);
        $this->assertStringContainsString('data-price-zero="0.00"', $html);
        $this->assertStringContainsString('data-price-currency="EUR"', $html);
        $this->assertStringContainsString('data-m-added="Added to your cart."', $html);
        $this->assertStringContainsString('data-m-promo-apply="Apply"', $html);
        $this->assertStringContainsString('data-cart-url="/cart"', $html);
        $this->assertDoesNotMatchRegularExpression('/<script/i', $html, 'no inline script: the texts travel as data attributes');
    }

    public function test_the_price_template_follows_the_stores_symbol_position_setting(): void
    {
        app(SiteSettingsRepository::class)->set('site.currency_symbol_position', 'prefix');

        $this->assertStringContainsString('data-price-template="€0.00"', $this->get('/cart')->getContent());
    }

    public function test_the_cart_page_is_translated(): void
    {
        app(SiteSettingsRepository::class)->set('site.locale', 'bg');

        $html = $this->get('/cart')->assertOk()->getContent();

        $this->assertStringContainsString('Вашата количка', $html);
        $this->assertStringContainsString('data-m-added="Добавено в количката."', $html);
    }

    // ---- the header ------------------------------------------------------------------------------------------

    public function test_every_page_has_a_plain_cart_link_with_an_empty_hidden_count(): void
    {
        foreach (['/', '/product/summer-dress', '/product-category/clothing', '/cart', '/product/missing'] as $url) {
            $html = $this->get($url)->getContent();

            $this->assertMatchesRegularExpression('/<a class="sf-cart" href="\/cart" data-sf-cart-link>\s*<svg class="sf-cart-icon"[^>]*aria-hidden="true"[^>]*>.*?<\/svg>\s*<span class="sf-cart-label">Cart<\/span>\s*<span class="sf-cart-count" data-sf-cart-count hidden><\/span>\s*<\/a>/s', $html, $url);
        }
    }

    // ---- the add-to-cart button ------------------------------------------------------------------------------

    public function test_the_button_carries_the_first_purchasable_in_stock_variation_and_is_empty_when_there_is_none(): void
    {
        $simple = $this->get('/product/summer-dress')->getContent();
        $variable = $this->get('/product/linen-shirt')->getContent();
        $soldOutSimple = $this->get('/product/sold-out-scarf')->getContent();
        $soldOutVariable = $this->get('/product/sold-out-vest')->getContent();

        $this->assertStringContainsString('disabled data-variation-id="101"', $simple);
        $this->assertStringContainsString('disabled data-variation-id="302"', $variable, 'the first in-stock one: S (301) has no stock');
        $this->assertStringContainsString('disabled data-variation-id=""', $soldOutSimple);
        $this->assertStringContainsString('disabled data-variation-id=""', $soldOutVariable);
    }

    public function test_the_button_is_disabled_without_javascript_and_the_page_has_a_live_status_region(): void
    {
        $html = $this->get('/product/summer-dress')->getContent();

        $this->assertMatchesRegularExpression('/<button class="sf-add-to-cart" type="button" disabled data-variation-id="101">/', $html);
        $this->assertMatchesRegularExpression('/<p class="sf-cart-status" role="status" aria-live="polite" data-sf-cart-status><\/p>/', $html);
        $this->assertStringContainsString('data-sf-add-to-cart', $html);
    }

    public function test_the_variation_select_disables_unavailable_options(): void
    {
        $html = $this->get('/product/linen-shirt')->getContent();

        $this->assertMatchesRegularExpression('/<option value="301" disabled>/', $html);
        $this->assertMatchesRegularExpression('/<option value="302" >/', $html);
        $this->assertMatchesRegularExpression('/<option value="303" >/', $html);
    }

    // ---- home ------------------------------------------------------------------------------------------------

    public function test_the_home_page_has_the_intro_the_category_row_and_the_newest_products(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<section class="sf-intro">', $html);
        $this->assertStringContainsString('Welcome to Raf Shop', $html);
        $this->assertStringContainsString('Browse the newest products', $html);
        $this->assertStringContainsString('<section class="sf-category-row"', $html);
        $this->assertStringContainsString('Shop by category', $html);
        $this->assertMatchesRegularExpression('/<a class="sf-category-tile-link" href="\/product-category\/clothing">\s*<span class="sf-category-tile-name">Clothing<\/span>\s*<span class="sf-category-tile-count">3 products<\/span>/', $html);
        $this->assertSame(1, substr_count($html, 'class="sf-category-tile"'), 'Shoes has no visible product: not in the row');
        $this->assertStringContainsString('Newest products', $html);
        $this->assertSame(4, substr_count($html, 'class="sf-card"'));
        $this->assertLessThan(strpos($html, 'Sold-out scarf'), strpos($html, 'Summer dress'), 'newest first');
        $this->assertSame(1, substr_count($html, '<h1'), 'one h1');
    }

    public function test_the_category_count_uses_the_singular_for_one_and_the_plural_otherwise_in_both_languages(): void
    {
        // Clothing has 3 visible products; Shoes gets 1, then 2.
        $this->makeProduct(10, ['slug' => 'one-shoe', 'categories' => [2], 'price' => '5.00', 'stock' => 1]);

        $one = $this->get('/')->getContent();
        $this->assertMatchesRegularExpression('/<span class="sf-category-tile-name">Shoes<\/span>\s*<span class="sf-category-tile-count">1 product<\/span>/', $one);
        $this->assertStringContainsString('>3 products<', $one);

        $this->app->forgetScopedInstances();
        $this->makeProduct(11, ['slug' => 'two-shoes', 'categories' => [2], 'price' => '5.00', 'stock' => 1]);

        $this->assertMatchesRegularExpression('/<span class="sf-category-tile-name">Shoes<\/span>\s*<span class="sf-category-tile-count">2 products<\/span>/', $this->get('/')->getContent());

        $this->app->forgetScopedInstances();
        app(SiteSettingsRepository::class)->set('site.locale', 'bg');
        $bg = $this->get('/')->getContent();
        $this->assertStringContainsString('>2 продукта<', $bg);

        DB::table('catalog_product_categories')->where('product_id', 11)->delete();
        DB::table('stock_levels')->where('variation_id', 1101)->delete();
        DB::table('catalog_variations')->where('product_id', 11)->delete();
        DB::table('catalog_products')->where('id', 11)->delete();

        $this->app->forgetScopedInstances();
        $this->assertStringContainsString('>1 продукт<', $this->get('/')->getContent());
    }

    public function test_an_empty_shop_has_an_intro_and_an_empty_message_but_no_category_row(): void
    {
        DB::table('catalog_products')->update(['catalog_visibility' => 'hidden']);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('sf-intro', $html);
        $this->assertStringNotContainsString('sf-category-row', $html);
        $this->assertStringContainsString('There are no products here yet.', $html);
    }

    // ---- query counts ----------------------------------------------------------------------------------------

    public function test_the_home_page_and_the_cart_page_query_counts(): void
    {
        $count = function (string $url): int {
            $this->app->forgetScopedInstances();
            \Illuminate\Support\Facades\Cache::forget(\App\Storefront\Appearance\StorefrontAppearance::CACHE_KEY); // D1: every measurement is a COLD page (+1 for the appearance read)

            foreach (Route::getRoutes() as $route) {
                $route->flushController();
            }

            $sql = [];
            DB::listen(function ($query) use (&$sql): void {
                $sql[] = $query->sql;
            });

            $this->get($url)->assertOk();

            return count($sql);
        };

        $home = $count('/');
        $cart = $count('/cart');

        fwrite(STDERR, "\n[query-count] storefront S3: / = {$home} queries, /cart = {$cart} queries (home ceiling 25, cart ceiling 7)\n");

        $this->assertLessThanOrEqual(25, $home, 'D1: +1 appearance read (cold cache); the home page is not dearer than before S3 (the category row reuses the menu data)');
        $this->assertLessThanOrEqual(7, $cart, 'D1: +1 appearance read (cold cache); locale, time zone, currency position and the menu (2): the shell reads no cart');
    }
}
