<?php

namespace Tests\Feature\Storefront;

use App\Http\Middleware\LimitFilteredCategoryRequests;
use App\Storefront\Filters\FilterCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * CT-3: the filters of the category page — the parameter guard, the one-selection-one-URL rule, the logic (OR inside a filter, AND between, the SAME in-stock variation, product-level values, descendants),
 * the facets (only values that give products together with the other filters' choices), the three looks, SEO, the rate limit of filtered requests and robots.txt.
 *
 * The world: Colour (1: Red 1001, Blue 1002, Green 1003; circles), Size (2: S 2001, M 2002, L 2003; text links), Material (3: Cotton 3001, Wool 3002; a list, a PRODUCT-level attribute), Brand-ish
 * «Season» (4: not a filter). Category 1 «Clothing» has the child 2 «Dresses».
 *   P1 red-S (stock), red-M (NO stock)         P2 blue-M (stock), red-S (stock)       P3 green-L (stock)
 *   P4 simple, Material Cotton                 P5 simple, Material Wool, no stock     P6 (in Dresses) blue-S (stock)      P7 red-M (stock)
 */
class CategoryFiltersTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    private const RED = 1001;

    private const BLUE = 1002;

    private const GREEN = 1003;

    private const S = 2001;

    private const M = 2002;

    private const L = 2003;

    private const COTTON = 3001;

    private const WOOL = 3002;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;

        config(['app.url' => 'https://shop.test', 'app.name' => 'Raf Shop']);
        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();

        $clothing = $this->makeCategory(1, 'Clothing', 'clothing');
        $dresses = $this->makeCategory(2, 'Dresses', 'dresses', $clothing);

        $this->makeAttribute(1, 'Colour', ['Red', 'Blue', 'Green']);
        $this->makeAttribute(2, 'Size', ['S', 'M', 'L']);
        $this->makeAttribute(3, 'Material', ['Cotton', 'Wool']);
        $this->makeAttribute(4, 'Season', ['Summer', 'Winter']);

        $this->filter(1, 'swatches');
        $this->filter(2, 'links');
        $this->filter(3, 'list');
        DB::table('catalog_attribute_values')->where('id', self::RED)->update(['colour' => '#cc0000']);
        DB::table('catalog_attribute_values')->where('id', self::BLUE)->update(['colour' => '#0000cc']);
        // Green has no colour: a text link in the same row

        $variable = fn (int $id, array $variations, array $categories = [1]) => $this->makeProduct($id, ['type' => 'variable', 'name' => "P{$id}", 'slug' => "p{$id}", 'categories' => $categories, 'variations' => $variations, 'timeline' => '2026-01-0'.$id.' 10:00:00']);

        $variable(1, [['attrs' => [1 => 'Red', 2 => 'S'], 'price' => '10.00', 'stock' => 2], ['attrs' => [1 => 'Red', 2 => 'M'], 'price' => '10.00', 'stock' => 0]]);
        $variable(2, [['attrs' => [1 => 'Blue', 2 => 'M'], 'price' => '10.00', 'stock' => 3], ['attrs' => [1 => 'Red', 2 => 'S'], 'price' => '10.00', 'stock' => 2]]);
        $variable(3, [['attrs' => [1 => 'Green', 2 => 'L'], 'price' => '10.00', 'stock' => 1]]);
        $this->makeProduct(4, ['name' => 'P4', 'slug' => 'p4', 'categories' => [1], 'price' => '10.00', 'stock' => 5, 'timeline' => '2026-01-04 10:00:00']);
        $this->makeProduct(5, ['name' => 'P5', 'slug' => 'p5', 'categories' => [1], 'price' => '10.00', 'stock' => 0, 'timeline' => '2026-01-05 10:00:00']);
        $variable(6, [['attrs' => [1 => 'Blue', 2 => 'S'], 'price' => '10.00', 'stock' => 1]], [2]);
        $variable(7, [['attrs' => [1 => 'Red', 2 => 'M'], 'price' => '10.00', 'stock' => 4]]);

        $this->productValue(4, 3, self::COTTON);
        $this->productValue(5, 3, self::WOOL);
        FilterCatalog::forget();
    }

    private function filter(int $attribute, string $display, bool $on = true): void
    {
        DB::table('catalog_attribute_definitions')->where('id', $attribute)->update(['is_filterable' => $on, 'filter_display' => $display]);
        FilterCatalog::forget();
    }

    private function productValue(int $product, int $attribute, int $value): void
    {
        DB::table('catalog_product_attributes')->insert(['product_id' => $product, 'attribute_definition_id' => $attribute, 'is_variation_axis' => false, 'attribute_value_id' => $value, 'sort_order' => 0, 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);
    }

    /** A request as a fresh one (the scoped readers of the previous request in this test process are dropped). */
    private function visit(string $url, string $ip = '203.0.113.5'): \Illuminate\Testing\TestResponse
    {
        $this->app->forgetScopedInstances();

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->get($url);
    }

    /** @return list<int> the product ids on the page, in the order shown */
    private function ids(string $html): array
    {
        preg_match_all('/data-product-id="(\d+)"/', $html, $m);

        return array_map('intval', $m[1]);
    }

    private function sorted(string $url): array
    {
        $ids = $this->ids($this->visit($url)->assertOk()->getContent());
        sort($ids);

        return $ids;
    }

    /** @return list<string> the labels of the values printed in the group of an attribute (the labels of its checkboxes) */
    private function shown(string $html, int $attribute): array
    {
        preg_match_all('#<details class="sf-fgroup".*?</details>#s', $html, $groups);

        foreach ($groups[0] as $group) {
            if (! str_contains($group, 'name="filter['.$attribute.'][]"')) {
                continue;
            }

            preg_match_all('#<label class="sf-fv[^"]*"[^>]*>.*?</label>#s', $group, $labels);

            return array_map(static function (string $label): string {
                preg_match('#<span class="sf-(?:fv-text|sr)">([^<]*)</span>#', $label, $m);

                return html_entity_decode($m[1] ?? '');
            }, $labels[0]);
        }

        return [];
    }

    private function checked(string $html, int $attribute): array
    {
        preg_match_all('#<input class="sf-fv-input" type="checkbox" name="filter\['.$attribute.'\]\[\]" value="(\d+)" checked>#', $html, $m);

        return array_map('intval', $m[1]);
    }

    // ---- no filters: nothing changes ----------------------------------------------------------------------------------------------------

    public function test_a_shop_with_no_filterable_attribute_prints_no_filter_block_and_asks_the_attributes_nothing_when_warm(): void
    {
        foreach ([1, 2, 3] as $attribute) {
            $this->filter($attribute, 'list', false);
        }

        $html = $this->visit('/product-category/clothing')->assertOk()->getContent();

        $this->assertStringNotContainsString('sf-fgroup', $html);
        $this->assertStringNotContainsString('sf-filter-chip', $html);
        $this->assertSame([7, 6, 5, 4, 3, 2, 1], $this->ids($html), 'the category as it was');

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->visit('/product-category/clothing')->assertOk();

        $this->assertSame([], array_values(array_filter($queries, static fn (string $sql): bool => ! str_contains($sql, 'catalog_products') && str_contains($sql, 'catalog_attribute_'))), 'a shop without filters pays no query of its own for them (the flag rides on the page query)');
    }

    public function test_a_category_with_filterable_attributes_but_nothing_to_choose_prints_no_block(): void
    {
        DB::table('catalog_product_categories')->whereIn('category_id', [1, 2])->delete();
        DB::table('catalog_product_categories')->insert([['product_id' => 3, 'category_id' => 1]]); // only green-L: every filter has one value

        $html = $this->visit('/product-category/clothing')->assertOk()->getContent();

        $this->assertStringNotContainsString('sf-fgroup', $html);
    }

    // ---- the parameter guard --------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function badQueries(): array
    {
        $many = implode(',', array_slice(range(1001, 1003), 0, 3));

        return [
            'unknown attribute' => ['filter[9]=1001'],
            'unknown value' => ['filter[1]=9999'],
            'a value of another attribute' => ['filter[1]=2001'],
            'an attribute that is not a filter' => ['filter[4]=4001'],
            'filter is a string' => ['filter=x'],
            'filter empty' => ['filter='],
            'a list instead of a map' => ['filter[]=1001'],
            'a list of lists' => ['filter[][]=1001'],
            'a nested array' => ['filter[1][0][]=1001'],
            'an associative array' => ['filter[1][a]=1001'],
            'an empty array element' => ['filter[1][]='],
            'a duplicated array value' => ['filter[1][]=1001&filter[1][]=1001'],
            'an unknown array value' => ['filter[1][]=9999'],
            'an array value that is not a number' => ['filter[1][]=%3Cb%3E'],
            'an array value of another attribute' => ['filter[1][]=2001'],
            'an empty value list' => ['filter[1]='],
            'a trailing comma' => ['filter[1]=1001,'],
            'a duplicated value' => ['filter[1]=1001,1001'],
            'a leading zero' => ['filter[1]=01001'],
            'a leading zero key' => ['filter[01]=1001'],
            'too long' => ['filter[1]='.str_repeat('1', 65)],
            'a sign' => ['filter[1]=%2B1001'],
            'uppercase hex' => ['filter[1]=0X3E9'],
            'a script' => ['filter[1]=%3Cscript%3Ealert(1)%3C/script%3E'],
            'a quote' => ['filter[1]=1001%27'],
            'a space' => ['filter[1]=1001%20,1002'],
            'a semicolon' => ['filter[1]=1001;1002'],
            'a negative key' => ['filter[-1]=1001'],
            'a text key' => ['filter[colour]=1001'],
            'filter with page out of range' => ['filter[1]=1001&page=99'],
            'filter with a bad per_page' => ['filter[1]=1001&per_page=13'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badQueries')]
    public function test_a_bad_filter_is_the_storefront_404(string $query): void
    {
        $this->visit('/product-category/clothing?'.$query)->assertNotFound();
    }

    public function test_the_caps_on_filters_and_values(): void
    {
        // seven filters, one value too many in one filter, 16 values in all
        for ($attribute = 10; $attribute <= 16; $attribute++) {
            $this->makeAttribute($attribute, "Extra {$attribute}", ['a', 'b']);
            $this->filter($attribute, 'list');
        }

        $this->makeAttribute(20, 'Many', array_map(fn (int $i): string => "v{$i}", range(1, 12)));
        $this->filter(20, 'list');
        $this->makeAttribute(21, 'Many2', array_map(fn (int $i): string => "w{$i}", range(1, 12)));
        $this->filter(21, 'list');

        $six = 'filter[1]=1001&filter[2]=2001&filter[3]=3001&filter[10]=10001&filter[11]=11001&filter[12]=12001';
        $this->visit('/product-category/clothing?'.$six)->assertOk();
        $this->visit('/product-category/clothing?'.$six.'&filter[13]=13001')->assertNotFound();

        $ten = 'filter[20]='.implode(',', range(20001, 20010));
        $this->visit('/product-category/clothing?'.$ten)->assertOk();
        $this->visit('/product-category/clothing?filter[20]='.implode(',', range(20001, 20011)))->assertNotFound();

        $fifteen = 'filter[20]='.implode(',', range(20001, 20008)).'&filter[21]='.implode(',', range(21001, 21007));
        $this->visit('/product-category/clothing?'.$fifteen)->assertOk();
        $this->visit('/product-category/clothing?filter[20]='.implode(',', range(20001, 20008)).'&filter[21]='.implode(',', range(21001, 21008)))->assertNotFound();
    }

    public function test_the_same_selection_in_another_order_is_a_301_to_the_canonical_url_and_that_url_is_200(): void
    {
        $this->visit('/product-category/clothing?filter[2]=2001&filter[1]=1001')->assertStatus(301)
            ->assertRedirect(url('/product-category/clothing?filter[1]=1001&filter[2]=2001'));
        $this->visit('/product-category/clothing?filter[1]=1002,1001')->assertStatus(301)
            ->assertRedirect(url('/product-category/clothing?filter[1]=1001,1002'));
        $this->visit('/product-category/clothing?filter[2]=2001&filter[1]=1001&per_page=12&sort=name_asc&page=2')->assertStatus(301)
            ->assertRedirect(url('/product-category/clothing?filter[1]=1001&filter[2]=2001&page=2&per_page=12&sort=name_asc'));

        $this->visit('/product-category/clothing?filter[1]=1001,1002&filter[2]=2001')->assertOk();
    }

    public function test_unknown_keys_are_ignored_as_before(): void
    {
        $this->visit('/product-category/clothing?utm_source=x&filter[1]=1001&price_min=5')->assertOk();
    }

    // ---- the logic --------------------------------------------------------------------------------------------------------------------------

    public function test_inside_one_filter_the_values_are_or(): void
    {
        $this->assertSame([1, 2, 7], $this->sorted('/product-category/clothing?filter[1]=1001'), 'red: P1 (red-S), P2 (red-S), P7 (red-M); P6 is blue');
        $this->assertSame([1, 2, 6, 7], $this->sorted('/product-category/clothing?filter[1]=1001,1002'), 'red OR blue (P6 is in the child category)');
    }

    public function test_between_filters_the_choices_are_and_on_the_same_in_stock_variation(): void
    {
        // P1 has red-S and red-M, but red-M has no stock; P2 has blue-M and red-S, never red-M: only P7 has an in-stock red-M
        $this->assertSame([7], $this->sorted('/product-category/clothing?filter[1]=1001&filter[2]=2002'));
        $this->assertSame([2, 7], $this->sorted('/product-category/clothing?filter[2]=2002'), 'M in stock: P2 (blue-M) and P7; P1\'s M is sold out');
        $this->assertSame([1, 2], $this->sorted('/product-category/clothing?filter[1]=1001&filter[2]=2001'), 'red-S on ONE variation: P1 and P2');
        $this->assertSame([2], $this->sorted('/product-category/clothing?filter[1]=1002&filter[2]=2002'), 'blue-M: P2');
    }

    public function test_a_product_level_value_needs_the_product_not_a_variation_or_stock(): void
    {
        $this->assertSame([4], $this->sorted('/product-category/clothing?filter[3]=3001'));
        $this->assertSame([5], $this->sorted('/product-category/clothing?filter[3]=3002'), 'the product has it: sold out does not matter');
        $this->assertSame([4, 5], $this->sorted('/product-category/clothing?filter[3]=3001,3002'));
        $this->assertSame([], $this->sorted('/product-category/clothing?filter[1]=1001&filter[3]=3001'), 'cotton and red: no product');
    }

    public function test_the_child_category_is_included(): void
    {
        $this->assertContains(6, $this->sorted('/product-category/clothing?filter[1]=1002'));
        $this->assertSame([6], $this->sorted('/product-category/clothing/dresses?filter[1]=1002'));
    }

    public function test_the_total_and_the_pages_are_those_of_the_filtered_set_and_the_links_keep_the_filters(): void
    {
        for ($i = 30; $i < 60; $i++) { // 30 more red-S products
            $this->makeProduct($i, ['type' => 'variable', 'name' => "R{$i}", 'slug' => "r{$i}", 'categories' => [1], 'timeline' => '2026-02-01 10:00:00', 'variations' => [['attrs' => [1 => 'Red', 2 => 'S'], 'price' => '10.00', 'stock' => 1]]]);
        }

        $html = $this->visit('/product-category/clothing?filter[1]=1001&per_page=12&sort=name_asc')->assertOk()->getContent();

        $this->assertSame(12, count($this->ids($html)));
        $this->assertStringContainsString('Page 1 of 3', $html, '33 red products at 12 per page');
        $this->assertStringContainsString('href="/product-category/clothing?filter[1]=1001&amp;page=2&amp;per_page=12&amp;sort=name_asc"', $html);

        $page3 = $this->visit('/product-category/clothing?filter[1]=1001&page=3&per_page=12&sort=name_asc')->assertOk()->getContent();
        $this->assertSame(9, count($this->ids($page3)), '33 red products: the last page holds 9');
        $this->visit('/product-category/clothing?filter[1]=1001&page=4&per_page=12')->assertNotFound();
    }

    public function test_a_selection_with_no_products_shows_the_message_and_the_filters_to_undo_it(): void
    {
        $html = $this->visit('/product-category/clothing?filter[1]=1001&filter[3]=3001')->assertOk()->getContent();

        $this->assertStringContainsString('class="sf-empty"', $html);
        $this->assertStringContainsString('sf-filters', $html);
        $this->assertStringContainsString('sf-filter-clear', $html);
    }

    // ---- the facets -------------------------------------------------------------------------------------------------------------------------

    public function test_the_values_shown_depend_on_the_other_filters_and_not_on_their_own(): void
    {
        $none = $this->visit('/product-category/clothing')->getContent();
        $this->assertSame(['Red', 'Blue', 'Green'], $this->shown($none, 1));
        $this->assertSame(['S', 'M', 'L'], $this->shown($none, 2));
        $this->assertSame(['Cotton', 'Wool'], $this->shown($none, 3));

        // red chosen: the sizes that exist in red in stock (S: P1, P2; M: P7); L is green only. The colours do not shrink: red's own choice does not narrow the colour list
        $red = $this->visit('/product-category/clothing?filter[1]=1001')->getContent();
        $this->assertSame(['S', 'M'], $this->shown($red, 2));
        $this->assertSame(['Red', 'Blue', 'Green'], $this->shown($red, 1));

        // a second size can be added: size S chosen does not hide M
        $s = $this->visit('/product-category/clothing?filter[2]=2001')->getContent();
        $this->assertSame(['S', 'M', 'L'], $this->shown($s, 2));
        $this->assertSame(['Red', 'Blue'], $this->shown($s, 1), 'in stock in S: red (P1, P2) and blue (P6)');
    }

    public function test_a_value_with_no_in_stock_product_is_not_shown_and_a_sold_out_variation_does_not_count(): void
    {
        DB::table('stock_levels')->where('variation_id', 701)->delete(); // P7's only red-M: M is now in stock only for blue-M (P2)

        $html = $this->visit('/product-category/clothing?filter[1]=1001')->getContent();

        $this->assertStringNotContainsString('value="2002"', $html, 'red + M gives no product any more: M is gone from the red list');
        $this->assertStringNotContainsString('name="filter[2][]"', $html, 'and a filter with one value left and nothing chosen is hidden');
    }

    public function test_a_selected_value_that_gives_nothing_stays_visible_so_it_can_be_removed(): void
    {
        // L exists only on green; blue + L gives nothing. Blue is chosen, size L is chosen: the colour list is green only (plus the chosen blue)
        $html = $this->visit('/product-category/clothing?filter[1]=1002&filter[2]=2003')->assertOk()->getContent();

        $this->assertContains('Blue', $this->shown($html, 1));
        $this->assertContains('Green', $this->shown($html, 1));
        $this->assertContains('L', $this->shown($html, 2), 'the chosen size stays');
        $this->assertStringContainsString('class="sf-filter-chip"', $html);
        $this->assertSame(2, substr_count($html, 'class="sf-filter-chip"'), 'one removable item per chosen value');
    }

    public function test_a_filter_with_fewer_than_two_values_and_nothing_chosen_is_not_shown(): void
    {
        $html = $this->visit('/product-category/clothing?filter[1]=1003')->assertOk()->getContent(); // green: only L exists in green

        $this->assertStringNotContainsString('name="filter[2][]"', $html, 'the size filter has one value (L) and nothing chosen: hidden');
        $this->assertStringContainsString('name="filter[1][]"', $html);
    }

    // ---- the looks ----------------------------------------------------------------------------------------------------------------------------

    public function test_the_three_looks(): void
    {
        $html = $this->visit('/product-category/clothing')->assertOk()->getContent();

        // swatches: an inline SVG circle with the validated colour inside the label of a checkbox; a value with no colour is a text label in the same row
        $this->assertStringContainsString('<svg class="sf-fv-circle" width="24" height="24" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="12" fill="#cc0000"/></svg>', $html);
        $this->assertStringContainsString('fill="#0000cc"', $html);
        $this->assertMatchesRegularExpression('#<label class="sf-fv sf-fv-link">\s*<input class="sf-fv-input" type="checkbox" name="filter\[1\]\[\]" value="1003"\s*>\s*<span class="sf-fv-text">Green</span>#', $html);
        $this->assertStringContainsString('title="Red"', $html);
        // links: the checkbox is hidden by CSS, the label is the text
        $this->assertMatchesRegularExpression('#<ul class="sf-filter-values sf-fvalues-links">.*?<label class="sf-fv sf-fv-link">\s*<input class="sf-fv-input" type="checkbox" name="filter\[2\]\[\]" value="2001"\s*>\s*<span class="sf-fv-text">S</span>#s', $html);
        // list: a visible square mark
        $this->assertMatchesRegularExpression('#<ul class="sf-filter-values sf-fvalues-list">.*?<label class="sf-fv sf-fv-list">\s*<input class="sf-fv-input" type="checkbox" name="filter\[3\]\[\]" value="3001"\s*>\s*<span class="sf-fv-mark"></span>\s*<span class="sf-fv-text">Cotton</span>#s', $html);
        $this->assertDoesNotMatchRegularExpression('/\sstyle=/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)[^>]*>/i', $html);
    }

    public function test_a_chosen_value_is_checked_and_its_chip_removes_it_and_the_links_are_the_canonical_url(): void
    {
        $html = $this->visit('/product-category/clothing?filter[1]=1001&filter[2]=2001')->assertOk()->getContent();

        $this->assertSame([1001], $this->checked($html, 1));
        $this->assertSame([2001], $this->checked($html, 2));
        $this->assertStringContainsString('href="/product-category/clothing?filter[2]=2001" rel="nofollow">Red', $html, 'the chip of red removes red');
        $this->assertStringContainsString('href="/product-category/clothing?filter[1]=1001" rel="nofollow">S', $html, 'the chip of S removes S');
        $this->assertStringContainsString('href="/product-category/clothing" rel="nofollow">Clear all', $html);
        $this->assertStringContainsString('(1)', $html, 'a group with a choice shows its count');
    }

    public function test_a_hostile_value_label_is_escaped(): void
    {
        DB::table('catalog_attribute_values')->where('id', self::GREEN)->update(['value' => '<img src=x onerror=alert(1)>', 'colour' => '#00cc00']);
        FilterCatalog::forget();

        $html = $this->visit('/product-category/clothing')->assertOk()->getContent();

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    // ---- SEO --------------------------------------------------------------------------------------------------------------------------------------

    public function test_a_filtered_page_is_noindex_with_the_unfiltered_canonical_and_an_unfiltered_one_is_as_before(): void
    {
        $filtered = $this->visit('/product-category/clothing?filter[1]=1001&page=1')->assertOk();
        $html = $filtered->getContent();

        $this->assertStringContainsString('<meta name="robots" content="noindex">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://shop.test/product-category/clothing">', $html);
        $this->assertSame('noindex, follow', $filtered->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('rel="nofollow"', $html);

        $plain = $this->visit('/product-category/clothing')->assertOk();
        $this->assertStringNotContainsString('name="robots"', $plain->getContent());
        $this->assertNull($plain->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('<link rel="canonical" href="https://shop.test/product-category/clothing">', $plain->getContent());
    }

    public function test_the_page_stays_sessionless(): void
    {
        $response = $this->visit('/product-category/clothing?filter[1]=1001')->assertOk();

        $this->assertSame([], $response->headers->getCookies());
    }

    // ---- the rate limit of filtered requests ---------------------------------------------------------------------------------------------------------

    public function test_the_121st_filtered_request_of_one_ip_in_a_minute_is_429_and_nothing_else_is_limited(): void
    {
        Log::spy();
        RateLimiter::clear('filtered-category:198.51.100.7');

        for ($i = 1; $i <= LimitFilteredCategoryRequests::LIMIT; $i++) {
            $this->visit('/product-category/clothing?filter=x', '198.51.100.7')->assertNotFound(); // a refused filter still counts: it is the request a crawler sends
        }

        $limited = $this->visit('/product-category/clothing?filter[1]=1001', '198.51.100.7');
        $limited->assertStatus(429);
        $this->assertGreaterThan(0, (int) $limited->headers->get('Retry-After'));
        $this->assertSame('noindex', $limited->headers->get('X-Robots-Tag'));
        $this->assertSame([], $limited->headers->getCookies());
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $limited->getContent());

        $this->visit('/product-category/clothing?filter[2]=2001', '198.51.100.7')->assertStatus(429);

        // another IP is not affected; the limited one still reaches everything that carries no filter
        $this->visit('/product-category/clothing?filter[1]=1001', '198.51.100.8')->assertOk();
        foreach (['/product-category/clothing' => 200, '/product-category/clothing?per_page=12&sort=name_asc' => 200, '/product/p4' => 200, '/' => 200, '/cart' => 200] as $url => $status) {
            $this->assertSame($status, $this->visit($url, '198.51.100.7')->getStatusCode(), $url);
        }

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_unfiltered_requests_do_not_count_toward_the_limit(): void
    {
        RateLimiter::clear('filtered-category:198.51.100.9');

        for ($i = 1; $i <= LimitFilteredCategoryRequests::LIMIT + 5; $i++) {
            $this->visit('/product-category/clothing/dresses', '198.51.100.9')->assertOk();
        }

        $this->visit('/product-category/clothing?filter[1]=1001', '198.51.100.9')->assertOk();
    }

    // ---- robots.txt ----------------------------------------------------------------------------------------------------------------------------------

    public function test_robots_txt_blocks_the_back_office_and_the_filter_urls_and_nothing_a_shopper_needs(): void
    {
        $lines = array_map('trim', file(public_path('robots.txt')));

        foreach (['User-agent: *', 'Disallow: /admin/', 'Disallow: /api/', 'Disallow: /design-preview', 'Disallow: /cart', 'Disallow: /checkout', 'Disallow: /*filter[', 'Disallow: /*filter%5B'] as $line) {
            $this->assertContains($line, $lines);
        }

        $disallowed = array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'Disallow:'));

        foreach ($disallowed as $line) {
            foreach (['product-category', '/product', 'page', 'per_page', 'Disallow: /$', 'Disallow:'.' '] as $forbidden) {
                $this->assertFalse($forbidden === 'Disallow:'.' ' ? trim($line) === 'Disallow:' : str_contains($line, $forbidden), $line.' must not block '.$forbidden);
            }
        }

        $this->assertFalse(collect($lines)->contains(fn (string $line): bool => str_starts_with($line, 'Sitemap:')), 'no sitemap route exists, so no Sitemap line');
    }

    // ---- the cost -------------------------------------------------------------------------------------------------------------------------------------

    public function test_the_filters_cost_one_query_per_filter_of_the_shop_and_one_to_read_them_when_cold(): void
    {
        $count = function (string $url): int {
            $n = 0;
            DB::listen(function () use (&$n): void {
                $n++;
            });
            $this->visit($url)->assertOk();

            return $n;
        };

        foreach ([1, 2, 3] as $attribute) {
            $this->filter($attribute, 'list', false);
        }

        $count('/product-category/clothing'); // warm the cache of an empty shop-list
        $none = $count('/product-category/clothing');

        foreach ([1, 2] as $attribute) {
            $this->filter($attribute, 'list');
        }
        $cold = $count('/product-category/clothing');
        $two = $count('/product-category/clothing');
        $this->filter(3, 'list');
        $count('/product-category/clothing');
        $three = $count('/product-category/clothing');
        $selected = $count('/product-category/clothing?filter[1]=1001&filter[2]=2001');

        fwrite(STDERR, "\n[query-count] category page: no filters {$none}, 2 filters {$two} (cold {$cold}), 3 filters {$three}, 2 chosen of 3 {$selected}\n");

        $this->assertSame($none + 2, $two, 'one facet query per filter');
        $this->assertSame($none + 3, $three);
        $this->assertSame($two + 1, $cold, 'a cold shop pays one query to read the filters');
        $this->assertSame($three, $selected, 'a chosen filter costs no more than an unchosen one');
    }

    public function test_the_cache_of_the_filters_is_forgotten_when_an_attribute_is_saved(): void
    {
        $this->visit('/product-category/clothing')->assertOk();
        $this->assertTrue(Cache::has(FilterCatalog::CACHE_KEY));

        \EasyCo\Catalog\Persistence\Eloquent\AttributeDefinitionModel::query()->find(1)->update(['filter_display' => 'list']);

        $this->assertFalse(Cache::has(FilterCatalog::CACHE_KEY));
    }

    // ---- the stored look -------------------------------------------------------------------------------------------------------------------------------

    public function test_the_migration_turns_buttons_into_links_and_back(): void
    {
        DB::table('catalog_attribute_definitions')->where('id', 2)->update(['filter_display' => 'buttons']);
        $migration = require base_path('packages/EasyCo/Catalog/database/migrations/2026_10_30_000001_change_filter_display_buttons_to_links.php');

        $migration->up();
        $this->assertSame('links', DB::table('catalog_attribute_definitions')->where('id', 2)->value('filter_display'));
        $this->assertSame(0, DB::table('catalog_attribute_definitions')->where('filter_display', 'buttons')->count());

        $migration->down();
        $this->assertSame('buttons', DB::table('catalog_attribute_definitions')->where('id', 2)->value('filter_display'));
        $this->assertSame(['list', 'links', 'swatches'], array_map(static fn (\EasyCo\Catalog\Enums\FilterDisplay $d): string => $d->value, \EasyCo\Catalog\Enums\FilterDisplay::cases()));
    }
}
