<?php

namespace Tests\Feature\Storefront;

use App\Storefront\Filters\FilterCatalog;
use App\Storefront\PageDesign\PageDesign;
use App\Storefront\PageDesign\PageDesignStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * P2: the brand, season and tag pages are the category page for another SUBJECT (`/brand/{slug}`, `/season/{slug}`, `/product-tag/{slug}`): the same listing, filters, sort, pagination, SEO and view; the subject
 * is fixed by the page, so its own kind of filter is not offered and its choice in the URL is ignored.
 *
 * The world: «Clothing» (1) with Colour (attribute 1: Red 1001, Blue 1002); brands Acme (1), Bolt (2), Cora (3, no product); seasons Autumn (1), Winter (2); tags Alpha (1), Beta (2), Gamma (3).
 * P1 red / Acme / Autumn / Alpha+Beta; P2 blue / Acme / Winter / Alpha; P3 red / Bolt / Autumn / Beta+Gamma; P4 blue / Bolt / Winter / Gamma. P5 is Acme but hidden from the catalog.
 */
class SubjectListingPageTest extends TestCase
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

        // hidden from the catalog: never on a listing, whatever its brand
        $this->makeProduct(5, ['name' => 'P5', 'slug' => 'p5', 'brand' => 1, 'visibility' => 'hidden', 'price' => '10.00', 'stock' => 3]);

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

    /** all three built-in filters switched on, so the own-kind rule is visible */
    private function allFiltersOn(): void
    {
        $this->publish(['filterBrand' => 'list', 'filterSeason' => 'list', 'filterTag' => 'list']);
    }

    /** @return list<int> */
    private function ids(string $html): array
    {
        preg_match_all('/data-product-id="(\d+)"/', $html, $m);

        return array_map('intval', $m[1]);
    }

    /** the names of the bar's filter controls (the sort is not one of them) @return list<string> */
    private function barNames(string $html): array
    {
        preg_match_all('#<details class="sf-bctl" name="sf-cbar">(.*?)</details>#s', $html, $controls);

        return array_map(static fn (string $control): string => explode(':', trim(strip_tags((string) (preg_match('#<span class="sf-bctl-text">(.*?)</span></summary>#s', $control, $t) ? $t[1] : ''))))[0], $controls[1]);
    }

    /** the names of the filter groups of the form (the side panel / the phone drawer) @return list<string> */
    private function formNames(string $html): array
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

    // ---- what each page lists ----------------------------------------------------------------------------------------------------------------

    /** ★ the subject is fixed in the product query: only its own visible products (newest first) */
    public function test_each_page_lists_only_the_products_of_its_subject(): void
    {
        $this->assertSame([2, 1], $this->ids($this->visit('/brand/acme')->assertOk()->getContent()), 'the brand: P5 is hidden');
        $this->assertSame([4, 3], $this->ids($this->visit('/brand/bolt')->assertOk()->getContent()));
        $this->assertSame([3, 1], $this->ids($this->visit('/season/autumn')->assertOk()->getContent()));
        $this->assertSame([4, 2], $this->ids($this->visit('/season/winter')->assertOk()->getContent()));
        $this->assertSame([3, 1], $this->ids($this->visit('/product-tag/beta')->assertOk()->getContent()));
        $this->assertSame([4, 3], $this->ids($this->visit('/product-tag/gamma')->assertOk()->getContent()));
    }

    public function test_a_plain_scope_parameter_in_the_url_changes_nothing(): void
    {
        $this->assertSame([2, 1], $this->ids($this->visit('/brand/acme?brand=2&season=2&tag=3&category=1')->assertOk()->getContent()));
    }

    /** ★ the own kind of filter is ignored on its page (no 404, no effect) */
    public function test_the_choice_of_the_own_kind_of_filter_in_the_url_is_ignored(): void
    {
        $this->allFiltersOn();

        $this->assertSame([2, 1], $this->ids($this->visit('/brand/acme?filter[brand]=2')->assertOk()->getContent()));
        $this->assertSame([3, 1], $this->ids($this->visit('/season/autumn?filter[season]=2')->assertOk()->getContent()));
        $this->assertSame([3, 1], $this->ids($this->visit('/product-tag/beta?filter[tag]=3')->assertOk()->getContent()));
        // …and it does not stop another filter of the same URL from working
        $this->assertSame([1], $this->ids($this->visit('/brand/acme?filter[brand]=2&filter[season]=1')->assertOk()->getContent()));
    }

    public function test_the_other_filters_combine_with_the_subject(): void
    {
        $this->allFiltersOn();

        $this->assertSame([1], $this->ids($this->visit('/brand/acme?filter[season]=1')->assertOk()->getContent()));
        $this->assertSame([2], $this->ids($this->visit('/brand/acme?filter[1]=1002')->assertOk()->getContent()), 'an attribute: Blue');
        $this->assertSame([1], $this->ids($this->visit('/season/autumn?filter[brand]=1')->assertOk()->getContent()));
        $this->assertSame([3], $this->ids($this->visit('/product-tag/beta?filter[brand]=2')->assertOk()->getContent()));
    }

    public function test_an_unknown_filter_value_is_still_the_404(): void
    {
        $this->allFiltersOn();

        $this->visit('/brand/acme?filter[season]=99')->assertNotFound();
        $this->visit('/brand/acme?filter[nonsense]=1')->assertNotFound();
        $this->visit('/brand/acme?filter=nonsense')->assertNotFound();
    }

    public function test_sort_and_pagination_work(): void
    {
        $this->assertSame([2, 1], $this->ids($this->visit('/brand/acme')->getContent()));
        // P6a: the price sort orders by the stored price: product 1 is the cheaper one
        \Illuminate\Support\Facades\DB::table('catalog_products')->where('id', 1)->update(['price_from_minor' => 1000]);
        \Illuminate\Support\Facades\DB::table('catalog_products')->where('id', 2)->update(['price_from_minor' => 2000]);
        $this->assertSame([1, 2], $this->ids($this->visit('/brand/acme?sort=price_asc')->assertOk()->getContent()));

        $this->visit('/brand/acme?sort=nonsense')->assertNotFound();
        $this->visit('/brand/acme?page=2')->assertNotFound(); // a page past the last one

        foreach (range(10, 23) as $id) {
            $this->makeProduct($id, ['name' => "B{$id}", 'slug' => "b{$id}", 'brand' => 3, 'price' => '10.00', 'stock' => 3, 'timeline' => sprintf('2026-02-%02d 10:00:00', $id)]);
        }

        $page1 = $this->visit('/brand/cora?per_page=12')->assertOk();
        $this->assertCount(12, $this->ids($page1->getContent()));
        $this->assertStringContainsString('href="/brand/cora?page=2&amp;per_page=12"', $page1->getContent(), 'the pager keeps the page\'s own path');
        $this->assertSame([11, 10], $this->ids($this->visit('/brand/cora?per_page=12&page=2')->assertOk()->getContent()));
    }

    // ---- 404, 301, empty -------------------------------------------------------------------------------------------------------------------

    public function test_an_unknown_or_malformed_slug_is_the_storefronts_404(): void
    {
        foreach (['/brand/nope', '/season/nope', '/product-tag/nope', '/brand/a%20b', '/brand/a.b', '/brand/' . str_repeat('x', 201)] as $url) {
            $this->visit($url)->assertNotFound();
        }

        $this->assertSame($this->visit('/brand/nope')->getContent(), $this->visit('/product-category/nope')->getContent(), 'the same 404 as for a category');
    }

    public function test_a_slug_in_another_case_is_a_301_to_the_canonical_one(): void
    {
        $this->visit('/brand/ACME')->assertStatus(301)->assertRedirect('/brand/acme');
        $this->visit('/season/Winter?sort=price_asc')->assertStatus(301)->assertRedirect('/season/winter?sort=price_asc');
    }

    public function test_a_subject_with_no_visible_products_shows_the_category_empty_state(): void
    {
        $brand = $this->visit('/brand/cora')->assertOk();
        $category = $this->visit('/product-category/clothing')->assertOk();

        $this->assertSame([], $this->ids($brand->getContent()));
        $this->assertStringContainsString('<p class="sf-empty">There are no products here yet.</p>', $brand->getContent());
        $this->assertStringContainsString('Products: 0', $brand->getContent());
        $this->assertStringContainsString('class="sf-listing sf-category', $brand->getContent(), 'the category\'s own markup');
        $this->assertStringContainsString('class="sf-listing sf-category', $category->getContent());
    }

    // ---- the own filter is not offered ------------------------------------------------------------------------------------------------------

    /** ★ absent from the side panel / drawer and from the top bar of its page, present on the others */
    public function test_the_own_kind_of_filter_is_not_offered_on_its_page(): void
    {
        $this->allFiltersOn();

        $category = $this->visit('/product-category/clothing')->assertOk()->getContent();
        $this->assertSame(['Brand', 'Season', 'Colour', 'Tag'], $this->barNames($category));
        $this->assertSame(['Brand', 'Season', 'Colour', 'Tag'], $this->formNames($category));

        $brand = $this->visit('/brand/acme')->assertOk()->getContent();
        $this->assertSame(['Season', 'Colour', 'Tag'], $this->barNames($brand));
        $this->assertSame(['Season', 'Colour', 'Tag'], $this->formNames($brand));
        $this->assertStringNotContainsString('filter[brand]', $brand);

        // (Colour is a filter only while it has two values to show: the products of one season share a colour in this world)
        $season = $this->visit('/season/autumn')->assertOk()->getContent();
        $this->assertSame(['Brand', 'Tag'], $this->barNames($season));
        $this->assertStringNotContainsString('filter[season]', $season);

        $tag = $this->visit('/product-tag/beta')->assertOk()->getContent();
        $this->assertSame(['Brand'], $this->barNames($tag), 'Season is not offered either: both products of Beta are of Autumn, so it has one value to show');
        $this->assertStringNotContainsString('filter[tag]', $tag);
    }

    public function test_the_filter_links_of_a_page_keep_its_own_path_and_the_chosen_filters(): void
    {
        $this->allFiltersOn();

        $html = $this->visit('/brand/acme?filter[season]=1')->assertOk()->getContent();

        $this->assertStringContainsString('href="/brand/acme"', $html, '«Clear» goes back to the page itself');
        $this->assertStringContainsString('action="/brand/acme"', $html);
        $this->assertStringNotContainsString('/product-category/', $html);
    }

    // ---- SEO -------------------------------------------------------------------------------------------------------------------------------

    public function test_title_heading_canonical_and_breadcrumbs_follow_the_subject(): void
    {
        foreach ([
            '/brand/acme' => ['Acme', 'Brands', '/brand/acme'],
            '/season/winter' => ['Winter', 'Seasons', '/season/winter'],
            '/product-tag/beta' => ['Beta', 'Tags', '/product-tag/beta'],
        ] as $url => [$name, $parent, $path]) {
            $html = $this->visit($url)->assertOk()->getContent();

            $this->assertStringContainsString("<title>{$name} — Raf Shop</title>", $html);
            $this->assertStringContainsString('<h1 class="sf-hp-heading-text">'.$name.'</h1>', $html);
            $this->assertStringContainsString('<link rel="canonical" href="https://shop.test'.$path.'">', $html);
            $this->assertStringNotContainsString('name="robots"', $html);
            $this->assertStringContainsString('<li class="sf-breadcrumb"><a href="/">Home</a></li>', $html);
            $this->assertStringContainsString('<li class="sf-breadcrumb">'.$parent.'</li>', $html, 'the parent step is plain text: no index page exists');
            $this->assertStringContainsString('<li class="sf-breadcrumb sf-breadcrumb-current" aria-current="page">'.$name.'</li>', $html);
        }
    }

    public function test_the_canonical_has_no_sort_or_filter_parameters_and_a_filtered_page_is_noindex(): void
    {
        $this->allFiltersOn();

        $sorted = $this->visit('/brand/acme?sort=price_asc')->assertOk()->getContent();
        $this->assertStringContainsString('<link rel="canonical" href="https://shop.test/brand/acme">', $sorted);

        $filtered = $this->visit('/brand/acme?filter[season]=1')->assertOk();
        $filtered->assertHeader('X-Robots-Tag', 'noindex, follow');
        $this->assertStringContainsString('<link rel="canonical" href="https://shop.test/brand/acme">', $filtered->getContent());
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $filtered->getContent());

        $this->visit('/brand/acme')->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_a_name_with_markup_and_quotes_is_escaped_everywhere(): void
    {
        $name = '<script>alert(1)</script> "Q" & \'s\'';
        $this->makeBrand(9, $name, 'evil');
        $this->makeProduct(9, ['name' => 'P9', 'slug' => 'p9', 'brand' => 9, 'price' => '10.00', 'stock' => 3]);
        FilterCatalog::forget();

        $html = $this->visit('/brand/evil')->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &quot;Q&quot; &amp; &#039;s&#039;', $html);
        $this->assertSame(1, preg_match('#<title>(.*?)</title>#', $html, $t));
        $this->assertStringNotContainsString('<script>', $t[1]);
    }

    public function test_the_slug_of_the_url_is_never_printed_raw(): void
    {
        $html = $this->visit('/brand/ACME')->assertStatus(301)->getContent();

        $this->assertStringNotContainsString('ACME', $html, 'a wrong case is a 301 to the stored slug: nothing of the request is echoed');
        $this->assertStringNotContainsString('ACME', $this->visit('/brand/acme')->getContent());
    }

    public function test_the_page_is_the_same_for_every_visitor_and_sets_no_cookie(): void
    {
        $first = $this->visit('/brand/acme')->assertOk();
        $second = $this->visit('/brand/acme')->assertOk();

        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertSame([], $first->headers->getCookies(), 'no session, no cookie');
    }

    // ---- cost ------------------------------------------------------------------------------------------------------------------------------

    /** ★ the same listing costs the same as on a category page: the subject read is one indexed query, cheaper than the category's own */
    public function test_a_subject_page_costs_no_more_queries_than_the_category_page_and_the_cost_is_pinned(): void
    {
        $this->allFiltersOn();
        $this->makeCategory(2, 'Acme only', 'acme-only');
        DB::table('catalog_product_categories')->insert([['product_id' => 1, 'category_id' => 2], ['product_id' => 2, 'category_id' => 2]]);

        $category = $this->queryCount('/product-category/acme-only');
        $brand = $this->queryCount('/brand/acme');
        $season = $this->queryCount('/season/autumn');
        $tag = $this->queryCount('/product-tag/beta');

        fwrite(STDERR, "\n[query-count] subject pages: brand {$brand}, season {$season}, tag {$tag}; the same listing as a category: {$category} (pinned ceiling 27 each)\n");

        $this->assertLessThanOrEqual($category, $brand, 'the same listing as the category page, minus one facet (its own filter)');
        $this->assertLessThanOrEqual(27, max($brand, $season, $tag));
        $this->assertSame($this->queryCount('/brand/acme'), $this->queryCount('/brand/acme'), 'a repeat costs the same');
    }

    public function test_the_own_filter_costs_no_facet_query(): void
    {
        $this->allFiltersOn();
        $this->visit('/product-category/clothing')->assertOk(); // warm the filter cache

        $queries = [];
        DB::listen(function ($q) use (&$queries): void {
            $queries[] = $q->sql;
        });
        $this->visit('/brand/acme')->assertOk();

        $this->assertCount(0, array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'distinct `catalog_products`.`brand_id`') || str_contains($sql, 'distinct `catalog_products`.`brand_id`') || preg_match('/select distinct .?catalog_products.?\.?.?brand_id/i', $sql) === 1), 'no facet query for the brand on a brand page');
    }
}
