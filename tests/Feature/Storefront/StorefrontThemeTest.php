<?php

namespace Tests\Feature\Storefront;

use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Theme\ApplyStorefrontTheme;
use App\Storefront\Theme\InvalidThemeException;
use App\Storefront\Theme\ThemeRegistry;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * Stage D1 (storefront-customization-design.md 2): the theme manifest and registry, the fall back to `easyco` for an unknown key (logged once), the middleware
 * that exists on the storefront routes ONLY, and a theme that overrides ONE view while every other view falls back to resources/views.
 */
class StorefrontThemeTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        ThemeRegistry::forgetWarnings();
        $this->dir = sys_get_temp_dir().'/sf-themes-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    /** @param  array<string, mixed>  $override */
    private function manifest(string $key, array $override = [], ?string $raw = null): void
    {
        is_dir($this->dir.'/'.$key) || mkdir($this->dir.'/'.$key, 0777, true);
        $data = array_merge([
            'key' => $key, 'name' => 'Test theme', 'version' => '1.0.0', 'extends' => 'easyco', 'views' => null,
            'assets' => ['css' => 'resources/css/storefront.css', 'js' => 'resources/js/storefront.js'], 'tokens' => [], 'header' => ['rows' => ['main'], 'elements' => ['logo']], 'slots' => [],
        ], $override);
        file_put_contents($this->dir.'/'.$key.'/theme.json', $raw ?? json_encode($data));
    }

    private function registry(): ThemeRegistry
    {
        return new ThemeRegistry($this->dir);
    }

    // ---- the manifest ---------------------------------------------------------------------------------------------------------------

    public function test_the_default_theme_manifest_is_valid_and_carries_the_token_defaults(): void
    {
        $theme = (new ThemeRegistry)->get('easyco');

        $this->assertSame(['easyco', 'EasyCo', null, null], [$theme->key, $theme->name, $theme->views, $theme->extends]);
        $this->assertNull($theme->viewsPath(), 'the default theme uses resources/views as it is');
        $this->assertContains('logo', $theme->header['elements']);
        $this->assertSame([], $theme->slots);

        $defaults = AppearanceSettings::defaults();

        foreach ($theme->tokens as $key => $value) {
            $this->assertSame($defaults[$key] ?? null, $value, "the manifest's default of {$key} is the settings' default");
        }

        $this->assertCount(count(array_filter(array_keys($defaults), static fn (string $key): bool => ! str_starts_with($key, 'banner.'))), $theme->tokens);
    }

    public function test_a_valid_theme_is_read_and_its_views_folder_is_resolved(): void
    {
        mkdir($this->dir.'/shop/views', 0777, true);
        $this->manifest('shop', ['views' => 'views']);

        $theme = $this->registry()->get('shop');

        $this->assertSame('views', $theme->views);
        $this->assertSame('shop', $theme->key);
        $this->assertStringEndsWith('/shop/views', str_replace('\\', '/', (string) $theme->viewsPath()));
        $this->assertSame(['shop'], $this->registry()->keys());
        $this->assertContains('easyco', (new ThemeRegistry)->keys());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: ?string}> */
    public static function brokenManifests(): array
    {
        return [
            'an unknown top-level key' => [['evil' => 'x'], null],
            'a missing key' => [['name' => null], null],
            'a key that is not the folder name' => [['key' => 'other'], null],
            'an uppercase key' => [['key' => 'Bad'], null],
            'a name that is not a string' => [['name' => ['x']], null],
            'a version that is not x.y.z' => [['version' => 'one'], null],
            'a parent other than easyco' => [['extends' => 'other'], null],
            'a views folder that does not exist' => [['views' => 'nope'], null],
            'a views path that climbs' => [['views' => '../../etc'], null],
            'tokens that are not strings' => [['tokens' => ['a' => 1]], null],
            'a header without rows' => [['header' => ['elements' => []]], null],
            'slots that are not a list' => [['slots' => ['a' => 'b']], null],
            'not json' => [[], '{ nope'],
            'a list instead of an object' => [[], '[1,2]'],
            'bigger than 64 KB' => [[], '{"key":"big","pad":"'.'x'.'"}'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brokenManifests')]
    public function test_a_broken_manifest_is_refused(array $override, ?string $raw): void
    {
        $key = $raw !== null && str_contains($raw, '"big"') ? 'big' : 'broken';
        $override = array_map(static fn ($v) => $v, $override);
        $data = $override;

        if (($override['name'] ?? 'x') === null) {
            $this->manifest($key);
            $json = json_decode((string) file_get_contents($this->dir.'/'.$key.'/theme.json'), true);
            unset($json['name']);
            file_put_contents($this->dir.'/'.$key.'/theme.json', json_encode($json));
        } elseif ($key === 'big') {
            mkdir($this->dir.'/big');
            file_put_contents($this->dir.'/big/theme.json', '{"key":"big","pad":"'.str_repeat('x', ThemeRegistry::MAX_BYTES + 10).'"}');
        } else {
            $this->manifest($key, $data, $raw);
        }

        $this->expectException(InvalidThemeException::class);
        $this->registry()->get($key);
    }

    // ---- the active theme ------------------------------------------------------------------------------------------------------------

    public function test_the_default_theme_is_active_when_nothing_is_configured(): void
    {
        $this->assertSame('easyco', (new ThemeRegistry)->active(null)->key);
        $this->assertSame('easyco', (new ThemeRegistry)->active('')->key);
        $this->assertSame('easyco', (new ThemeRegistry)->active('  ')->key);
    }

    public function test_an_unknown_or_invalid_theme_key_falls_back_to_easyco_and_is_logged_once(): void
    {
        Log::spy();
        $this->manifest('broken', ['views' => 'nope']);
        $registry = $this->registry();
        mkdir($this->dir.'/easyco', 0777, true);
        copy(resource_path('themes/easyco/theme.json'), $this->dir.'/easyco/theme.json');

        foreach (['ghost', 'ghost', 'broken', 'broken', '../etc/passwd'] as $key) {
            $this->assertSame('easyco', $registry->active($key)->key, $key);
        }

        // One warning per unknown key (ghost, broken, the path), however many requests ask: three keys, three lines, never five.
        Log::shouldHaveReceived('warning')->times(3);
        Log::shouldHaveReceived('warning')->withArgs(static fn (string $message, array $context): bool => str_starts_with($message, 'storefront.theme_unknown') && ! array_key_exists('exception', $context) && ! array_key_exists('trace', $context))->times(3);
    }

    public function test_one_unknown_key_over_many_requests_writes_one_line(): void
    {
        Log::spy();
        $this->app->instance(ThemeRegistry::class, new ThemeRegistry);
        $this->app[SiteSettingsRepository::class]->set(AppearanceSettings::THEME_KEY, 'ghost-theme');

        foreach (range(1, 3) as $ignored) {
            Cache::forget(StorefrontAppearance::CACHE_KEY);
            $this->app->forgetScopedInstances();
            $this->get('/')->assertOk();
        }

        Log::shouldHaveReceived('warning')->once();
    }

    // ---- the middleware: the storefront only -------------------------------------------------------------------------------------------

    public function test_the_middleware_is_on_every_storefront_route_and_on_nothing_else(): void
    {
        $storefront = 0;

        foreach (Route::getRoutes() as $route) {
            $has = in_array(ApplyStorefrontTheme::class, $route->gatherMiddleware(), true);
            $isStorefront = str_starts_with((string) $route->getName(), 'storefront.');

            $this->assertSame($isStorefront, $has, $route->uri().' ('.$route->getName().')');
            $storefront += $isStorefront ? 1 : 0;
        }

        $this->assertGreaterThanOrEqual(7, $storefront);
    }

    public function test_the_middleware_is_not_global_and_not_in_the_web_or_api_groups(): void
    {
        $kernel = $this->app->make(Kernel::class);

        $this->assertNotContains(ApplyStorefrontTheme::class, $kernel->getGlobalMiddleware());

        foreach ($kernel->getMiddlewareGroups() as $group => $middleware) {
            $this->assertNotContains(ApplyStorefrontTheme::class, $middleware, "the {$group} group");
        }
    }

    // ---- a theme that overrides one view ----------------------------------------------------------------------------------------------

    private function themeWithOneView(): void
    {
        mkdir($this->dir.'/shop/views/storefront/partials', 0777, true);
        file_put_contents($this->dir.'/shop/views/storefront/partials/top-banner.blade.php', '<div class="theme-marker">THEME-OVERRIDE {{ $banner === null ? "no-banner" : "banner" }}</div>');
        $this->manifest('shop', ['views' => 'views']);
        $this->app->instance(ThemeRegistry::class, $this->registry());
        $this->app[SiteSettingsRepository::class]->set(AppearanceSettings::THEME_KEY, 'shop');
        Cache::forget(StorefrontAppearance::CACHE_KEY);
        $this->app->forgetScopedInstances();
    }

    public function test_a_theme_overrides_one_view_and_every_other_view_falls_back(): void
    {
        $this->seedPricing();
        $category = $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeProduct(1, ['slug' => 'dress', 'categories' => [$category], 'price' => '10.00', 'stock' => 3]);
        $this->themeWithOneView();

        foreach (['/', '/product-category/clothing', '/product/dress', '/cart'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('<div class="theme-marker">THEME-OVERRIDE no-banner</div>', $html, "{$url}: the theme's view is used");
            $this->assertStringContainsString('<header class="sf-header">', $html, "{$url}: the layout comes from the default views");
            $this->assertStringContainsString('<footer class="sf-footer">', $html, $url);
        }
    }

    public function test_the_admin_and_the_api_are_never_themed(): void
    {
        $this->themeWithOneView();
        $finder = app('view')->getFinder();
        $before = $finder->getPaths();

        $this->postJson('/api/shipping/quote', []);
        $this->get('/admin/login');

        $this->assertSame($before, $finder->getPaths(), 'the theme path was not prepended by an API or admin request');
        $this->assertFalse(in_array((string) realpath($this->dir.'/shop/views'), $finder->getPaths(), true));

        $this->get('/')->assertOk();
        $this->assertContains((string) realpath($this->dir.'/shop/views'), $finder->getPaths(), 'a storefront request prepends it');
        $this->assertSame((string) realpath($this->dir.'/shop/views'), $finder->getPaths()[0]);
    }

    public function test_the_default_theme_prepends_nothing(): void
    {
        $this->seedPricing();
        $finder = app('view')->getFinder();
        $before = $finder->getPaths();

        $this->get('/')->assertOk();

        $this->assertSame($before, $finder->getPaths());
    }
}
