<?php

namespace Tests\Feature\Storefront;

use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\PageDesign\PageDesign;
use App\Storefront\PageDesign\PageDesignStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * CD-1a: «Каталог» on the storefront — the PUBLISHED number of cards per page is the default of the category listing (the URL's `per_page` still decides, from the closed list), the columns are two CSS
 * variables that exist only when they differ from the defaults, the numbered pages keep their markup (plus the ellipsis) and a shop that never touched the section prints what it printed before.
 */
class CatalogListingTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    private int $category;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;

        config(['app.url' => 'https://shop.test', 'app.name' => 'Raf Shop']);
        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();
        $this->category = $this->makeCategory(1, 'Clothing', 'clothing');
    }

    private function products(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->makeProduct($i, ['name' => "Item {$i}", 'slug' => "item-{$i}", 'categories' => [$this->category], 'price' => '10.00', 'stock' => 1, 'timeline' => sprintf('2026-01-01 10:%02d:%02d', intdiv($i, 60), $i % 60)]);
        }
    }

    /** @param  array<string, string>  $values */
    private function publish(array $values): void
    {
        [$clean, $errors] = PageDesign::validate(PageDesign::CATALOG, $values, false);
        $this->assertSame([], $errors);

        PageDesignStore::publish(PageDesign::CATALOG, $clean);
    }

    private function cards(string $url): int
    {
        return substr_count($this->get($url)->assertOk()->getContent(), 'class="sf-card"');
    }

    private function css(): ?string
    {
        $compiled = json_decode((string) app(SiteSettingsRepository::class)->get(AppearanceSettings::COMPILED_KEY), true);

        return is_array($compiled) ? Storage::disk('public')->get('appearance/tokens-'.$compiled['hash'].'.css') : null;
    }

    // ---- a shop that never touched the section ----------------------------------------------------------------------------------------

    public function test_with_the_defaults_the_listing_is_what_it_was(): void
    {
        $this->products(30);
        $html = $this->get('/product-category/clothing')->assertOk()->getContent();

        $this->assertSame(24, substr_count($html, 'class="sf-card"'), 'the default size is today\'s 24');
        $this->assertStringContainsString('<div class="sf-grid">', $html);
        $this->assertStringNotContainsString('sf-cat-', $html);
        $this->assertStringNotContainsString('rel="stylesheet" href="/storage/appearance/tokens-', $html, 'no token stylesheet for a shop that changed nothing');
        $this->assertNull($this->css());
        $this->assertSame([], Storage::disk('public')->files('appearance'));
        $this->assertStringContainsString('<a href="/product-category/clothing?page=2">2</a>', $html);
        $this->assertStringNotContainsString('per_page', $html);

        // CD-1a.2: 20 is a member of the list; the default is 24, so the links keep per_page=20
        $twenty = $this->get('/product-category/clothing?per_page=20')->assertOk()->getContent();
        $this->assertSame(20, substr_count($twenty, 'class="sf-card"'));
        $this->assertStringContainsString('href="/product-category/clothing?page=2&amp;per_page=20"', $twenty);
        $this->get('/product-category/clothing?per_page=13')->assertNotFound();
        $this->assertStringContainsString('<link rel="canonical" href="https://shop.test/product-category/clothing">', $html);
        $this->assertMatchesRegularExpression('#<li class="sf-pagination-page">\s*<span aria-current="page">1</span>\s*</li>#', $html);
        $this->assertStringNotContainsString('sf-pagination-ellipsis', $html, 'two pages: no ellipsis');
    }

    public function test_publishing_the_defaults_stores_nothing_and_emits_nothing(): void
    {
        $this->products(3);
        $this->publish(PageDesign::defaults(PageDesign::CATALOG));

        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::CATALOG_DESIGN_KEY));
        $this->assertNull($this->css());
        $this->assertSame([], Storage::disk('public')->files('appearance'));
    }

    // ---- the published size ---------------------------------------------------------------------------------------------------------------

    public function test_every_size_of_the_closed_list_gives_that_many_cards(): void
    {
        $this->products(75);

        foreach ([12, 20, 24, 36, 48, 60, 72] as $size) {
            $this->publish(['per_page' => (string) $size]);
            $this->assertSame($size, $this->cards('/product-category/clothing'), "published {$size}");
        }

        $this->assertSame(3, $this->cards('/product-category/clothing?page=2'), 'the last page of 72 holds the rest');
    }

    public function test_the_url_decides_when_it_has_a_size_and_the_links_keep_it(): void
    {
        $this->products(40);
        $this->publish(['per_page' => '12']);

        $this->assertSame(12, $this->cards('/product-category/clothing'));
        $this->assertSame(36, $this->cards('/product-category/clothing?per_page=36'), 'a member of the closed list that is not the published one');
        $this->get('/product-category/clothing?per_page=100')->assertNotFound();
        $this->get('/product-category/clothing?per_page=13')->assertNotFound();

        $default = $this->get('/product-category/clothing')->getContent();
        $this->assertStringContainsString('<a href="/product-category/clothing?page=2">2</a>', $default, 'the published size is the default: no per_page in the links');
        $this->assertStringNotContainsString('per_page', $default);

        $other = $this->get('/product-category/clothing?per_page=24')->getContent();
        $this->assertStringContainsString('href="/product-category/clothing?page=2&amp;per_page=24"', $other, 'a size that is not the published one is kept');

        $this->get('/product-category/clothing?page=5')->assertNotFound();
        $this->assertSame(4, $this->cards('/product-category/clothing?page=4'), '40 products at 12 per page: the last page holds 4');
    }

    public function test_a_draft_is_never_read(): void
    {
        $this->products(30);
        [$clean] = PageDesign::validate(PageDesign::CATALOG, ['per_page' => '12', 'cols' => '5', 'cols_phone' => '1'], false);
        PageDesignStore::saveDraft(PageDesign::CATALOG, $clean);

        $this->assertNotNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::CATALOG_DESIGN_DRAFT_KEY));
        $this->assertSame(24, $this->cards('/product-category/clothing'), 'the draft does not change the size');
        $this->assertNull($this->css(), 'nor the stylesheet');
    }

    public function test_a_stored_value_that_does_not_validate_is_the_default(): void
    {
        $this->products(30);
        app(SiteSettingsRepository::class)->set(AppearanceSettings::CATALOG_DESIGN_KEY, '{"per_page":"7"}');
        app(\App\Storefront\Appearance\StorefrontAppearance::class)->refresh();

        $this->assertSame(24, $this->cards('/product-category/clothing'));
    }

    // ---- the columns ----------------------------------------------------------------------------------------------------------------------

    public function test_the_columns_are_two_variables_and_only_when_they_differ(): void
    {
        $this->products(3);

        $this->publish(['cols' => '5', 'cols_phone' => '1']);
        $css = (string) $this->css();
        $this->assertStringContainsString('--sf-cat-cols: 5;', $css);
        $this->assertStringContainsString('--sf-cat-cols-phone: 1;', $css);

        $this->publish(['cols' => '3']);
        $css = (string) $this->css();
        $this->assertStringContainsString('--sf-cat-cols: 3;', $css);
        $this->assertStringNotContainsString('--sf-cat-cols-phone', $css);

        $this->publish(['per_page' => '48']);
        $this->assertStringNotContainsString('--sf-cat-', (string) $this->css(), 'the size is not a variable');

        $this->publish(['cols' => '4', 'cols_phone' => '2', 'per_page' => '24']);
        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::CATALOG_DESIGN_KEY), 'back to the defaults: the key is forgotten');
    }

    // ---- the numbered pages ---------------------------------------------------------------------------------------------------------------

    public function test_the_numbered_pages_mark_the_current_one_and_use_an_ellipsis_for_a_gap(): void
    {
        $this->products(130);
        $this->publish(['per_page' => '12']);

        $html = $this->get('/product-category/clothing?page=6')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<span aria-current="page">'), 'one current page');
        $this->assertStringContainsString('<span aria-current="page">6</span>', $html);
        $this->assertSame(2, substr_count($html, '<li class="sf-pagination-ellipsis">…</li>'));
        $this->assertStringContainsString('<a href="/product-category/clothing">1</a>', $html, 'the first page has no ?page=');
        $this->assertStringContainsString('<a href="/product-category/clothing?page=4">4</a>', $html);
        $this->assertStringContainsString('<a href="/product-category/clothing?page=8">8</a>', $html);
        $this->assertStringContainsString('<a href="/product-category/clothing?page=11">11</a>', $html, 'the last page');
        $this->assertStringNotContainsString('?page=2"', $html, 'page 2 is inside the gap');
        $this->assertStringNotContainsString('?page=9"', $html, 'page 9 is inside the gap');
        $this->assertStringNotContainsString('?page=10"', $html, 'page 10 is inside the gap');
        $this->assertStringContainsString('rel="prev" href="/product-category/clothing?page=5"', $html);
        $this->assertStringContainsString('rel="next" href="/product-category/clothing?page=7"', $html);
        $this->assertStringContainsString('Page 6 of 11', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://shop.test/product-category/clothing?page=6">', $html, 'the canonical is as before');
    }

    public function test_the_numbered_links_keep_the_sort(): void
    {
        $this->products(60);
        $this->publish(['per_page' => '12']);

        $html = $this->get('/product-category/clothing?page=2&sort=name_asc')->assertOk()->getContent();

        $this->assertStringContainsString('<a href="/product-category/clothing?sort=name_asc">1</a>', $html);
        $this->assertStringContainsString('<a href="/product-category/clothing?page=3&amp;sort=name_asc">3</a>', $html);
    }

    // ---- everything else stays ------------------------------------------------------------------------------------------------------------

    public function test_the_home_page_is_not_touched_by_the_section(): void
    {
        $this->products(15);
        $before = $this->get('/')->assertOk()->getContent();

        $this->publish(['cols' => '5', 'cols_phone' => '1', 'per_page' => '48']);
        $after = $this->get('/')->assertOk()->getContent();

        $strip = static fn (string $html): string => (string) preg_replace('#\s+#', ' ', (string) preg_replace('#<link rel="stylesheet" href="[^"]*tokens-[^"]*">#', '', $html));

        $this->assertSame($strip($before), $strip($after), 'the same page except for the token stylesheet link');
        $this->assertSame(12, substr_count($after, 'class="sf-card"'), 'the home page keeps its twelve');
    }

    public function test_one_template_serves_the_listing_and_the_slot_for_the_filters_is_empty(): void
    {
        $this->products(2);
        $view = file_get_contents(resource_path('views/storefront/category.blade.php'));

        $this->assertStringContainsString("@yield('listing_filters')", $view, 'the clearly named empty slot');
        $this->assertStringContainsString("@include('storefront.partials.pagination'", $view);
        $this->assertSame(1, preg_match_all('#Route::get\(.*(listing|tag|brand|search|season)#i', (string) file_get_contents(base_path('routes/storefront.php'))) + 1, 'no route for another listing was added');

        $html = $this->get('/product-category/clothing')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'class="sf-listing sf-category"'));
    }

    public function test_the_page_stays_sessionless_without_inline_style_or_script(): void
    {
        $this->products(30);
        $this->publish(['cols' => '5', 'cols_phone' => '1', 'per_page' => '12']);

        $response = $this->get('/product-category/clothing?page=2')->assertOk();
        $html = $response->getContent();

        $this->assertSame([], $response->headers->getCookies(), 'no cookie');
        $this->assertDoesNotMatchRegularExpression('/\sstyle=/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<style[\s>]/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)[^>]*>/i', $html);
    }

    public function test_the_query_count_does_not_depend_on_the_section(): void
    {
        $this->products(8);

        $count = function (): int {
            $this->app->forgetScopedInstances();
            \Illuminate\Support\Facades\Cache::flush();
            $n = 0;
            DB::listen(function () use (&$n): void {
                $n++;
            });
            $this->get('/product-category/clothing')->assertOk();

            return $n;
        };

        $default = $count();
        $this->publish(['per_page' => '48', 'cols' => '5', 'cols_phone' => '1']);
        $this->assertSame($default, $count(), 'a non-default section adds no query');
    }
}
