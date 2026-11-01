<?php

namespace Tests\Feature\Storefront;

use App\Storefront\Filters\FilterCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * CD-1c: the filters in the new look — the BAR of «filters on top» (the sort first, then one native dropdown per filter whose panel is one column of plain links that apply at once, «Изчисти» only for a
 * filter with a selection), «Брой продукти: N» beside the title, the phone DRAWER as two levels (rows, then one filter per screen) with «Изчисти» only with a selection and a delicate «Филтрирай», and
 * everything escaped, with no style attribute and no inline script. The URLs, the whitelist and the facets are CT-3's and are tested there.
 *
 * The world: Colour (1: Red 1001 #cc0000, Blue 1002 #0000cc; circles), Size (2: S 2001, M 2002; text links), Material (3: Cotton 3001, Wool 3002; a list, product-level).
 */
class CategoryBarTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        app()->setLocale('en');

        config(['app.url' => 'https://shop.test', 'app.name' => 'Raf Shop']);
        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();
        $this->makeCategory(1, 'Clothing', 'clothing');

        $this->makeAttribute(1, 'Colour', ['Red', 'Blue']);
        $this->makeAttribute(2, 'Size', ['S', 'M']);
        $this->makeAttribute(3, 'Material', ['Cotton', 'Wool']);

        foreach ([1 => 'swatches', 2 => 'links', 3 => 'list'] as $id => $display) {
            DB::table('catalog_attribute_definitions')->where('id', $id)->update(['is_filterable' => true, 'filter_display' => $display]);
        }

        DB::table('catalog_attribute_values')->where('id', 1001)->update(['colour' => '#cc0000']);
        DB::table('catalog_attribute_values')->where('id', 1002)->update(['colour' => '#0000cc']);

        $this->makeProduct(1, ['type' => 'variable', 'name' => 'P1', 'slug' => 'p1', 'categories' => [1], 'timeline' => '2026-01-01 10:00:00', 'variations' => [['attrs' => [1 => 'Red', 2 => 'S'], 'price' => '10.00', 'stock' => 2]]]);
        $this->makeProduct(2, ['type' => 'variable', 'name' => 'P2', 'slug' => 'p2', 'categories' => [1], 'timeline' => '2026-01-02 10:00:00', 'variations' => [['attrs' => [1 => 'Blue', 2 => 'M'], 'price' => '10.00', 'stock' => 2]]]);
        $this->makeProduct(3, ['name' => 'P3', 'slug' => 'p3', 'categories' => [1], 'price' => '10.00', 'stock' => 5, 'timeline' => '2026-01-03 10:00:00']);
        $this->makeProduct(4, ['name' => 'P4', 'slug' => 'p4', 'categories' => [1], 'price' => '10.00', 'stock' => 5, 'timeline' => '2026-01-04 10:00:00']);

        $this->makeProduct(5, ['type' => 'variable', 'name' => 'P5', 'slug' => 'p5', 'categories' => [1], 'timeline' => '2026-01-05 10:00:00', 'variations' => [['attrs' => [1 => 'Red', 2 => 'M'], 'price' => '10.00', 'stock' => 2]]]);

        foreach ([[3, 3001], [4, 3002]] as [$product, $value]) {
            DB::table('catalog_product_attributes')->insert(['product_id' => $product, 'attribute_definition_id' => 3, 'is_variation_axis' => false, 'attribute_value_id' => $value, 'sort_order' => 0, 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);
        }

        FilterCatalog::forget();
    }

    private function visit(string $url): \Illuminate\Testing\TestResponse
    {
        $this->app->forgetScopedInstances();

        return $this->get($url);
    }

    /** The bar's HTML (everything between the bar's opening div and the form). */
    private function bar(string $html): string
    {
        preg_match('#<div class="sf-cbar">.*?</div>\s*<div class="sf-fdrawer"#s', $html, $m);

        return $m[0] ?? '';
    }

    /** @return list<string> the controls of the bar, each as its HTML */
    private function controls(string $html): array
    {
        preg_match_all('#<details class="sf-bctl[^"]*" name="sf-cbar">.*?</details>#s', $this->bar($html), $m);

        return $m[0];
    }

    // ---- the bar ----------------------------------------------------------------------------------------------------------------------------------

    public function test_the_sort_is_the_first_control_then_one_dropdown_per_filter_in_the_admin_order_all_in_one_group(): void
    {
        $html = $this->visit('/product-category/clothing')->assertOk()->getContent();
        $controls = $this->controls($html);

        $this->assertCount(4, $controls, 'the sort, Colour, Size, Material');
        $this->assertStringContainsString('class="sf-bctl sf-bctl-sort"', $controls[0]);
        $this->assertStringContainsString('<span class="sf-bctl-text sf-bctl-strong">Newest</span>', $controls[0], 'the sort shows the current option, weight 500');

        foreach ([1 => 'Colour', 2 => 'Size', 3 => 'Material'] as $i => $name) {
            $this->assertStringContainsString('<span class="sf-bctl-text">'.$name.'</span>', $controls[$i], 'a filter with no selection shows its name only');
        }

        $this->assertSame(4, substr_count($this->bar($html), 'name="sf-cbar"'), 'native single-open: one name for the whole bar');
        $this->assertLessThan(strpos($html, 'class="sf-fdrawer"'), strpos($html, 'class="sf-cbar"'), 'the bar comes before the form');
        $this->assertLessThan(strpos($html, 'class="sf-cat-main"'), strpos($html, 'class="sf-fdrawer"'), 'and the form before the list');
    }

    public function test_the_sort_panel_lists_plain_links_the_current_one_marked(): void
    {
        $html = $this->visit('/product-category/clothing?sort=name_asc&filter[2]=2001')->assertOk()->getContent();
        $sort = $this->controls($html)[0];

        $this->assertStringContainsString('<span class="sf-bctl-text sf-bctl-strong">Name (A–Z)</span>', $sort);
        $this->assertStringContainsString('<a class="sf-bv sf-bv-link" href="/product-category/clothing?filter[2]=2001" rel="nofollow">Newest</a>', $sort, 'the default link has no sort and keeps the filter');
        $this->assertStringContainsString('<a class="sf-bv sf-bv-link sf-bv-on" href="/product-category/clothing?filter[2]=2001&amp;sort=name_asc" rel="nofollow" aria-current="true">Name (A–Z)</a>', $sort);
        $this->assertStringNotContainsString('sf-bctl-meta', $sort, 'the sort panel has no meta row');
    }

    public function test_a_panel_is_a_meta_row_and_one_column_of_links_by_kind(): void
    {
        $controls = $this->controls($this->visit('/product-category/clothing')->assertOk()->getContent());

        foreach ([1, 2, 3] as $i) {
            $this->assertStringContainsString('<span class="sf-bctl-chosen">Selected filters: 0</span>', $controls[$i]);
            $this->assertSame(1, substr_count($controls[$i], '<ul class="sf-bctl-values'), 'one column');
            $this->assertStringNotContainsString('(', strip_tags($controls[$i]), 'no counters beside the values');
        }

        // swatches: the circle (a validated colour as the fill of an inline SVG), the name as a title and as text for screen readers
        $this->assertStringContainsString('sf-bctl-values-swatches', $controls[1]);
        $this->assertStringContainsString('<a class="sf-bv sf-bv-dot" href="/product-category/clothing?filter[1]=1001" rel="nofollow" title="Red"><svg class="sf-fv-circle" width="28" height="28" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="12" fill="#cc0000"/></svg><span class="sf-sr">Red</span></a>', $controls[1]);
        // links: text
        $this->assertStringContainsString('sf-bctl-values-links', $controls[2]);
        $this->assertStringContainsString('<a class="sf-bv sf-bv-link" href="/product-category/clothing?filter[2]=2001" rel="nofollow">S</a>', $controls[2]);
        // list: a row with a box
        $this->assertStringContainsString('sf-bctl-values-list', $controls[3]);
        $this->assertStringContainsString('<a class="sf-bv sf-bv-list" href="/product-category/clothing?filter[3]=3001" rel="nofollow">Cotton</a>', $controls[3]);
    }

    public function test_the_bar_has_one_clear_after_its_last_control_only_with_a_selection_and_no_control_has_its_own(): void
    {
        $none = $this->visit('/product-category/clothing')->getContent();
        $this->assertStringNotContainsString('sf-bar-clear', $none);

        $html = $this->visit('/product-category/clothing?filter[1]=1001&filter[2]=2001&per_page=12')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'class="sf-bar-clear"'), 'exactly one');
        $this->assertMatchesRegularExpression('#</details>\s*<a class="sf-bar-clear" href="/product-category/clothing\?per_page=12" rel="nofollow">Clear</a>\s*</div>#', $html, 'the cell after the last control, the URL without the filters but with per_page');
        $this->assertStringNotContainsString('sf-bctl-clear', $html, 'no control prints its own «Clear»');
    }

    public function test_the_control_of_a_selected_filter_shows_its_name_in_weight_500_and_the_chosen_values_and_they_are_escaped(): void
    {
        DB::table('catalog_attribute_values')->where('id', 3001)->update(['value' => 'A<b>"x"</b>']);
        FilterCatalog::forget();

        $html = $this->visit('/product-category/clothing?filter[1]=1001,1002&filter[3]=3001')->assertOk()->getContent();
        $controls = $this->controls($html);

        $this->assertStringContainsString('<span class="sf-bctl-text"><strong class="sf-bctl-strong">Colour</strong>: Red, Blue</span>', $controls[1]);
        $this->assertStringContainsString('<span class="sf-bctl-text"><strong class="sf-bctl-strong">Material</strong>: A&lt;b&gt;&quot;x&quot;&lt;/b&gt;</span>', $controls[2], 'a chosen value that gives nothing keeps its control');
        $this->assertStringNotContainsString('A<b>', $html, 'nothing of a value is raw HTML');
        $this->assertStringContainsString('sf-bv sf-bv-list sf-bv-on', $controls[2]);
        $this->assertStringContainsString('<span class="sf-sr">, selected</span>', $controls[2], 'the state as text for screen readers');
    }

    public function test_a_value_that_gives_no_products_is_not_in_the_bar_or_in_the_drawer_and_a_chosen_one_stays(): void
    {
        // Wool only exists on P4 (no colour): with red chosen, wool gives nothing and is not offered; the material filter has one value left and nothing chosen: hidden
        $html = $this->visit('/product-category/clothing?filter[1]=1001')->assertOk()->getContent();

        $this->assertStringNotContainsString('filter[3]=3002', $html);
        $this->assertStringNotContainsString('name="filter[3][]"', $html);
        $this->assertCount(3, $this->controls($html), 'the sort, Colour and Size');

        // M gives nothing with red either; a chosen value that gives nothing stays so it can be removed
        $both = $this->visit('/product-category/clothing?filter[1]=1001&filter[3]=3002')->assertOk()->getContent();
        $this->assertStringContainsString('sf-bv sf-bv-list sf-bv-on" href="/product-category/clothing?filter[1]=1001" rel="nofollow">Wool', $both);
    }

    // ---- the count --------------------------------------------------------------------------------------------------------------------------------

    public function test_the_count_beside_the_title_is_the_total_of_the_result_on_every_page(): void
    {
        $html = $this->visit('/product-category/clothing?per_page=12')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<div class="sf-cat-head">\s*<h1 class="sf-title">Clothing</h1>\s*<p class="sf-count">Products: 5</p>\s*</div>#', $html);
        $this->assertStringContainsString('<p class="sf-fcount">Products: 5</p>', $html, 'and at the top of the drawer');
        $this->assertStringContainsString('<p class="sf-count">Products: 1</p>', $this->visit('/product-category/clothing?filter[2]=2001')->getContent());

        for ($i = 6; $i <= 16; $i++) {
            $this->makeProduct($i, ['name' => "P{$i}", 'slug' => "p{$i}", 'categories' => [1], 'price' => '10.00', 'stock' => 1, 'timeline' => '2026-02-01 10:00:00']);
        }

        $page2 = $this->visit('/product-category/clothing?per_page=12&page=2')->assertOk()->getContent();
        $this->assertStringContainsString('<p class="sf-count">Products: 16</p>', $page2, 'the total of the whole result, not of the page');
    }

    // ---- the phone drawer -------------------------------------------------------------------------------------------------------------------------

    public function test_the_drawer_is_a_list_of_rows_the_sort_first_then_one_screen_per_filter(): void
    {
        $html = $this->visit('/product-category/clothing?filter[1]=1001,1002&sort=name_asc')->assertOk()->getContent();

        preg_match('#<div class="sf-fdrawer".*?</form>#s', $html, $m);
        $drawer = $m[0];

        $this->assertLessThan(strpos($drawer, 'sf-fgroups'), strpos($drawer, 'sf-fgroup-sort'), 'the sort row is the first row');
        $this->assertStringContainsString('<span class="sf-fgroup-name">Sort</span><span class="sf-fgroup-current">Name (A–Z)</span>', $drawer);
        $this->assertStringContainsString('<span class="sf-fgroup-name">Colour</span><span class="sf-fgroup-current">Red, Blue</span>', $drawer, 'a row shows the chosen values');
        $this->assertStringContainsString('<span class="sf-fgroup-name">Size</span><span class="sf-fgroup-current"></span>', $drawer);
        $this->assertSame(3, substr_count($drawer, '<details class="sf-fgroup'), 'the sort, Colour and Size (the material gives nothing with these colours): one screen each');
        $this->assertStringContainsString('<p class="sf-fgroup-meta">Selected filters: 2</p>', $drawer, 'level 2 starts with the count of this filter');
        $this->assertStringContainsString('<p class="sf-fdrawer-title" id="sf-fdrawer-title">Filters</p>', $drawer);
        $this->assertStringContainsString('<button class="sf-fdrawer-close"', $drawer);
        $this->assertSame(0, substr_count($drawer, '<details class="sf-fgroup" open>'), 'closed rows');
    }

    public function test_the_drawer_foot_has_a_delicate_apply_and_a_clear_link_only_with_a_selection(): void
    {
        $plain = $this->visit('/product-category/clothing')->assertOk()->getContent();
        $this->assertStringContainsString('<button type="submit" class="sf-fbtn sf-fbtn-outline">Apply</button>', $plain);
        $this->assertStringNotContainsString('sf-fclear', $plain);
        $this->assertStringNotContainsString('sf-fbtn-primary', $plain, 'no solid button');
        $this->assertDoesNotMatchRegularExpression('#<button[^>]*sf-fbtn[^>]*>[^<]*\d#', $plain, 'no count on the button: the page is cached');

        $chosen = $this->visit('/product-category/clothing?filter[2]=2001')->assertOk()->getContent();
        $this->assertStringContainsString('<a class="sf-fclear" href="/product-category/clothing" rel="nofollow">Clear</a>', $chosen);
    }

    public function test_the_values_of_the_drawer_are_still_the_checkboxes_of_one_get_form(): void
    {
        $html = $this->visit('/product-category/clothing?filter[2]=2001')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<form class="sf-filters-form" method="get" action="/product-category/clothing">'));
        $this->assertStringContainsString('type="checkbox" name="filter[2][]" value="2001" checked', $html);
        $this->assertStringContainsString('type="checkbox" name="filter[2][]" value="2002"', $html);
    }

    // ---- security ---------------------------------------------------------------------------------------------------------------------------------

    public function test_the_page_has_no_style_attribute_and_no_inline_script_and_the_same_html_for_everyone(): void
    {
        $url = '/product-category/clothing?filter[1]=1001,1002&filter[3]=3001';
        $first = $this->visit($url)->assertOk();
        $html = $first->getContent();

        $this->assertDoesNotMatchRegularExpression('/\sstyle=/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)[^>]*>/i', $html);
        $this->assertSame([], $first->headers->getCookies(), 'no cookie');
        $this->assertSame($html, $this->withHeaders(['Cookie' => 'visitor=1', 'User-Agent' => 'other'])->visit($url)->getContent(), 'identical for every visitor');
    }

    public function test_every_filter_link_is_nofollow_and_the_bar_needs_no_script(): void
    {
        $bar = $this->bar($this->visit('/product-category/clothing?filter[1]=1001')->getContent());

        preg_match_all('#<a [^>]*>#', $bar, $links);
        $this->assertNotEmpty($links[0]);

        foreach ($links[0] as $link) {
            $this->assertStringContainsString('rel="nofollow"', $link);
        }

        $this->assertStringNotContainsString('onclick', $bar);
    }
}
