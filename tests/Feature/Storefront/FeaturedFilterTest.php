<?php

namespace Tests\Feature\Storefront;

use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Filters\FilterCatalog;
use App\Storefront\PageDesign\PageDesign;
use App\Storefront\PageDesign\PageDesignStore;
use App\Storefront\Sidebar\SidebarFilters;
use App\Storefront\Sidebar\SidebarStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * P2: the «Препоръчани» filter of the side panel and the top bar: a SWITCH (`featured=1`), driven by the same «Сайдбар» list as every other filter and HIDDEN until the merchant switches it on.
 *
 * The world: «Clothing» (1) with Colour (attribute 1: Red 1001, Blue 1002); brands Acme (1), Bolt (2); seasons Autumn (1), Winter (2).
 * P1 red / Acme / Autumn, FEATURED; P2 blue / Acme / Winter; P3 red / Bolt / Autumn; P4 blue / Bolt / Winter, FEATURED. Newest first: P4, P3, P2, P1.
 */
class FeaturedFilterTest extends TestCase
{
    use BuildsShippingZones;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->withoutVite();
        app()->setLocale('en');

        config(['app.url' => 'https://shop.test', 'app.name' => 'Raf Shop']);
        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();
        $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeAttribute(1, 'Colour', ['Red', 'Blue']);
        DB::table('catalog_attribute_definitions')->where('id', 1)->update(['is_filterable' => true, 'filter_display' => 'list']);

        foreach ([1 => 'Acme', 2 => 'Bolt'] as $id => $name) {
            $this->makeBrand($id, $name, strtolower($name));
        }

        foreach ([1 => 'Autumn', 2 => 'Winter'] as $id => $name) {
            DB::table('catalog_seasons')->insert(['id' => $id, 'name' => $name, 'slug' => strtolower($name), 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);
        }

        foreach ([1 => ['Red', 1, 1, true], 2 => ['Blue', 1, 2, false], 3 => ['Red', 2, 1, false], 4 => ['Blue', 2, 2, true]] as $id => [$colour, $brand, $season, $featured]) {
            $this->makeProduct($id, ['type' => 'variable', 'name' => "P{$id}", 'slug' => "p{$id}", 'categories' => [1], 'price' => '10.00', 'stock' => 3, 'brand' => $brand, 'featured' => $featured, 'timeline' => sprintf('2026-01-%02d 10:00:00', $id),
                'variations' => [['attrs' => [1 => $colour], 'price' => '10.00', 'stock' => 2]]]);
            DB::table('catalog_products')->where('id', $id)->update(['season_id' => $season]);
        }

        FilterCatalog::forget();
    }

    private function visit(string $url): \Illuminate\Testing\TestResponse
    {
        $this->app->forgetScopedInstances();

        return $this->get($url);
    }

    /** the saved list: every known filter shown, «Препоръчани» shown or not */
    private function publishList(bool $featured): void
    {
        $rows = [['key' => 'brand', 'show' => true], ['key' => 'season', 'show' => true], ['key' => '1', 'show' => true], ['key' => 'tag', 'show' => false], ['key' => 'featured', 'show' => $featured]];
        [$clean, $errors] = SidebarFilters::validate(['filters' => $rows, 'kinds' => ['brand' => 'list', 'season' => 'list', 'tag' => 'list']], app(FilterCatalog::class)->knownKeys(), app(FilterCatalog::class)->hiddenByDefault());
        $this->assertSame([], $errors, json_encode($errors));
        SidebarStore::publish($clean);
        $this->app->forgetScopedInstances();
    }

    /** @return list<int> */
    private function ids(string $html): array
    {
        preg_match_all('/data-product-id="(\d+)"/', $html, $m);

        return array_map('intval', $m[1]);
    }

    /** @return list<string> */
    private function bar(string $html): array
    {
        preg_match_all('#<details class="sf-bctl" name="sf-cbar">(.*?)</details>#s', $html, $controls);

        return array_map(static fn (string $control): string => explode(':', trim(strip_tags((string) (preg_match('#<span class="sf-bctl-text">(.*?)</span></summary>#s', $control, $t) ? $t[1] : ''))))[0], $controls[1]);
    }

    /** @return list<string> */
    private function panel(string $html): array
    {
        preg_match_all('#<details class="sf-fgroup"[^>]*>\s*<summary class="sf-fgroup-summary"><span class="sf-fgroup-name">(.*?)</span>#s', $html, $m);

        return array_map('trim', $m[1]);
    }

    private function queryCount(string $url): int
    {
        $n = 0;
        DB::listen(function () use (&$n): void {
            $n++;
        });
        $this->visit($url)->assertOk();

        return $n;
    }

    // ---- ★ hidden by default -------------------------------------------------------------------------------------------------------------

    /** ★ a fresh shop: nothing changes until the merchant switches it on */
    public function test_a_fresh_shop_does_not_offer_it_and_its_parameter_is_the_404_of_a_hidden_filter(): void
    {
        $html = $this->visit('/product-category/clothing')->assertOk()->getContent();

        $this->assertSame(['Brand', 'Season', 'Colour'], $this->bar($html));
        $this->assertSame(['Brand', 'Season', 'Colour'], $this->panel($html));
        $this->assertStringNotContainsString('featured=1', $html);
        $this->assertStringNotContainsString('name="featured"', $html);

        foreach (['featured=1', 'featured=2', 'featured=', 'featured[]=1'] as $query) {
            $this->visit('/product-category/clothing?'.$query)->assertNotFound();
        }

        $this->assertSame($this->visit('/product-category/nope')->getContent(), $this->visit('/product-category/clothing?featured=1')->getContent(), 'the same 404');
    }

    /** ★ a list saved before the filter existed has no `featured` row: it is appended HIDDEN, every other missing one is appended shown */
    public function test_a_stored_list_without_the_key_gets_it_hidden(): void
    {
        app(SiteSettingsRepository::class)->set(AppearanceSettings::SIDEBAR_FILTERS_KEY, json_encode(['filters' => [['key' => 'season', 'show' => true], ['key' => 'brand', 'show' => true]]]));
        app(StorefrontAppearance::class)->refresh();
        $this->app->forgetScopedInstances();

        $this->assertSame(['Season', 'Brand', 'Colour'], $this->bar($this->visit('/product-category/clothing')->assertOk()->getContent()), 'the attribute is appended shown, «Препоръчани» is not');
        $this->visit('/product-category/clothing?featured=1')->assertNotFound();

        $rows = collect(SidebarStore::values()['filters'])->pluck('show', 'key')->all();
        $this->assertFalse($rows['featured']);
        $this->assertTrue($rows['1']);
    }

    public function test_the_default_is_the_catalogs_not_the_stores(): void
    {
        $this->assertSame(['featured', 'collection'], app(FilterCatalog::class)->hiddenByDefault());
        $this->assertSame([['key' => 'a', 'show' => true], ['key' => 'featured', 'show' => false]], SidebarFilters::defaults(['a', 'featured'], [], ['featured']));
        $this->assertSame([['key' => 'a', 'show' => true], ['key' => 'featured', 'show' => true]], SidebarFilters::defaults(['a', 'featured'], []), 'without the catalog\'s default the store shows everything');
    }

    // ---- ★ shown ----------------------------------------------------------------------------------------------------------------------

    /** ★ only the flagged products, newest first; in the bar and in the side panel / drawer */
    public function test_when_shown_the_switch_is_in_the_bar_and_the_form_and_filters_the_listing(): void
    {
        $this->publishList(true);

        $html = $this->visit('/product-category/clothing')->assertOk()->getContent();
        $this->assertSame([4, 3, 2, 1], $this->ids($html));
        $this->assertSame(['Brand', 'Season', 'Colour', 'Featured'], $this->bar($html), 'the order of the «Сайдбар» list');
        $this->assertSame(['Brand', 'Season', 'Colour', 'Featured'], $this->panel($html));
        $this->assertStringContainsString('href="/product-category/clothing?featured=1" rel="nofollow">Featured</a>', $html, 'the bar: a link that turns it on');
        $this->assertStringContainsString('<input class="sf-fv-input" type="checkbox" name="featured" value="1" >', $html, 'the form: one checkbox, not checked');

        $on = $this->visit('/product-category/clothing?featured=1')->assertOk();
        $this->assertSame([4, 1], $this->ids($on->getContent()));
        $this->assertStringContainsString('<input class="sf-fv-input" type="checkbox" name="featured" value="1" checked>', $on->getContent());
        $this->assertStringContainsString('<strong class="sf-bctl-strong">Featured</strong>: Yes', $on->getContent(), 'the chip of the bar');
        $this->assertStringContainsString('class="sf-bar-clear" href="/product-category/clothing"', $on->getContent(), '«Clear» drops it');
        $on->assertHeader('X-Robots-Tag', 'noindex, follow');
    }

    public function test_it_combines_with_the_other_filters_the_sort_and_the_pager(): void
    {
        $this->publishList(true);

        $this->assertSame([1], $this->ids($this->visit('/product-category/clothing?featured=1&filter[brand]=1')->assertOk()->getContent()));
        $this->assertSame([4], $this->ids($this->visit('/product-category/clothing?filter[1]=1002&featured=1')->assertOk()->getContent()));
        // P6a: the price sort orders by the stored price: the two featured products get one (product 1 the cheaper)
        \Illuminate\Support\Facades\DB::table('catalog_products')->where('id', 1)->update(['price_from_minor' => 1000]);
        \Illuminate\Support\Facades\DB::table('catalog_products')->where('id', 4)->update(['price_from_minor' => 2000]);
        $this->assertSame([1, 4], $this->ids($this->visit('/product-category/clothing?featured=1&sort=price_asc')->assertOk()->getContent()));

        $html = $this->visit('/product-category/clothing?filter[brand]=1&featured=1')->assertOk()->getContent();
        $this->assertStringContainsString('href="/product-category/clothing?filter[brand]=1&amp;featured=1&amp;sort=price_asc"', $html, 'the sort keeps the switch');
        $this->assertStringContainsString('href="/product-category/clothing?filter[brand]=1"', $html, 'turning it off keeps the other filter');

        // one selection, one URL: the order is canonical, `featured` last
        $this->assertSame([1], $this->ids($this->visit('/product-category/clothing?filter[brand]=1&featured=1')->getContent()));
    }

    /** ★ any value but `1` is no filter at all (the page is the unfiltered one), never an error */
    public function test_a_value_other_than_one_is_ignored_when_shown(): void
    {
        $this->publishList(true);

        foreach (['featured=2', 'featured=', 'featured=true', 'featured=01', 'featured[]=1', 'featured[a]=1'] as $query) {
            $page = $this->visit('/product-category/clothing?'.$query)->assertOk();

            $this->assertSame([4, 3, 2, 1], $this->ids($page->getContent()), $query);
            $page->assertHeaderMissing('X-Robots-Tag');
        }
    }

    public function test_it_is_never_a_filter_key_of_the_url(): void
    {
        $this->publishList(true);

        $this->visit('/product-category/clothing?filter[featured]=1')->assertNotFound();
    }

    /** ★ hidden again: gone everywhere and its parameter is the 404 */
    public function test_hidden_again_it_is_gone_and_its_parameter_is_the_404(): void
    {
        $this->publishList(true);
        $this->visit('/product-category/clothing?featured=1')->assertOk();

        $this->publishList(false);

        $html = $this->visit('/product-category/clothing')->assertOk()->getContent();
        $this->assertSame(['Brand', 'Season', 'Colour'], $this->bar($html));
        $this->assertStringNotContainsString('featured', $html);
        $this->visit('/product-category/clothing?featured=1')->assertNotFound();
    }

    public function test_it_is_offered_in_a_shop_with_no_other_filter_and_moves_with_the_list(): void
    {
        DB::table('catalog_attribute_definitions')->where('id', 1)->update(['is_filterable' => false]);
        DB::table('catalog_products')->update(['brand_id' => null, 'season_id' => null]);
        DB::table('catalog_brands')->delete();
        DB::table('catalog_seasons')->delete();
        FilterCatalog::forget();

        $this->assertSame([], $this->bar($this->visit('/product-category/clothing')->assertOk()->getContent()));

        [$clean, $errors] = SidebarFilters::validate(['filters' => [['key' => 'featured', 'show' => true]], 'kinds' => ['brand' => 'list', 'season' => 'list', 'tag' => 'list']], app(FilterCatalog::class)->knownKeys(), app(FilterCatalog::class)->hiddenByDefault());
        $this->assertSame([], $errors);
        SidebarStore::publish($clean);
        $this->app->forgetScopedInstances();

        $this->assertSame(['Featured'], $this->bar($this->visit('/product-category/clothing')->assertOk()->getContent()), 'a shown switch is a filter even where there is nothing else to filter');
        $this->assertSame([4, 1], $this->ids($this->visit('/product-category/clothing?featured=1')->assertOk()->getContent()));
    }

    public function test_the_order_of_the_list_decides_where_it_sits(): void
    {
        $rows = [['key' => 'featured', 'show' => true], ['key' => '1', 'show' => true], ['key' => 'brand', 'show' => false], ['key' => 'season', 'show' => false], ['key' => 'tag', 'show' => false]];
        [$clean] = SidebarFilters::validate(['filters' => $rows, 'kinds' => ['brand' => 'list', 'season' => 'list', 'tag' => 'list']], app(FilterCatalog::class)->knownKeys(), app(FilterCatalog::class)->hiddenByDefault());
        SidebarStore::publish($clean);
        $this->app->forgetScopedInstances();

        $this->assertSame(['Featured', 'Colour'], $this->bar($this->visit('/product-category/clothing')->assertOk()->getContent()));
    }

    // ---- other pages and cost --------------------------------------------------------------------------------------------------------------

    public function test_it_works_on_a_brand_page_too(): void
    {
        $this->publishList(true);

        $this->assertSame([2, 1], $this->ids($this->visit('/brand/acme')->assertOk()->getContent()));
        $this->assertSame([1], $this->ids($this->visit('/brand/acme?featured=1')->assertOk()->getContent()));
        $this->assertStringContainsString('href="/brand/acme?featured=1"', $this->visit('/brand/acme')->getContent());
        $this->visit('/brand/acme?filter[brand]=2&featured=1')->assertOk();
    }

    /** ★ the switch costs no query of its own: shown but not chosen, or chosen, the page costs what it cost */
    public function test_the_switch_costs_no_extra_query(): void
    {
        $this->visit('/product-category/clothing')->assertOk(); // warm the filter cache
        $hidden = $this->queryCount('/product-category/clothing');

        $this->publishList(true);
        $this->visit('/product-category/clothing')->assertOk();
        $shown = $this->queryCount('/product-category/clothing');
        $chosen = $this->queryCount('/product-category/clothing?featured=1');

        fwrite(STDERR, "\n[query-count] category page, «Препоръчани»: hidden {$hidden}, shown {$shown}, chosen {$chosen}\n");

        $this->assertSame($hidden, $shown, 'shown but not chosen: no extra query');
        $this->assertLessThanOrEqual($shown + 1, $chosen, 'chosen: the same queries, only narrower (the facets are the filters\')');
    }

    public function test_the_product_query_of_a_listing_without_the_switch_has_no_featured_clause(): void
    {
        $this->publishList(true);

        $sql = [];
        DB::listen(function ($q) use (&$sql): void {
            $sql[] = $q->sql;
        });
        $this->visit('/product-category/clothing')->assertOk();

        $this->assertCount(0, array_filter($sql, static fn (string $s): bool => str_contains($s, 'is_featured')), 'not chosen: nothing about is_featured in any query');

        $sql = [];
        $this->visit('/product-category/clothing?featured=1')->assertOk();
        $this->assertNotCount(0, array_filter($sql, static fn (string $s): bool => str_contains($s, 'is_featured')));
    }

    public function test_the_draft_is_not_the_shop(): void
    {
        $rows = [['key' => 'featured', 'show' => true]];
        [$clean] = SidebarFilters::validate(['filters' => $rows, 'kinds' => ['brand' => 'list', 'season' => 'list', 'tag' => 'list']], app(FilterCatalog::class)->knownKeys(), app(FilterCatalog::class)->hiddenByDefault());
        SidebarStore::saveDraft($clean);

        $this->assertNotNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::SIDEBAR_FILTERS_DRAFT_KEY));
        $this->visit('/product-category/clothing?featured=1')->assertNotFound();
        $this->assertSame(PageDesignStore::values(PageDesign::CATALOG)['filterTag'], 'off');
    }
}
