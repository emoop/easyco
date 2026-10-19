<?php

namespace Tests\Feature\Storefront;

use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\StorefrontHomeSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * Stage S3-V: the cart page's shell and script (statically), the home page's editable intro and its anchor, the shared layout's bag icon and footer,
 * and the query numbers that must not rise. The cart script's behaviour is StorefrontCartFlowTest (fake DOM).
 */
class StorefrontHomeAndCartS3vTest extends TestCase
{
    use AssertsSafeHtml;
    use BuildsMenus;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    /** The numbers before S3-V plus D1's ONE appearance read on a cold cache (StorefrontCartPageTest's ceilings): neither page may cost more now. */
    private const HOME_QUERIES = 24;

    private const CART_QUERIES = 7;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        config(['app.name' => 'Raf Shop']);
        $this->seedPricing();
        $category = $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeProduct(1, ['slug' => 'dress', 'categories' => [$category], 'price' => '10.00', 'stock' => 3, 'name' => 'Summer dress']);
    }

    private function countQueries(string $url): int
    {
        $this->app->forgetScopedInstances();
        \Illuminate\Support\Facades\Cache::forget(\App\Storefront\Appearance\StorefrontAppearance::CACHE_KEY); // D1: every measurement is a COLD page (+1 for the appearance read)

        foreach (Route::getRoutes() as $route) {
            $route->flushController();
        }

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $this->get($url)->assertOk();

        return $count;
    }

    private function cartScript(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/storefront-cart.js');
    }

    // ---- the cart shell ------------------------------------------------------------------------------------------------------------

    public function test_the_cart_shell_is_still_private_cookie_free_noindex_and_costs_what_it_cost(): void
    {
        $response = $this->get('/cart')->assertOk();

        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
        $this->assertSame([], $response->headers->getCookies());
        $this->assertFalse($this->app['session']->driver()->isStarted());
        $this->assertDoesNotMatchRegularExpression('/<script/i', $response->getContent(), 'no inline script');
        $this->assertLessThanOrEqual(self::CART_QUERIES, $this->countQueries('/cart'));
    }

    public function test_the_empty_cart_links_come_with_the_shell_from_the_menu_data_and_stay_hidden_until_the_script_needs_them(): void
    {
        $html = $this->get('/cart')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<div class="sf-cart-empty-extras" data-sf-cart-empty-extras hidden>.*?<a href="\/product-category\/clothing">Clothing<\/a>.*?<\/div>/s', $html);
        $this->assertStringContainsString('data-home-url="/"', $html, 'the empty state\'s shop button');
        $this->assertStringNotContainsString('<form', $html);
    }

    public function test_the_cart_shell_passes_the_safe_html_check_with_the_one_allowed_icon(): void
    {
        $this->assertNoActiveContent($this->get('/cart')->getContent(), 'cart');
    }

    // ---- the cart script (source) ---------------------------------------------------------------------------------------------------

    public function test_the_stepper_buttons_and_the_input_share_one_handler(): void
    {
        $script = $this->cartScript();

        $this->assertSame(1, substr_count($script, 'var onQuantityChange = function () {'), 'ONE handler');
        $this->assertStringContainsString("input.addEventListener('change', onQuantityChange);", $script, 'the input runs it');
        $this->assertSame(2, substr_count($script, 'onQuantityChange();'), 'and so do the minus and the plus, after setting the input');
        $this->assertSame(1, substr_count($script, "api('PATCH', '/api/cart/lines/'"), 'ONE request site for a quantity change');
        $this->assertMatchesRegularExpression('/minus\.addEventListener\(\'click\', function \(\) \{\s*var current = parseInt\(input\.value, 10\);\s*if \(isNaN\(current\) \|\| current <= 1\) \{\s*return;/', $script, 'minus at 1 does nothing');
    }

    public function test_the_checkout_control_is_an_enabled_link_and_never_a_disabled_button(): void
    {
        $script = $this->cartScript();

        $this->assertStringContainsString("var checkout = el('a', 'sf-cart-checkout', t('checkout_label'));", $script);
        $this->assertStringContainsString("var barLink = el('a', 'sf-cart-checkout sf-cart-bar-button', t('checkout_label'));", $script);
        $this->assertDoesNotMatchRegularExpression('/sf-cart-checkout[^;]*disabled/', $script);
    }

    public function test_the_promo_box_is_the_compact_one_and_the_only_placeholder_is_the_floating_label_space(): void
    {
        $script = $this->cartScript();

        $this->assertStringContainsString('summary.appendChild(promotionBox(cart, act, { compact: true }));', $script);
        $this->assertSame(1, substr_count($script, "setAttribute('placeholder'"), 'one placeholder in the whole script');
        $this->assertStringContainsString("code.setAttribute('placeholder', ' ');", $script, 'in the compact branch, a single space');
        $this->assertStringNotContainsString("placeholder', t('promo_label')", $script, 'no "Promo code" placeholder text any more');
    }

    public function test_the_cart_script_still_never_turns_data_into_markup(): void
    {
        $code = (string) preg_replace('#//[^
]*#', '', $this->cartScript());

        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', 'DOMParser'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code, $forbidden);
        }
    }

    public function test_every_text_key_of_the_new_cart_script_exists_in_both_languages(): void
    {
        preg_match_all("/\\bt\\('([a-z_]+)'/", $this->cartScript(), $m);

        foreach (array_unique($m[1]) as $key) {
            foreach (['bg', 'en'] as $locale) {
                $texts = trans('storefront.js', [], $locale) + trans('storefront.js_checkout', [], $locale) + ['cart_count_label' => trans('storefront.cart_count_label', [], $locale)];
                $this->assertArrayHasKey($key, $texts, "{$locale}: {$key}");
            }
        }
    }

    // ---- the home page --------------------------------------------------------------------------------------------------------------

    public function test_the_default_intro_comes_from_the_language_file_when_the_keys_are_empty(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<h1 class="sf-title">Welcome to Raf Shop</h1>', $html);
        $this->assertStringContainsString(__('storefront.home_intro_text'), $html);
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_a_configured_heading_and_text_are_shown_and_escaped(): void
    {
        $settings = app(SiteSettingsRepository::class);
        $settings->set(StorefrontHomeSettings::TITLE_KEY, '<script>alert(1)</script> Spring & co');
        $settings->set(StorefrontHomeSettings::TEXT_KEY, 'Fresh <b>arrivals</b> every week');

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<h1 class="sf-title">&lt;script&gt;alert(1)&lt;/script&gt; Spring &amp; co</h1>', $html, 'text, not markup');
        $this->assertStringContainsString('Fresh &lt;b&gt;arrivals&lt;/b&gt; every week', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('Welcome to Raf Shop', $html, 'the default is replaced, not appended');
        $this->assertNoActiveContent($html, 'home');
    }

    public function test_one_configured_field_leaves_the_other_at_its_default(): void
    {
        app(SiteSettingsRepository::class)->set(StorefrontHomeSettings::TITLE_KEY, 'Our shop');

        $html = $this->get('/')->getContent();

        $this->assertStringContainsString('<h1 class="sf-title">Our shop</h1>', $html);
        $this->assertStringContainsString(__('storefront.home_intro_text'), $html);
    }

    public function test_a_stored_value_longer_than_the_limit_is_cut_by_the_reader(): void
    {
        app(SiteSettingsRepository::class)->set(StorefrontHomeSettings::TITLE_KEY, str_repeat('a', 300));

        $this->assertSame(StorefrontHomeSettings::MAX_TITLE, mb_strlen((string) app(StorefrontHomeSettings::class)->title()));
    }

    public function test_the_primary_button_jumps_to_an_element_that_exists(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertMatchesRegularExpression('/<a class="sf-intro-button" href="#newest">Browse the newest<\/a>/', $html);
        $this->assertSame(1, substr_count($html, 'id="newest"'));
        $this->assertMatchesRegularExpression('/<section class="sf-listing sf-home" id="newest"/', $html);
    }

    public function test_the_first_two_cards_stay_eager_and_the_category_tiles_are_whole_links(): void
    {
        $this->assertStringContainsString("'eager' => \$loop->index < 2", (string) file_get_contents(resource_path('views/storefront/home.blade.php')), 'the LCP rule is untouched');

        $html = $this->get('/')->getContent();
        $this->assertMatchesRegularExpression('/<a class="sf-category-tile-link" href="\/product-category\/clothing">\s*<span class="sf-category-tile-name">Clothing<\/span>/', $html);
    }

    public function test_the_home_page_costs_no_more_queries_with_or_without_a_configured_intro(): void
    {
        $without = $this->countQueries('/');

        $settings = app(SiteSettingsRepository::class);
        $settings->set(StorefrontHomeSettings::TITLE_KEY, 'Our shop');
        $settings->set(StorefrontHomeSettings::TEXT_KEY, 'Some text');
        $with = $this->countQueries('/');

        fwrite(STDERR, "\n[query-count] storefront home (S3-V): {$without} queries without the intro keys, {$with} with them (before S3-V: ".self::HOME_QUERIES.")\n");

        $this->assertLessThanOrEqual(self::HOME_QUERIES, $without, 'the intro costs no query of its own');
        $this->assertLessThanOrEqual(self::HOME_QUERIES, $with);
    }

    public function test_a_category_page_costs_what_it_cost(): void
    {
        $count = $this->countQueries('/product-category/clothing');

        $this->assertLessThanOrEqual(StorefrontPageQueryCountTest::CATEGORY, $count, 'the pinned ceiling of StorefrontPageQueryCountTest for a category page');
    }

    // ---- the shared layout ----------------------------------------------------------------------------------------------------------

    public function test_the_header_cart_link_has_a_bag_icon_a_name_and_the_count_slot(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertMatchesRegularExpression('/<a class="sf-cart" href="\/cart" data-sf-cart-link>\s*<svg class="sf-cart-icon"[^>]*aria-hidden="true"[^>]*focusable="false">.*?<\/svg>\s*<span class="sf-cart-label( sf-sr-only)?">Cart<\/span>\s*<span class="sf-cart-count" data-sf-cart-count hidden><\/span>\s*<\/a>/s', $html, 'an inline svg, decorative; the word is the accessible name');
        $this->assertSame(4, substr_count($html, '<svg'), 'D2: the desktop bag, the phone hamburger and bag, the drawer close icon: a closed set, no icon library');
        $this->assertNoActiveContent($html, 'layout');
    }

    public function test_the_footer_names_the_shop_lists_the_footer_pages_and_links_home(): void
    {
        $page = app(\App\Content\PageWriter::class)->create(['title' => 'About us', 'slug' => 'about', 'body' => '<p>x</p>', 'status' => 'published']);
        $this->saveMenu('footer', [$this->menuItem('page', ['target_id' => $page->id])]); // D3: the footer is the footer menu

        $html = $this->get('/')->getContent();

        $this->assertMatchesRegularExpression('/<div class="sf-footer-brand">\s*<span class="sf-footer-name">Raf Shop<\/span>\s*<a class="sf-footer-home" href="\/">Home<\/a>\s*<\/div>/', $html);
        $this->assertMatchesRegularExpression('/<nav class="sf-footer-pages"[^>]*>\s*<ul class="sf-footer-list">\s*<li><a href="\/info\/about">About us<\/a><\/li>\s*<\/ul>\s*<\/nav>/', $html);
    }
}
