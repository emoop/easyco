<?php

namespace Tests\Feature\Storefront;

use App\Content\PageWriter;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\AppearanceCompiler;
use App\Storefront\Appearance\StorefrontAppearance;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Stage D1 (storefront-customization-design.md 3): the colour / font / radius validation, the compiler (values in, CSS text out), the compiled token
 * stylesheet's link on the page, the top banner, and the ONE read of the appearance (a cold page pays one query, a warm page none).
 */
class StorefrontAppearanceTest extends TestCase
{
    use AssertsSafeHtml;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        config(['app.name' => 'Raf Shop']);
        Storage::fake('public');
        $this->seedPricing();
        $category = $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeProduct(1, ['slug' => 'dress', 'categories' => [$category], 'price' => '10.00', 'stock' => 3, 'name' => 'Summer dress']);
    }

    private function settings(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    /** The next request starts from the database again: the reader's cache entry and the scoped instances are dropped. */
    private function fresh(): void
    {
        Cache::forget(StorefrontAppearance::CACHE_KEY);
        $this->app->forgetScopedInstances();
    }

    private function bannerOn(array $extra = []): void
    {
        $this->settings()->set(AppearanceSettings::BANNER_ENABLED, '1');
        $this->settings()->set(AppearanceSettings::BANNER_TEXT, $extra['text'] ?? 'Free delivery over 100 EUR');

        foreach (['url' => AppearanceSettings::BANNER_URL, 'label' => AppearanceSettings::BANNER_LINK_LABEL, 'starts' => AppearanceSettings::BANNER_STARTS_AT, 'ends' => AppearanceSettings::BANNER_ENDS_AT] as $field => $key) {
            isset($extra[$field]) ? $this->settings()->set($key, $extra[$field]) : $this->settings()->forget($key);
        }

        $this->fresh();
    }

    // ---- validation ------------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: mixed, 1: bool}> */
    public static function colours(): array
    {
        return [
            'a name' => ['red', false],
            'short hex' => ['#fff', false],
            'a rule break-out' => ['#ffff;}', false],
            'a url' => ['url(x)', false],
            'not hex digits' => ['#12345g', false],
            'no hash' => ['1a2b3c', false],
            'with a space' => ['#1a2b3c ', false],
            'a number' => [123456, false],
            'null' => [null, false],
            'mixed case' => ['#1a2B3c', true],
            'black' => ['#000000', true],
        ];
    }

    #[DataProvider('colours')]
    public function test_only_a_six_digit_hex_colour_is_a_colour(mixed $value, bool $valid): void
    {
        $this->assertSame($valid, AppearanceSettings::isHex($value));
        $this->assertSame($valid, AppearanceSettings::isValid('appearance.color.primary', $value));

        if (! $valid) {
            $this->expectException(InvalidArgumentException::class);
            app(AppearanceCompiler::class)->css(['appearance.color.primary' => $value]);
        } else {
            $this->assertStringContainsString('--sf-primary: '.strtolower($value).';', (string) app(AppearanceCompiler::class)->css(['appearance.color.primary' => $value]));
        }
    }

    public function test_fonts_radius_and_button_come_from_closed_lists(): void
    {
        $compiler = app(AppearanceCompiler::class);

        foreach ([[AppearanceSettings::FONT_BODY, 'Comic Sans'], [AppearanceSettings::FONT_BODY, 'same'], [AppearanceSettings::FONT_HEADING, "x'; } body {"], [AppearanceSettings::RADIUS, 'xl'], [AppearanceSettings::BUTTON, 'ghost'], ['appearance.nope', 'x']] as [$key, $value]) {
            try {
                $compiler->css([$key => $value]);
            } catch (InvalidArgumentException) {
                continue;
            }

            $this->fail("{$key} = {$value} was accepted");
        }

        $this->assertNotNull($compiler->css([AppearanceSettings::FONT_HEADING => 'same', AppearanceSettings::FONT_BODY => 'lora']));
    }

    public function test_the_banner_link_rule_is_the_pages_rule_plus_https_only(): void
    {
        foreach (['/sale', '/info/delivery?x=1', 'https://example.com/a'] as $ok) {
            $this->assertTrue(AppearanceSettings::isSafeBannerUrl($ok), $ok);
        }

        foreach (['javascript:alert(1)', 'data:text/html,x', '//evil.test/x', 'http://example.com', 'ftp://x.test', 'sale', '/a b', "/x\\y", ''] as $bad) {
            $this->assertFalse(AppearanceSettings::isSafeBannerUrl($bad), $bad);
        }
    }

    // ---- the compiler ----------------------------------------------------------------------------------------------------------------

    public function test_an_untouched_shop_compiles_to_nothing(): void
    {
        $compiler = app(AppearanceCompiler::class);

        $this->assertNull($compiler->css([]));
        $this->assertNull($compiler->compile([]));
        $this->assertSame([], Storage::disk('public')->files('appearance'), 'no file, no link: the fallbacks in storefront.css are today\'s look');
    }

    public function test_the_stylesheet_holds_exactly_the_saved_tokens_and_the_chosen_fonts_only(): void
    {
        $css = (string) app(AppearanceCompiler::class)->css([
            'appearance.color.primary' => '#1A2B3C',
            'appearance.color.header_bg' => '#112233',
            AppearanceSettings::FONT_BODY => 'inter',
            AppearanceSettings::FONT_HEADING => 'lora',
            AppearanceSettings::RADIUS => 'lg',
            AppearanceSettings::BUTTON => 'outline',
        ]);

        $this->assertStringStartsWith(":root {\n", $css);
        foreach (['--sf-primary: #1a2b3c;', '--sf-header-bg: #112233;', '--sf-r-sm: 9px;', '--sf-r-md: 12px;', '--sf-r-lg: 18px;', '--sf-btn-bg: transparent;', '--sf-btn-bw: 2px;'] as $needle) {
            $this->assertStringContainsString($needle, $css);
        }
        $this->assertMatchesRegularExpression("/--sf-font-body: 'Inter Variable', ui-sans-serif/", $css);
        $this->assertMatchesRegularExpression("/--sf-font-heading: 'Lora Variable', Georgia/", $css);

        // Only the tokens that were saved: nothing else of the palette.
        foreach (['--sf-text:', '--sf-bg:', '--sf-muted:', '--sf-accent:', '--sf-border:', '--sf-footer-bg:', '--sf-banner-bg:'] as $absent) {
            $this->assertStringNotContainsString($absent, $css);
        }

        // Only the two chosen families, latin + cyrillic each: four @font-face blocks, no Manrope.
        $this->assertSame(4, substr_count($css, '@font-face'));
        $this->assertStringContainsString('/fonts/storefront/inter/inter-latin-wght-normal.woff2', $css);
        $this->assertStringContainsString('/fonts/storefront/lora/lora-cyrillic-wght-normal.woff2', $css);
        $this->assertStringNotContainsString('manrope', strtolower($css));
        $this->assertSame(4, substr_count($css, 'font-display: swap;'));
        $this->assertSame(4, substr_count($css, 'unicode-range: U+'));
    }

    public function test_the_hover_colour_of_the_primary_is_the_one_of_today_for_the_default_primary_and_follows_a_new_one(): void
    {
        $css = (string) app(AppearanceCompiler::class)->css(['appearance.color.primary' => '#171717', 'appearance.color.accent' => '#00aa00']);

        $this->assertStringNotContainsString('--sf-primary-hover', $css, 'a default primary is not emitted, so the fallback #404040 of storefront.css (today) applies');
        $this->assertMatchesRegularExpression('/--sf-primary-hover: #[0-9a-f]{6};/', (string) app(AppearanceCompiler::class)->css(['appearance.color.primary' => '#0000ff']));
    }

    public function test_the_pill_radius_rounds_only_the_buttons(): void
    {
        $css = (string) app(AppearanceCompiler::class)->css([AppearanceSettings::RADIUS => 'pill']);

        $this->assertStringContainsString('--sf-r-btn: 9999px;', $css);
        $this->assertStringNotContainsString('--sf-r-md', $css);
    }

    public function test_the_file_is_named_by_its_content_the_previous_one_is_kept_and_older_ones_go(): void
    {
        $compiler = app(AppearanceCompiler::class);
        $disk = Storage::disk('public');

        $one = $compiler->compile(['appearance.color.primary' => '#111111']);
        $two = $compiler->compile(['appearance.color.primary' => '#222222'], $one['hash']);
        $three = $compiler->compile(['appearance.color.primary' => '#333333'], $two['hash']);

        $this->assertNotSame($one['hash'], $two['hash'], 'the name follows the content');
        $this->assertTrue($disk->exists('appearance/tokens-'.$three['hash'].'.css'));
        $this->assertTrue($disk->exists('appearance/tokens-'.$two['hash'].'.css'), 'the previous file stays for pages cached a moment ago');
        $this->assertFalse($disk->exists('appearance/tokens-'.$one['hash'].'.css'), 'older ones are removed');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $three['hash']);
        $this->assertSame(['hash' => $three['hash'], 'fonts' => [], 'body' => null], $three);
        $this->assertSame($two['hash'], $compiler->compile(['appearance.color.primary' => '#222222'], $two['hash'])['hash'], 'the same content, the same name');
    }

    public function test_compiled_fonts_name_the_body_font_for_the_preload(): void
    {
        $compiled = app(AppearanceCompiler::class)->compile([AppearanceSettings::FONT_BODY => 'manrope', AppearanceSettings::FONT_HEADING => 'lora']);

        $this->assertSame(['manrope', 'lora'], $compiled['fonts']);
        $this->assertSame('manrope', $compiled['body']);
        $this->assertSame(['lora'], app(AppearanceCompiler::class)->compile([AppearanceSettings::FONT_HEADING => 'lora'])['fonts']);
        $this->assertNull(app(AppearanceCompiler::class)->compile([AppearanceSettings::FONT_HEADING => 'lora'])['body']);
    }

    public function test_the_font_files_and_their_licences_are_in_the_repository(): void
    {
        foreach (['inter', 'manrope', 'lora'] as $family) {
            foreach (['latin', 'cyrillic'] as $subset) {
                $this->assertFileExists(public_path("fonts/storefront/{$family}/{$family}-{$subset}-wght-normal.woff2"));
            }

            $this->assertStringContainsString('SIL OPEN FONT LICENSE', strtoupper((string) file_get_contents(public_path("fonts/storefront/{$family}/OFL-LICENSE.txt"))));
        }
    }

    // ---- the page: defaults change nothing ----------------------------------------------------------------------------------------------

    public function test_with_the_defaults_no_page_links_a_token_stylesheet_or_a_font_or_shows_a_banner(): void
    {
        foreach (['/', '/product-category/clothing', '/product/dress', '/cart', '/checkout'] as $url) {
            $this->fresh();
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('/storage/appearance/', $html, $url);
            $this->assertStringNotContainsString('rel="preload"', $html, $url);
            $this->assertStringNotContainsString('sf-banner', $html, $url);
            $this->assertStringNotContainsString('<style', $html, $url);
        }
    }

    public function test_a_compiled_value_links_the_stylesheet_after_the_main_one_and_preloads_only_the_body_font(): void
    {
        $compiled = app(AppearanceCompiler::class)->compile([AppearanceSettings::FONT_BODY => 'inter', AppearanceSettings::FONT_HEADING => 'lora', 'appearance.color.primary' => '#123456']);
        $this->settings()->set(AppearanceSettings::COMPILED_KEY, json_encode($compiled));
        $this->fresh();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<link rel="stylesheet" href="/storage/appearance/tokens-'.$compiled['hash'].'.css">'));
        $this->assertSame(1, substr_count($html, 'rel="preload"'), 'one preload');
        $this->assertStringContainsString('<link rel="preload" as="font" type="font/woff2" href="/fonts/storefront/inter/inter-latin-wght-normal.woff2" crossorigin>', $html);
        $this->assertStringNotContainsString('lora-latin', $html, 'only the BODY font is preloaded');
        $this->assertLessThan(strpos($html, '/storage/appearance/'), strpos($html, 'rel="preload"'));
    }

    public function test_a_hand_edited_compiled_value_cannot_inject_a_link(): void
    {
        $this->settings()->set(AppearanceSettings::COMPILED_KEY, json_encode(['hash' => '"><script>alert(1)</script>', 'fonts' => ['../../etc'], 'body' => 'x']));
        $this->fresh();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('/storage/appearance/', $html);

        $this->settings()->set(AppearanceSettings::COMPILED_KEY, 'not json');
        $this->fresh();
        $this->get('/')->assertOk();
    }

    // ---- the banner ------------------------------------------------------------------------------------------------------------------

    public function test_the_banner_is_absent_when_off_and_shows_escaped_text_when_on(): void
    {
        $this->settings()->set(AppearanceSettings::BANNER_TEXT, 'Hidden while off');
        $this->fresh();
        $this->assertStringNotContainsString('sf-banner', $this->get('/')->getContent());

        $this->bannerOn(['text' => '<script>alert(1)</script> Sale & more']);
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<span class="sf-banner-text">&lt;script&gt;alert(1)&lt;/script&gt; Sale &amp; more</span>', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertLessThan(strpos($html, '<header class="sf-header sf-hdr'), strpos($html, 'sf-banner'), 'above the header');
        $this->assertNoActiveContent($html, 'banner');
    }

    public function test_the_whole_bar_is_the_link_without_a_link_text_and_text_plus_a_link_with_one(): void
    {
        $this->bannerOn(['text' => 'Free delivery', 'url' => '/product-category/clothing']);
        $whole = $this->get('/')->getContent();
        $this->assertMatchesRegularExpression('/<a class="sf-banner-inner sf-banner-bar-link" href="\/product-category\/clothing"><span class="sf-banner-text">Free delivery<\/span><\/a>/', $whole);

        $this->bannerOn(['text' => 'Free delivery', 'url' => 'https://example.com/promo', 'label' => 'Read more']);
        $split = $this->get('/')->getContent();
        $this->assertMatchesRegularExpression('/<p class="sf-banner-inner">\s*<span class="sf-banner-text">Free delivery<\/span>\s*<a class="sf-banner-link" href="https:\/\/example.com\/promo">Read more<\/a>\s*<\/p>/', $split);

        $this->bannerOn(['text' => 'Only text']);
        $this->assertStringNotContainsString('<a class="sf-banner', $this->get('/')->getContent());
    }

    #[DataProvider('unsafeLinks')]
    public function test_an_unsafe_link_in_the_database_never_reaches_the_page(string $url): void
    {
        $this->bannerOn(['text' => 'Sale', 'url' => $url, 'label' => 'More']);
        $html = $this->get('/')->getContent();

        $this->assertStringContainsString('<span class="sf-banner-text">Sale</span>', $html, 'the text still shows');
        $this->assertStringNotContainsString('sf-banner-link', $html);
        $this->assertStringNotContainsString('sf-banner-bar-link', $html);
    }

    /** @return array<string, array{0: string}> */
    public static function unsafeLinks(): array
    {
        return ['javascript' => ['javascript:alert(1)'], 'data' => ['data:text/html,<b>x</b>'], 'protocol-relative' => ['//evil.test/x'], 'plain http' => ['http://example.com/x']];
    }

    public function test_the_dates_are_respected_in_the_store_time_zone(): void
    {
        $this->settings()->set('site.timezone', 'Europe/Sofia');
        $this->bannerOn(['text' => 'Dated', 'starts' => '2026-06-01 10:00', 'ends' => '2026-06-30 18:00']);
        $reader = app(StorefrontAppearance::class);
        $at = static fn (string $utc): DateTimeImmutable => new DateTimeImmutable($utc.' UTC');

        $this->assertNull($reader->banner($at('2026-06-01 06:59')), 'before: 09:59 in Sofia (UTC+3)');
        $this->assertNotNull($reader->banner($at('2026-06-01 07:00')), 'the start: 10:00 in Sofia');
        $this->assertNotNull($reader->banner($at('2026-06-15 12:00')), 'inside');
        $this->assertNotNull($reader->banner($at('2026-06-30 14:59')));
        $this->assertNull($reader->banner($at('2026-06-30 15:00')), 'the end: 18:00 in Sofia');
        $this->assertNull($reader->banner($at('2026-07-15 00:00')), 'after');
    }

    public function test_a_banner_whose_window_is_over_is_not_on_the_page(): void
    {
        $this->bannerOn(['text' => 'Old news', 'starts' => '2001-01-01 00:00', 'ends' => '2001-01-02 00:00']);
        $this->assertStringNotContainsString('sf-banner', $this->get('/')->getContent());

        $this->bannerOn(['text' => 'Future news', 'starts' => '2999-01-01 00:00']);
        $this->assertStringNotContainsString('sf-banner', $this->get('/')->getContent());

        $this->bannerOn(['text' => 'Now', 'starts' => '2001-01-01 00:00', 'ends' => '2999-01-01 00:00']);
        $this->assertStringContainsString('sf-banner-text', $this->get('/')->getContent());
    }

    public function test_a_banner_text_that_is_too_long_or_not_plain_is_not_shown(): void
    {
        $this->bannerOn(['text' => str_repeat('a', AppearanceSettings::BANNER_TEXT_MAX + 1)]);
        $this->assertStringNotContainsString('sf-banner', $this->get('/')->getContent());

        $this->bannerOn(['text' => "Sale\u{202E}evil"]);
        $this->assertStringNotContainsString('sf-banner', $this->get('/')->getContent());
    }

    // ---- ONE read ---------------------------------------------------------------------------------------------------------------------

    /** @return list<string> the SQL of every query of `site_settings` that asks for an appearance key */
    private function appearanceQueries(string $url): array
    {
        foreach (Route::getRoutes() as $route) {
            $route->flushController();
        }

        $this->app->forgetScopedInstances();
        $found = [];
        DB::listen(function ($query) use (&$found): void {
            if (str_contains($query->sql, 'site_settings') && in_array(AppearanceSettings::COMPILED_KEY, $query->bindings, true)) {
                $found[] = $query->sql;
            }
        });

        $this->get($url)->assertOk();

        return $found;
    }

    /** @return list<string> the SQL of EVERY `site_settings` query of the request, whatever the key */
    private function settingsQueries(string $url): array
    {
        foreach (Route::getRoutes() as $route) {
            $route->flushController();
        }

        $this->app->forgetScopedInstances();
        $found = [];
        DB::listen(function ($query) use (&$found): void {
            if (str_contains($query->sql, 'site_settings')) {
                $found[] = $query->sql.' | '.json_encode($query->bindings);
            }
        });

        $this->get($url)->assertOk();

        return $found;
    }

    public function test_a_cold_page_pays_exactly_one_settings_query_more_than_a_warm_one_and_never_a_second(): void
    {
        foreach (['/', '/product/dress', '/cart', '/checkout'] as $url) {
            Cache::forget(StorefrontAppearance::CACHE_KEY);
            $cold = $this->settingsQueries($url);
            $warm = $this->settingsQueries($url);

            $this->assertCount(count($warm) + 1, $cold, "{$url}: the appearance read is the ONE extra query of a cold page (any second settings query would show here)");
            $this->assertCount(1, array_filter($cold, static fn (string $sql): bool => str_contains($sql, AppearanceSettings::COMPILED_KEY)));
        }
    }

    public function test_every_appearance_key_is_read_with_one_query_cold_and_none_warm(): void
    {
        Cache::forget(StorefrontAppearance::CACHE_KEY);

        $cold = $this->appearanceQueries('/');
        $this->assertCount(1, $cold, 'ONE getMany for the compiled value, the theme and every banner key');
        $this->assertStringContainsString('in (', strtolower($cold[0]));

        $warm = $this->appearanceQueries('/product-category/clothing');
        $this->assertSame([], $warm, 'the cache entry serves every later page');
        $this->assertTrue(Cache::has(StorefrontAppearance::CACHE_KEY));
    }

    public function test_the_extra_query_is_the_same_one_on_every_page_and_only_cold(): void
    {
        $baseline = [];

        foreach (['/', '/product-category/clothing', '/product/dress', '/cart', '/checkout'] as $url) {
            Cache::forget(StorefrontAppearance::CACHE_KEY);
            $this->assertCount(1, $this->appearanceQueries($url), "{$url}: cold = ONE appearance query");
            $this->assertCount(0, $this->appearanceQueries($url), "{$url}: warm = none");
            $baseline[$url] = true;
        }

        $this->assertCount(5, $baseline);
    }

    public function test_a_page_with_the_cache_entry_saved_by_the_admin_shows_it_without_asking_the_database(): void
    {
        $this->settings()->set(AppearanceSettings::BANNER_ENABLED, '1');
        $this->settings()->set(AppearanceSettings::BANNER_TEXT, 'From the database');
        Cache::forever(StorefrontAppearance::CACHE_KEY, StorefrontAppearance::build([AppearanceSettings::BANNER_ENABLED => '1', AppearanceSettings::BANNER_TEXT => 'From the cache']));
        $this->app->forgetScopedInstances();

        $this->assertSame([], $this->appearanceQueries('/'));
        $this->assertStringContainsString('From the cache', $this->get('/')->getContent());
    }

    public function test_refresh_replaces_the_cache_entry_with_what_is_stored(): void
    {
        $this->settings()->set(AppearanceSettings::BANNER_ENABLED, '1');
        $this->settings()->set(AppearanceSettings::BANNER_TEXT, 'First');
        $reader = app(StorefrontAppearance::class);
        $reader->refresh();
        $this->assertSame('First', Cache::get(StorefrontAppearance::CACHE_KEY)['banner']['text']);

        $this->settings()->set(AppearanceSettings::BANNER_TEXT, 'Second');
        $reader->refresh();
        $this->assertSame('Second', Cache::get(StorefrontAppearance::CACHE_KEY)['banner']['text']);
    }

    // ---- no inline style or script anywhere -----------------------------------------------------------------------------------------

    public function test_no_storefront_page_has_an_inline_style_element_or_attribute_or_script_even_with_everything_on(): void
    {
        $compiled = app(AppearanceCompiler::class)->compile([AppearanceSettings::FONT_BODY => 'inter', 'appearance.color.primary' => '#123456']);
        $this->settings()->set(AppearanceSettings::COMPILED_KEY, json_encode($compiled));
        app(PageWriter::class)->create(['title' => 'About', 'slug' => 'about', 'body' => '<p>Text</p>', 'status' => 'published']);
        $this->bannerOn(['text' => 'Sale', 'url' => '/sale', 'label' => 'Go']);

        foreach (['/', '/product-category/clothing', '/product/dress', '/cart', '/checkout', '/info/about'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertDoesNotMatchRegularExpression('/<style[\s>]/i', $html, "{$url}: no inline <style>");
            $this->assertDoesNotMatchRegularExpression('/<script/i', $html, "{$url}: no inline <script>");
            $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html, "{$url}: no style attribute");
            // (the checkout is a form by nature: AssertsSafeHtml refuses <form>, so the page is judged by the three checks above)
            if ($url !== '/checkout') {
                $this->assertNoActiveContent($html, $url);
            }
        }
    }
}
