<?php

namespace Tests\Feature\Logo;

use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\SiteLogo;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Cache\StorefrontCacheHeaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesLogoFixtures;
use Tests\Feature\Storefront\Cache\BuildsCacheShop;
use Tests\TestCase;

/**
 * LOGO-1 (site-logo.md): what the storefront prints. The head (three icon tags with an icon; byte for byte today's one SVG tag without), the shop name in EVERY page's `<title>` in the pattern the SEO
 * code already uses, the one resolver in every place that printed APP_NAME, `/favicon.ico` as a sessionless route, and the header / checkout header left exactly as they are.
 */
class SiteLogoStorefrontTest extends TestCase
{
    use BuildsCacheShop;
    use MakesLogoFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop();
        app(SiteSettingsRepository::class)->set('site.locale', 'bg');
        $this->fresh();
    }

    private function html(string $url = '/'): string
    {
        Cache::forget(StorefrontAppearance::CACHE_KEY);
        $this->fresh();

        return (string) $this->get($url)->getContent();
    }

    private function title(string $url): string
    {
        $html = $this->html($url);
        $this->assertSame(1, preg_match('#<title>(.*?)</title>#s', $html, $m), "no <title> at {$url}");

        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
    }

    private function withIcon(): void
    {
        $this->saveLogoTab(['site_icon' => $this->logoAsset($this->pngBytes(640, 640))->id]);
        $this->fresh();
    }

    // ---- the head --------------------------------------------------------------------------------------------------------------

    /** ★ no icon: the head is byte for byte today's (ONE SVG tag) */
    public function test_without_an_icon_the_head_is_todays_one_svg_tag(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('    <link rel="icon" href="'.asset('favicon.svg').'">'."\n".'    <title>', $html);
        $this->assertSame(1, substr_count($html, 'rel="icon"'));
        $this->assertStringNotContainsString('apple-touch-icon', $html);
        $this->assertStringNotContainsString('favicon.ico', $html);
        $this->assertStringNotContainsString('manifest', $html);
        $this->assertStringNotContainsString('theme-color', $html);
    }

    /** ★ with an icon: the PNG 32 with type and sizes, /favicon.ico sizes=any, and the 180 touch icon */
    public function test_with_an_icon_the_head_has_the_three_tags(): void
    {
        $this->withIcon();
        $icon = app(StorefrontAppearance::class)->siteLogo()['icon'];
        $html = $this->html();

        $this->assertStringContainsString('<link rel="icon" type="image/png" sizes="32x32" href="'.$icon['png32']['url'].'">', $html);
        $this->assertStringContainsString('<link rel="icon" href="/favicon.ico" sizes="any">', $html);
        $this->assertStringContainsString('<link rel="apple-touch-icon" href="'.$icon['png180']['url'].'">', $html);
        $this->assertStringNotContainsString('favicon.svg', $html, 'the static SVG is replaced, not added to');
        $this->assertStringNotContainsString('manifest', $html);
        $this->assertStringNotContainsString('theme-color', $html);

        // the files exist on the public disk
        foreach ([$icon['png32']['url'], $icon['png180']['url'], $icon['ico']['url']] as $url) {
            $this->assertTrue(Storage::disk('public')->exists('appearance/'.basename($url)), $url);
        }
    }

    /** ★ a damaged value is ignored: the head falls back to the static tag */
    public function test_a_damaged_compiled_value_falls_back_to_the_static_files(): void
    {
        $this->withIcon();
        app(SiteSettingsRepository::class)->set(SiteLogo::COMPILED, '{"icon":{"hash":"zz","png32":{"url":"https://evil.example/x.png","w":32,"h":32}}}');

        $html = $this->html();
        $this->assertStringContainsString('<link rel="icon" href="'.asset('favicon.svg').'">', $html);
        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertNull(app(StorefrontAppearance::class)->siteLogo());
        $this->get('/favicon.ico')->assertNotFound();
    }

    /** ★ a tampered CACHE entry (not just a tampered setting) is validated again on read */
    public function test_a_tampered_cache_entry_is_validated_again_when_read(): void
    {
        $this->withIcon();
        $payload = Cache::get(StorefrontAppearance::CACHE_KEY);
        $payload['site']['logo']['icon']['png32']['url'] = 'https://evil.example/x.png';
        $payload['site']['name'] = "evil\nname";
        Cache::forever(StorefrontAppearance::CACHE_KEY, $payload);
        $this->fresh();

        $this->assertNull(app(StorefrontAppearance::class)->siteLogo());
        $this->assertSame('Raf Shop', app(StorefrontAppearance::class)->shopName(), 'a bad name falls back to APP_NAME');
        $html = (string) $this->get('/')->getContent();
        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringContainsString('<link rel="icon" href="'.asset('favicon.svg').'">', $html);
    }

    /** the header and checkout header do not change at all: «Дизайн» keeps the header logo */
    public function test_the_header_and_the_checkout_header_are_untouched_by_the_logos(): void
    {
        $header = static fn (string $html): string => substr($html, (int) strpos($html, '<header'), (int) strpos($html, '</header>') - (int) strpos($html, '<header'));

        $before = $this->html();
        $this->saveLogoTab(['site_icon' => $this->logoAsset($this->pngBytes(640, 640))->id, 'logo_main' => $this->logoAsset($this->pngBytes(800, 200))->id, 'logo_dark' => $this->logoAsset($this->pngBytes(800, 200))->id]);
        $this->fresh();
        $after = $this->html();

        $this->assertNotSame('', $header($before));
        $this->assertSame($header($before), $header($after), 'the shop header prints the same static logo');
        $this->assertStringContainsString('images/logo.svg', $header($after));
        $this->assertStringNotContainsString('/storage/appearance/logo-', $header($after), 'logo.main is NOT in the header');

        $checkoutBefore = $this->html('/checkout');
        $this->assertStringNotContainsString('/storage/appearance/logo-', $checkoutBefore);
    }

    // ---- the shop name ---------------------------------------------------------------------------------------------------------

    /**
     * ★ the saved name is in the `<title>` of every page type, in the pattern each already has; with none saved they are exactly as before.
     *
     * @return array<string, array{0: string, 1: bool}> url => [is the home page (the name alone)]
     */
    public static function pages(): array
    {
        return [
            'home' => ['/', true],
            'product' => ['/product/alpha', false],
            'category' => ['/product-category/clothing', false],
            'info page' => ['/info/about', false],
            'cart' => ['/cart', false],
            'checkout' => ['/checkout', false],
            'search' => ['/search?q=alpha', false],
            '404' => ['/no-such-page-here', false],
            'account login' => ['/account/login', false],
        ];
    }

    /** @dataProvider-free loop: one shop, every page */
    public function test_the_name_is_in_every_page_title_and_nothing_changes_without_it(): void
    {
        $without = [];

        foreach (self::pages() as $label => [$url, $home]) {
            $title = $this->title($url);
            $without[$label] = $title;
            $home ? $this->assertSame('Raf Shop', $title, $label) : $this->assertStringEndsWith(' — Raf Shop', $title, $label);
        }

        $this->saveLogoTab(['site_name' => 'Моят магазин']);

        foreach (self::pages() as $label => [$url, $home]) {
            $title = $this->title($url);
            $home ? $this->assertSame('Моят магазин', $title, $label) : $this->assertStringEndsWith(' — Моят магазин', $title, $label);
            $this->assertStringNotContainsString('Raf Shop', $title, $label);
            // the rest of each title is what it was (the page's own words)
            $this->assertSame(str_replace('Raf Shop', 'Моят магазин', $without[$label]), $title, $label);
        }

        // removing the name brings every title back byte for byte
        $this->saveLogoTab(['site_name' => null]);

        foreach (self::pages() as $label => [$url]) {
            $this->assertSame($without[$label], $this->title($url), $label);
        }
    }

    /** ★ every place that printed APP_NAME uses the saved name: the logo alt, the footer, the home h1, JSON-LD and og:site_name */
    public function test_the_name_reaches_the_alt_the_footer_the_h1_the_json_ld_and_og(): void
    {
        $this->saveLogoTab(['site_name' => 'Acme Shop']);
        $html = $this->html();

        $this->assertStringContainsString('alt="Acme Shop"', $html, 'the logo alt');
        $this->assertStringContainsString('<span class="sf-footer-name">Acme Shop</span>', $html, 'the old footer');
        $this->assertStringContainsString('<h1 class="sf-sr">Acme Shop</h1>', $html, 'the home h1');
        $this->assertStringContainsString('"name":"Acme Shop"', $html, 'JSON-LD');
        $this->assertStringContainsString('property="og:site_name" content="Acme Shop"', $html);
        $this->assertStringNotContainsString('Raf Shop', $html);

        // markup in a name is escaped where it is printed
        $this->saveLogoTab(['site_name' => 'A <b>&</b> "B"']);
        $html = $this->html();
        $this->assertStringNotContainsString('<b>&</b>', $html);
        $this->assertStringContainsString('alt="A &lt;b&gt;&amp;&lt;/b&gt; &quot;B&quot;"', $html);
    }

    /** with no saved name every one of those is APP_NAME, as before */
    public function test_without_a_name_everything_is_app_name(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('alt="Raf Shop"', $html);
        $this->assertStringContainsString('<span class="sf-footer-name">Raf Shop</span>', $html);
        $this->assertStringContainsString('<h1 class="sf-sr">Raf Shop</h1>', $html);
        $this->assertStringContainsString('"name":"Raf Shop"', $html);
        $this->assertStringContainsString('property="og:site_name" content="Raf Shop"', $html);
    }

    // ---- /favicon.ico ---------------------------------------------------------------------------------------------------------

    /** ★ with an icon: 200 image/x-icon, the contract's headers, sessionless */
    public function test_favicon_ico_is_a_sessionless_cacheable_icon(): void
    {
        $this->withIcon();
        $this->fresh();
        $response = $this->get('/favicon.ico');

        $response->assertOk();
        $this->assertSame('image/x-icon', $response->headers->get('Content-Type'));
        $this->assertSame([], $response->headers->getCookies(), 'no Set-Cookie');
        $this->assertFalse($response->headers->has('Set-Cookie'));
        $this->assertSame('max-age=0, public, s-maxage=604800', $response->headers->get('Cache-Control'));
        $this->assertContains('all', explode(' ', (string) $response->headers->get('xkey')));

        $ico = app(StorefrontAppearance::class)->siteLogo()['icon']['ico']['url'];
        $this->assertSame(Storage::disk('public')->get('appearance/'.basename($ico)), $response->getContent(), 'the bytes of the generated .ico');
        $this->assertSame(3, unpack('vcount', substr((string) $response->getContent(), 4, 2))['count']);

        // a request that CARRIES a session cookie still gets none back
        $this->fresh();
        $again = $this->call('GET', '/favicon.ico', [], ['laravel_session' => 'x', 'XSRF-TOKEN' => 'y']);
        $this->assertSame([], $again->headers->getCookies());
    }

    /** ★ no icon: a BARE 404, empty body, not cached, no cookie */
    public function test_favicon_ico_without_an_icon_is_an_empty_uncached_404(): void
    {
        $response = $this->get('/favicon.ico');

        $response->assertNotFound();
        $this->assertSame('', (string) $response->getContent());
        $this->assertSame([], $response->headers->getCookies());
        $this->assertFalse($response->headers->has('xkey'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('s-maxage', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_missing_file_is_a_404_and_the_old_empty_file_is_gone(): void
    {
        $this->withIcon();
        $ico = app(StorefrontAppearance::class)->siteLogo()['icon']['ico']['url'];
        Storage::disk('public')->delete('appearance/'.basename($ico));
        $this->fresh();

        $this->get('/favicon.ico')->assertNotFound();
        $this->assertFileDoesNotExist(public_path('favicon.ico'), 'an empty static file would shadow the route');
        $this->assertContains('seo.favicon', StorefrontCacheHeaders::ROUTES, 'the route is one the page cache may keep');
        $this->assertSame('seo.favicon', app('router')->getRoutes()->match(\Illuminate\Http\Request::create('/favicon.ico'))->getName());
    }

    public function test_the_nginx_template_answers_favicon_ico_through_the_app_before_the_image_rule(): void
    {
        $conf = (string) file_get_contents(base_path('docker/nginx/default.conf.template'));
        $favicon = strpos($conf, 'location = /favicon.ico');
        $images = strpos($conf, 'location ~* \.(?:jpe?g|png');

        $this->assertNotFalse($favicon);
        $this->assertNotFalse($images);
        $this->assertLessThan($images, $favicon, 'before the image-extension rule');
        $this->assertMatchesRegularExpression('#location = /favicon\.ico \{\s*try_files /__through_the_app__ /index\.php\?\$query_string;\s*\}#', $conf);
    }
}
