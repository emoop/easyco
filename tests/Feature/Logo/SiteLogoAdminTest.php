<?php

namespace Tests\Feature\Logo;

use App\Settings\AdminLogo;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\ShopName;
use App\Storefront\Appearance\StorefrontAppearance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\MakesLogoFixtures;
use Tests\TestCase;

/**
 * LOGO-1 (site-logo.md): the admin panel's favicon, logos and name come from the CACHED appearance payload and fall back to today's static files when nothing is set or anything is wrong; a warm cache
 * adds no query; the login page (before auth) works the same with and without a logo.
 */
class SiteLogoAdminTest extends TestCase
{
    use BuildsShippingZones;
    use MakesLogoFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['app.name' => 'Raf Shop']);
    }

    private function allSet(): void
    {
        $this->saveLogoTab([
            'site_name' => 'Acme Shop',
            'site_icon' => $this->logoAsset($this->pngBytes(640, 640))->id,
            'logo_main' => $this->logoAsset($this->pngBytes(800, 200))->id,
        ]);
    }

    /** ★ nothing set: today's static files and APP_NAME */
    public function test_with_nothing_set_the_static_files_are_used(): void
    {
        $this->assertSame(asset('favicon.svg'), AdminLogo::favicon());
        $this->assertSame(asset('images/logo.svg'), AdminLogo::light());
        $this->assertSame(asset('images/easyco-logo-white.svg'), AdminLogo::dark());
        $this->assertSame('Raf Shop', AdminLogo::name());
        $this->assertSame('Raf Shop', ShopName::current());
    }

    /** ★ icon, logo and name set: the closures use them; the dark mode uses the main logo until a dark one is set */
    public function test_the_closures_use_the_payload(): void
    {
        $this->allSet();
        $logo = app(StorefrontAppearance::class)->siteLogo();

        $this->assertSame(asset(ltrim($logo['icon']['png32']['url'], '/')), AdminLogo::favicon());
        $this->assertSame(asset(ltrim($logo['main']['png']['url'], '/')), AdminLogo::light());
        $this->assertSame(AdminLogo::light(), AdminLogo::dark(), 'no dark logo: dark mode uses the main one');
        $this->assertSame('Acme Shop', AdminLogo::name());

        $dark = $this->logoAsset($this->pngBytes(800, 200));
        $settings = app(SiteSettingsRepository::class);
        $this->saveLogoTab(['site_name' => 'Acme Shop', 'site_icon' => (int) $settings->get(\App\Settings\SiteLogo::ICON), 'logo_main' => (int) $settings->get(\App\Settings\SiteLogo::MAIN), 'logo_dark' => $dark->id]);
        $logo = app(StorefrontAppearance::class)->siteLogo();
        $this->assertSame(asset(ltrim($logo['dark']['png']['url'], '/')), AdminLogo::dark());
        $this->assertNotSame(AdminLogo::light(), AdminLogo::dark());
    }

    /** ★ a damaged payload, a tampered cache entry and a failing cache are all "the static files" */
    public function test_a_damaged_or_failing_payload_falls_back_to_the_static_files(): void
    {
        $this->allSet();

        // a tampered cache entry
        $payload = Cache::get(StorefrontAppearance::CACHE_KEY);
        $payload['site']['logo']['main']['png']['url'] = 'https://evil.example/logo.png';
        $payload['site']['logo']['icon']['png32']['url'] = 'javascript:alert(1)';
        Cache::forever(StorefrontAppearance::CACHE_KEY, $payload);
        $this->app->forgetScopedInstances();

        $this->assertSame(asset('favicon.svg'), AdminLogo::favicon());
        $this->assertSame(asset('images/logo.svg'), AdminLogo::light());
        $this->assertSame(asset('images/easyco-logo-white.svg'), AdminLogo::dark());

        // the payload cannot be read at all: nothing throws out of the closures
        Cache::partialMock()->shouldReceive('get')->andThrow(new \RuntimeException('cache down'));
        $this->app->forgetScopedInstances();

        $this->assertSame(asset('favicon.svg'), AdminLogo::favicon());
        $this->assertSame(asset('images/logo.svg'), AdminLogo::light());
        $this->assertSame(asset('images/easyco-logo-white.svg'), AdminLogo::dark());
        $this->assertSame('Raf Shop', AdminLogo::name());
    }

    /** ★ ZERO added SELECTs with a warm cache: the four closures share the one payload read (array cache store) */
    public function test_a_warm_cache_adds_no_select(): void
    {
        $this->allSet();
        $this->app->forgetScopedInstances();
        AdminLogo::light(); // warms the request

        $selects = 0;
        DB::listen(static function ($query) use (&$selects): void {
            if (stripos(ltrim($query->sql), 'select') === 0) {
                $selects++;
            }
        });

        AdminLogo::favicon();
        AdminLogo::light();
        AdminLogo::dark();
        AdminLogo::name();
        ShopName::current();

        $this->assertSame(0, $selects, 'the closures read the cached payload only');

        // a NEW request (fresh scoped instance), cache still warm: still no SELECT
        $this->app->forgetScopedInstances();
        $selects = 0;
        AdminLogo::favicon();
        AdminLogo::light();
        AdminLogo::dark();
        AdminLogo::name();

        $this->assertSame(0, $selects, 'no SELECT on a warm cache');
    }

    /** a cold cache costs the one existing read (the appearance `getMany`), never more than one query for the four closures */
    public function test_a_cold_cache_costs_one_read_shared_by_all_four(): void
    {
        $this->allSet();
        Cache::forget(StorefrontAppearance::CACHE_KEY);
        $this->app->forgetScopedInstances();

        $selects = 0;
        DB::listen(static function ($query) use (&$selects): void {
            stripos(ltrim($query->sql), 'select') === 0 && $selects++;
        });

        AdminLogo::favicon();
        AdminLogo::light();
        AdminLogo::dark();
        AdminLogo::name();

        $this->assertLessThanOrEqual(1, $selects, 'the one getMany, then the cache');
    }

    /** ★ the login page (before auth) renders with and without a logo, with the same number of queries */
    public function test_the_login_page_renders_with_and_without_a_logo(): void
    {
        $count = function (): array {
            Cache::forget(StorefrontAppearance::CACHE_KEY);
            $this->app->forgetScopedInstances();
            $this->get('/admin/login')->assertOk(); // warms the payload

            $this->app->forgetScopedInstances();
            $queries = 0;
            DB::listen(static function () use (&$queries): void {
                $queries++;
            });
            $response = $this->get('/admin/login')->assertOk();

            return [$queries, (string) $response->getContent()];
        };

        [$withoutQueries, $without] = $count();
        $this->assertStringContainsString('images/logo.svg', $without);
        $this->assertStringContainsString('favicon.svg', $without);

        $this->allSet();
        [$withQueries, $with] = $count();
        $logo = app(StorefrontAppearance::class)->siteLogo();

        $this->assertStringContainsString(basename($logo['main']['png']['url']), $with, 'the login page shows the logo');
        $this->assertStringContainsString(basename($logo['icon']['png32']['url']), $with, 'and the icon in the tab');
        $this->assertStringContainsString('Acme Shop', $with, 'and the name');
        $this->assertSame($withoutQueries, $withQueries, 'the logo adds no query to the login page');
    }

    /** the admin's browser tab title carries the shop name, and a logged-in page shows the logo too */
    public function test_a_logged_in_admin_page_has_the_name_in_the_tab_and_the_logo(): void
    {
        $this->allSet();
        $this->actingAsStaff('Administrator');
        $html = (string) $this->get('/admin')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<title>[^<]*Acme Shop[^<]*</title>#', $html);
        $logo = app(StorefrontAppearance::class)->siteLogo();
        $this->assertStringContainsString(basename($logo['main']['png']['url']), $html);
        $this->assertStringContainsString(basename($logo['icon']['png32']['url']), $html);
    }
}
