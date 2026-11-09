<?php

namespace Tests\Feature\Storefront;

use App\Http\Controllers\Storefront\DesignPreviewController;
use App\Storefront\Filters\FilterCatalog;
use App\Storefront\PageDesign\PageDesign;
use App\Storefront\PageDesign\PageDesignStore;
use EasyCo\Catalog\Persistence\Eloquent\BrandModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * CT-4: the built-in filters of the category page — brand, season and tag — next to the attribute filters. The same URL rules (`filter[brand]=1,2`, canonical order: brand, season, the attributes, tag; 404 for an
 * unknown id or a switched-off filter; the caps), the same «only values that give products» rule, the three looks (list, links, buttons) in the bar and in the form (side panel / phone drawer), and the cost in queries.
 *
 * The world: «Clothing» (1) with Colour (attribute 1: Red 1001, Blue 1002); brands Acme (1), Bolt (2), Cora (3, no product); seasons Autumn (1), Winter (2); tags Alpha (1), Beta (2), Gamma (3).
 * P1 red / Acme / Autumn / Alpha+Beta; P2 blue / Acme / Winter / Alpha; P3 red / Bolt / Autumn / Beta+Gamma; P4 blue / Bolt / Winter / Gamma.
 */
class CategoryBuiltinFiltersTest extends TestCase
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

        foreach ([1 => 'Acme', 2 => 'Bolt', 3 => 'Cora'] as $id => $name) {
            $this->makeBrand($id, $name, strtolower($name));
        }

        foreach ([1 => 'Autumn', 2 => 'Winter'] as $id => $name) {
            DB::table('catalog_seasons')->insert(['id' => $id, 'name' => $name, 'slug' => strtolower($name), 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);
        }

        foreach ([1 => 'Alpha', 2 => 'Beta', 3 => 'Gamma'] as $id => $name) {
            $this->makeTag($id, $name, strtolower($name));
        }

        foreach ([1 => ['Red', 1, 1, [1, 2]], 2 => ['Blue', 1, 2, [1]], 3 => ['Red', 2, 1, [2, 3]], 4 => ['Blue', 2, 2, [3]]] as $id => [$colour, $brand, $season, $tags]) {
            $this->makeProduct($id, ['type' => 'variable', 'name' => "P{$id}", 'slug' => "p{$id}", 'categories' => [1], 'price' => '10.00', 'stock' => 3, 'brand' => $brand, 'tags' => $tags, 'timeline' => sprintf('2026-01-%02d 10:00:00', $id),
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

    private function publish(array $values): void
    {
        [$clean, $errors] = PageDesign::validate(PageDesign::CATALOG, $values + PageDesign::defaults(PageDesign::CATALOG), false);
        $this->assertSame([], $errors);
        PageDesignStore::publish(PageDesign::CATALOG, $clean);
    }

    /** the bar as `control name => the labels of its values` (the sort is not in it) @return array<string, list<string>> */
    private function bar(string $html): array
    {
        preg_match_all('#<details class="sf-bctl" name="sf-cbar">(.*?)</details>#s', $html, $controls);
        $out = [];

        foreach ($controls[1] as $control) {
            preg_match('#<span class="sf-bctl-text">(.*?)</span></summary>#s', $control, $text);
            preg_match_all('#<a class="sf-bv [^"]*" href="[^"]*" rel="nofollow"[^>]*>(.*?)(?:<span class="sf-sr">|</a>)#s', $control, $values);
            $out[explode(':', trim(strip_tags($text[1] ?? '')))[0]] = array_map(static fn (string $label): string => html_entity_decode(trim($label)), $values[1]);
        }

        return $out;
    }

    private function ids(string $html): array
    {
        preg_match_all('/data-product-id="(\d+)"/', $html, $m);

        return array_map('intval', $m[1]);
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

    // ---- which built-ins are shown, in which order --------------------------------------------------------------------------------------------

    public function test_the_defaults_show_brand_and_season_and_not_the_tag_and_only_values_that_give_products(): void
    {
        $bar = $this->bar($this->visit('/product-category/clothing')->assertOk()->getContent());

        $this->assertSame(['Brand', 'Season', 'Colour'], array_keys($bar), 'the order: brand, season, the attributes (the tag is off by default)');
        $this->assertSame(['Acme', 'Bolt'], $bar['Brand'], 'Cora has no product here: not shown');
        $this->assertSame(['Autumn', 'Winter'], $bar['Season']);
    }

    public function test_the_tag_is_last_when_it_is_switched_on_and_a_switched_off_built_in_is_gone(): void
    {
        $this->publish(['filterTag' => 'buttons', 'filterBrand' => 'off']);
        $bar = $this->bar($this->visit('/product-category/clothing')->getContent());

        $this->assertSame(['Season', 'Colour', 'Tag'], array_keys($bar));
        $this->assertSame(['Alpha', 'Beta', 'Gamma'], $bar['Tag'], 'by name');
    }

    public function test_a_value_that_gives_nothing_with_the_other_choices_is_not_shown_and_a_chosen_one_stays(): void
    {
        $this->publish(['filterTag' => 'list']);

        // Gamma only: P3 and P4 (both Bolt): the brands shrink to Bolt alone (a filter with fewer than two values is not shown), the seasons stay two
        $bar = $this->bar($this->visit('/product-category/clothing?filter[tag]=3')->assertOk()->getContent());

        $this->assertArrayNotHasKey('Brand', $bar);
        $this->assertSame(['Autumn', 'Winter'], $bar['Season']);
        $this->assertSame(['Alpha', 'Beta', 'Gamma'], $bar['Tag'], 'a filter\'s own choice never narrows its own list');

        // a brand that has no product: chosen it stays visible so it can be removed
        $html = $this->visit('/product-category/clothing?filter[brand]=3')->assertOk()->getContent();

        $this->assertSame([], $this->ids($html));
        $this->assertContains('Cora', $this->bar($html)['Brand']);
    }

    public function test_a_selection_narrows_the_list_with_or_inside_a_filter_and_and_between_filters(): void
    {
        $this->publish(['filterTag' => 'list']);

        $this->assertEqualsCanonicalizing([1, 2], $this->ids($this->visit('/product-category/clothing?filter[brand]=1')->getContent()));
        $this->assertEqualsCanonicalizing([1, 2, 3, 4], $this->ids($this->visit('/product-category/clothing?filter[brand]=1,2')->getContent()), 'OR inside one filter');
        $this->assertEqualsCanonicalizing([1], $this->ids($this->visit('/product-category/clothing?filter[brand]=1&filter[season]=1')->getContent()), 'AND between filters');
        $this->assertEqualsCanonicalizing([1, 3], $this->ids($this->visit('/product-category/clothing?filter[tag]=2')->getContent()), 'a product with several tags');
        $this->assertEqualsCanonicalizing([3], $this->ids($this->visit('/product-category/clothing?filter[brand]=2&filter[1]=1001&filter[tag]=2')->assertOk()->getContent()), 'with an attribute filter: red AND Beta AND Bolt');
    }

    public function test_the_page_is_noindex_with_a_canonical_to_the_unfiltered_category_and_the_links_are_nofollow(): void
    {
        $response = $this->visit('/product-category/clothing?filter[brand]=1')->assertOk();

        $response->assertHeader('X-Robots-Tag', 'noindex, follow');
        $this->assertStringContainsString('<link rel="canonical" href="https://shop.test/product-category/clothing">', $response->getContent());
        $this->assertStringContainsString('href="/product-category/clothing?filter[brand]=1,2" rel="nofollow"', $response->getContent(), 'the other brand is added with a nofollow link');
    }

    // ---- the URL: 404 and 301 ------------------------------------------------------------------------------------------------------------------

    public function test_an_unknown_brand_season_or_tag_id_is_the_storefront_404(): void
    {
        $this->publish(['filterTag' => 'list']);

        foreach (['filter[brand]=99', 'filter[season]=99', 'filter[tag]=99', 'filter[brand]=1,99', 'filter[brand]=abc', 'filter[brand]=01', 'filter[brand]=1,1', 'filter[brand]=', 'filter[brand][x]=1', 'filter[shoe]=1'] as $query) {
            $this->visit('/product-category/clothing?'.$query)->assertNotFound();
        }

        $this->visit('/product-category/clothing?filter[brand]=3')->assertOk(); // Cora exists: a valid choice that gives nothing
    }

    public function test_a_switched_off_built_in_is_not_in_the_whitelist_its_key_is_a_404(): void
    {
        $this->visit('/product-category/clothing?filter[tag]=1')->assertNotFound(); // the tag is off by default

        $this->publish(['filterTag' => 'list', 'filterBrand' => 'off']);

        $this->visit('/product-category/clothing?filter[tag]=1')->assertOk();
        $this->visit('/product-category/clothing?filter[brand]=1')->assertNotFound();
    }

    public function test_a_built_in_with_no_values_at_all_is_not_a_filter_and_its_key_is_a_404(): void
    {
        DB::table('catalog_product_tags')->delete();
        DB::table('catalog_tags')->delete();
        FilterCatalog::forget();
        $this->publish(['filterTag' => 'list']);

        $this->visit('/product-category/clothing?filter[tag]=1')->assertNotFound();
        $this->assertArrayNotHasKey('Tag', $this->bar($this->visit('/product-category/clothing')->getContent()));
    }

    public function test_the_same_choice_in_another_order_is_a_301_to_the_canonical_url_and_the_order_of_the_keys_is_brand_season_attributes_tag(): void
    {
        $this->publish(['filterTag' => 'list']);

        $this->visit('/product-category/clothing?filter[tag]=2,1')->assertStatus(301)->assertRedirect('/product-category/clothing?filter[tag]=1,2');
        $this->visit('/product-category/clothing?filter[tag]=2&filter[1]=1001&filter[season]=1&filter[brand]=2')->assertStatus(301)
            ->assertRedirect('/product-category/clothing?filter[brand]=2&filter[season]=1&filter[1]=1001&filter[tag]=2');
        $this->visit('/product-category/clothing?filter[brand][]=1&filter[brand][]=2')->assertStatus(301)->assertRedirect('/product-category/clothing?filter[brand]=1,2');
        $this->visit('/product-category/clothing?filter[brand]=1,2&per_page=12&page=1&sort=price_asc')->assertOk();
    }

    public function test_the_caps_count_the_built_ins_too(): void
    {
        foreach (range(4, 14) as $id) {
            $this->makeBrand($id, "Brand {$id}", "brand-{$id}");
        }

        FilterCatalog::forget();

        $this->visit('/product-category/clothing?filter[brand]='.implode(',', range(1, 10)))->assertStatus(301)->assertRedirect(); // ten values: allowed, but not canonical (Acme..Cora by name, then the others)
        $this->visit('/product-category/clothing?filter[brand]='.implode(',', range(1, 11)))->assertNotFound();

        $this->publish(['filterTag' => 'list']);
        $seven = 'filter[brand]=1&filter[season]=1&filter[tag]=1&filter[1]=1001&filter[2]=1&filter[3]=1&filter[4]=1';
        $this->visit('/product-category/clothing?'.$seven)->assertNotFound();
    }

    // ---- the looks ---------------------------------------------------------------------------------------------------------------------------------

    public function test_list_and_links_render_like_the_attribute_filters_in_the_bar_and_in_the_form(): void
    {
        $this->publish(['filterBrand' => 'links', 'filterSeason' => 'list', 'layout' => 'sidebar']);
        $html = $this->visit('/product-category/clothing?filter[brand]=1')->assertOk()->getContent();

        $this->assertStringContainsString('sf-bctl-values-links', $html);
        $this->assertStringContainsString('sf-bv sf-bv-link sf-bv-on', $html, 'links: the chosen brand is marked');
        $this->assertStringContainsString('sf-bv sf-bv-list', $html, 'list: the seasons');
        $this->assertStringContainsString('<strong class="sf-bctl-strong">Brand</strong>: Acme', $html, '«Име: избраните»');
        $this->assertStringContainsString('<label class="sf-fv sf-fv-link">', $html);
        $this->assertStringContainsString('name="filter[brand][]" value="1" checked', $html, 'the form (side panel / drawer): a real checkbox, the chosen one checked');
        $this->assertStringContainsString('name="filter[season][]" value="1"', $html);
        $this->assertStringContainsString('<label class="sf-fv sf-fv-list">', $html);
    }

    public function test_buttons_are_chips_a_link_in_the_bar_and_a_label_with_a_real_checkbox_in_the_form(): void
    {
        $this->publish(['filterTag' => 'buttons']);
        $html = $this->visit('/product-category/clothing?filter[tag]=2')->assertOk()->getContent();

        $this->assertStringContainsString('sf-bctl-values-buttons', $html);
        $this->assertStringContainsString('<a class="sf-bv sf-bv-chip sf-bv-on" href="/product-category/clothing" rel="nofollow">Beta<span class="sf-sr">, selected</span></a>', $html, 'the chosen chip: the link that removes it');
        $this->assertStringContainsString('<a class="sf-bv sf-bv-chip" href="/product-category/clothing?filter[tag]=1,2" rel="nofollow">Alpha</a>', $html);
        $this->assertSame(1, preg_match('#<label class="sf-fv sf-fv-chip">\s*<input class="sf-fv-input" type="checkbox" name="filter\[tag\]\[\]" value="2" checked>\s*<span class="sf-fv-text">Beta</span>\s*</label>#', $html), 'the drawer: the chip is the label of the real checkbox');
        $this->assertStringContainsString('sf-fvalues-buttons', $html);
        $this->assertStringContainsString('<strong class="sf-bctl-strong">Tag</strong>: Beta', $html);
    }

    public function test_the_names_come_from_the_merchant_and_are_escaped(): void
    {
        DB::table('catalog_tags')->where('id', 1)->update(['name' => 'A<b>"x"</b>']);
        FilterCatalog::forget();
        $this->publish(['filterTag' => 'buttons', 'filterBrand' => 'off']);
        $html = $this->visit('/product-category/clothing?filter[tag]=1')->assertOk()->getContent();

        $this->assertStringNotContainsString('A<b>"x"</b>', $html);
        $this->assertStringContainsString('A&lt;b&gt;&quot;x&quot;&lt;/b&gt;', $html);
    }

    public function test_the_page_has_no_style_attribute_and_no_inline_script_and_is_the_same_for_every_visitor(): void
    {
        $this->publish(['filterTag' => 'buttons']);
        $html = $this->visit('/product-category/clothing?filter[tag]=1')->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)[^>]*>/i', $html);
        $this->assertSame($html, $this->visit('/product-category/clothing?filter[tag]=1')->getContent());
    }

    // ---- the published design decides --------------------------------------------------------------------------------------------------------------

    public function test_a_draft_choice_never_reaches_the_shop_and_the_signed_preview_shows_it(): void
    {
        // P1: the kinds of the built-ins are the «Сайдбар» zone's working copy now (its signed preview carries them)
        [$draft] = \App\Storefront\Sidebar\SidebarFilters::validate(['filters' => [['key' => 'tag', 'show' => true, 'kind' => 'buttons']]], app(FilterCatalog::class)->knownKeys());
        \App\Storefront\Sidebar\SidebarStore::saveDraft($draft);

        $this->assertArrayNotHasKey('Tag', $this->bar($this->visit('/product-category/clothing')->getContent()));
        $this->assertArrayHasKey('Tag', $this->bar($this->visit(DesignPreviewController::previewUrl('sidebar'))->assertOk()->getContent()), 'the preview carries the working copy');
    }

    public function test_old_stored_data_without_the_keys_and_a_hand_edited_bad_value_use_the_defaults(): void
    {
        app(\App\Settings\Contracts\SiteSettingsRepository::class)->set(\App\Settings\AppearanceSettings::CATALOG_DESIGN_KEY, '{"cols":"4","cols_phone":"2","per_page":"24","layout":"full","side":"left"}');
        app(\App\Storefront\Appearance\StorefrontAppearance::class)->refresh();

        $this->assertSame(['Brand', 'Season', 'Colour'], array_keys($this->bar($this->visit('/product-category/clothing')->getContent())), 'old data: brand and season on, the tag off');

        app(\App\Settings\Contracts\SiteSettingsRepository::class)->set(\App\Settings\AppearanceSettings::CATALOG_DESIGN_KEY, '{"layout":"full","filterBrand":"huge","filterTag":["list"],"filterSeason":"off"}');
        app(\App\Storefront\Appearance\StorefrontAppearance::class)->refresh();

        $this->assertSame(['Brand', 'Colour'], array_keys($this->bar($this->visit('/product-category/clothing')->getContent())), 'bad brand → «list», bad tag → «off», the valid season «off» is kept');
    }

    // ---- the cost ------------------------------------------------------------------------------------------------------------------------------------

    public function test_a_shop_with_no_brand_season_or_tag_data_costs_what_a_shop_with_them_switched_off_costs(): void
    {
        DB::table('catalog_product_tags')->delete();
        DB::table('catalog_tags')->delete();
        DB::table('catalog_products')->update(['brand_id' => null, 'season_id' => null]);
        DB::table('catalog_seasons')->delete();
        DB::table('catalog_brands')->delete();
        FilterCatalog::forget();
        $this->publish(['filterTag' => 'list']);

        $this->queryCount('/product-category/clothing'); // warm
        $noData = $this->queryCount('/product-category/clothing');

        $this->publish(['filterBrand' => 'off', 'filterSeason' => 'off', 'filterTag' => 'off']);
        $this->queryCount('/product-category/clothing');
        $this->assertSame($noData, $this->queryCount('/product-category/clothing'), 'no data: not one query more');
        $this->assertSame([], array_diff(array_keys($this->bar($this->visit('/product-category/clothing')->getContent())), ['Colour']));
    }

    public function test_each_shown_built_in_costs_one_query_when_warm_and_the_cold_read_costs_one_more(): void
    {
        $this->publish(['filterBrand' => 'off', 'filterSeason' => 'off', 'filterTag' => 'off']);
        $this->queryCount('/product-category/clothing');
        $off = $this->queryCount('/product-category/clothing');

        $this->publish(['filterBrand' => 'list', 'filterSeason' => 'off', 'filterTag' => 'off']);
        $this->queryCount('/product-category/clothing');
        $this->assertSame($off + 1, $this->queryCount('/product-category/clothing'), 'one built-in: one facet query');

        $this->publish(['filterBrand' => 'list', 'filterSeason' => 'links', 'filterTag' => 'buttons']);
        $this->queryCount('/product-category/clothing');
        $this->assertSame($off + 3, $this->queryCount('/product-category/clothing'), 'three built-ins: three facet queries, nothing else');

        $warm = $this->queryCount('/product-category/clothing');
        FilterCatalog::forget();
        $this->assertSame($warm + 2, $this->queryCount('/product-category/clothing'), 'cold: the attributes in one query and the three built-ins in one more');
    }

    public function test_saving_a_brand_forgets_the_cached_lists(): void
    {
        $this->assertContains('Acme', $this->bar($this->visit('/product-category/clothing')->getContent())['Brand']);

        $brand = BrandModel::query()->findOrFail(1);
        $brand->forceFill(['name' => 'Renamed'])->save();

        $this->assertContains('Renamed', $this->bar($this->visit('/product-category/clothing')->getContent())['Brand']);
    }
}
