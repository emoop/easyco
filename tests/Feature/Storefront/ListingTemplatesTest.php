<?php

namespace Tests\Feature\Storefront;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Storefront\Content\ContentCompiler;
use App\Storefront\Content\ContentSet;
use App\Storefront\Content\ContentStore;
use App\Storefront\Content\ContentTemplate;
use App\Storefront\Filters\FilterCatalog;
use App\Storefront\Home\HomeValidator;
use App\Storefront\Menu\MenuRecompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * P3: the templates of the listing pages («Дизайн» → «Съдържание»): a default per kind, a page's OWN template that replaces it completely, the title's quick-navigation cloud, the block above the list and the block or
 * carousel below it (page 1 only), the carousel source `current`, validation on read, and the cost.
 *
 * The world: «Clothing» (1) with «Dresses» (2) and «Coats» (3); «Shoes» (4). Brands Acme (1), Bolt (2), Zed (3); season Autumn (1); tag Alpha (1).
 * P1 Acme Dresses; P2 Acme Dresses; P3 Acme Coats; P4 Bolt Coats; P5 Acme Shoes but HIDDEN; P6 Acme Shoes but DRAFT; P7 Bolt Dresses. Dresses holds 3 visible products, Coats 2, Shoes none.
 */
class ListingTemplatesTest extends TestCase
{
    use BuildsShippingZones;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    private const CLOTHING = '/product-category/clothing';

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
        $this->makeCategory(2, 'Dresses', 'dresses', 1);
        $this->makeCategory(3, 'Coats', 'coats', 1);
        $this->makeCategory(4, 'Shoes', 'shoes');

        foreach ([1 => 'Acme', 2 => 'Bolt', 3 => 'Zed'] as $id => $name) {
            $this->makeBrand($id, $name, strtolower($name));
        }

        DB::table('catalog_seasons')->insert(['id' => 1, 'name' => 'Autumn', 'slug' => 'autumn', 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);
        $this->makeTag(1, 'Alpha', 'alpha');

        $products = [
            1 => ['brand' => 1, 'categories' => [2]], 2 => ['brand' => 1, 'categories' => [2]], 3 => ['brand' => 1, 'categories' => [3]], 4 => ['brand' => 2, 'categories' => [3]],
            5 => ['brand' => 1, 'categories' => [4], 'visibility' => 'hidden'], 6 => ['brand' => 1, 'categories' => [4], 'status' => 'draft'], 7 => ['brand' => 2, 'categories' => [2]],
        ];

        foreach ($products as $id => $o) {
            $this->makeProduct($id, $o + ['name' => "P{$id}", 'slug' => "p{$id}", 'price' => '10.00', 'stock' => 3, 'timeline' => sprintf('2026-01-%02d 10:00:00', $id)]);
        }

        DB::table('catalog_products')->whereIn('id', [3, 4])->update(['season_id' => 1]);
        DB::table('catalog_product_tags')->insert([['product_id' => 1, 'tag_id' => 1], ['product_id' => 3, 'tag_id' => 1]]);

        FilterCatalog::forget();
    }

    private function visit(string $url): TestResponse
    {
        $this->app->forgetScopedInstances();

        return $this->get($url);
    }

    /** A block with a visible marker in its text. */
    private function block(string $marker): HtmlBlockModel
    {
        return HtmlBlockModel::query()->create(['title' => $marker, 'body' => "<p>{$marker}</p>"]);
    }

    /** @return array<string, mixed> */
    private function template(bool $cloud = false, ?HtmlBlockModel $top = null, ?array $bottom = null, bool $title = true, string $heading = ''): array
    {
        return ['cloud' => $cloud, 'title' => $title, 'top' => $top?->id, 'bottom' => $bottom, 'bottom_heading' => $heading];
    }

    private function bottomBlock(HtmlBlockModel $block): array
    {
        return ['type' => 'html_block', 'html_block' => $block->id];
    }

    private function bottomCarousel(string $source = 'current', array $extra = []): array
    {
        return ['type' => 'carousel', 'settings' => ['kind' => 'products', 'source' => $source, 'entity' => null, 'count' => '12', 'sort' => 'newest', 'arrows' => true, 'label' => ''] + $extra];
    }

    /** publishes a set built from `kind => ['default' => template|null, 'own' => [id => template]]` (the others blank) through the real validator and store */
    private function publish(array $kinds, ?array $style = null): void
    {
        $set = ContentSet::blank();
        $style === null || $set['style'] = $style;

        foreach ($kinds as $kind => $entry) {
            $set[$kind] = ['default' => $entry['default'] ?? null, 'own' => $entry['own'] ?? []];
        }

        [$clean, $errors] = ContentSet::validate($set, ContentStore::existing());
        $this->assertSame([], $errors, json_encode($errors));
        ContentStore::publish($clean);
        $this->app->forgetScopedInstances();
    }

    /** @return list<int> */
    private function ids(string $html): array
    {
        preg_match_all('/data-product-id="(\d+)"/', $html, $m);

        return array_map('intval', $m[1]);
    }

    /** @return list<string> the names in the cloud, in order (empty = no cloud markup) */
    private function cloud(string $html): array
    {
        if (! preg_match('#<nav class="sf-subcategories sf-cloud [^"]*"[^>]*>(.*?)</nav>#s', $html, $nav)) {
            return [];
        }

        preg_match_all('#<li(?: class="sf-subcategory")?><a (?:class="sf-bv-chip" )?href="[^"]*">(.*?)</a></li>#s', $nav[1], $m);

        return array_map(static fn (string $name): string => html_entity_decode($name, ENT_QUOTES | ENT_HTML5), $m[1]);
    }

    /** the products of the bottom carousel @return list<int> */
    private function carouselIds(string $html): array
    {
        return preg_match('#<section class="sf-car[^"]*"[^>]*data-carousel>(.*?)</section>#s', $html, $m) ? $this->ids($m[1]) : [];
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

    /** the page as the P2 view prints it, from the data the real response was rendered with */
    private function baseline(TestResponse $response): string
    {
        return view()->file(base_path('tests/Fixtures/storefront/category-p2.blade.php'), $response->original->getData())->render();
    }

    // ---- ★ nothing saved = today's pages ---------------------------------------------------------------------------------------------------

    /** ★ with no template saved, or a set that says nothing, every listing page prints exactly the bytes the P2 view printed */
    public function test_with_no_template_every_listing_page_is_byte_identical(): void
    {
        $urls = [self::CLOTHING, '/product-category/clothing/dresses', '/brand/acme', '/season/autumn', '/product-tag/alpha', '/brand/zed', self::CLOTHING.'?sort=name_asc', '/brand/acme?filter[season]=1'];

        foreach ($urls as $url) {
            $response = $this->visit($url)->assertOk();
            $this->assertSame($this->baseline($response), $response->getContent(), $url);
        }

        // a published set that holds only blank templates prints nothing either
        $this->publish(['categories' => ['default' => $this->template()], 'brands' => ['own' => [1 => $this->template()]]]);

        foreach ($urls as $url) {
            $response = $this->visit($url)->assertOk();
            $this->assertSame($this->baseline($response), $response->getContent(), 'blank set: '.$url);
        }
    }

    /** ★ the same queries as before (nothing is read for a page that has no template) */
    public function test_with_no_template_the_query_counts_are_what_they_were(): void
    {
        $before = [];

        foreach ([self::CLOTHING, '/brand/acme', '/season/autumn', '/product-tag/alpha'] as $url) {
            $this->visit($url);
            $before[$url] = $this->queryCount($url);
        }

        $this->publish(['categories' => ['default' => null], 'brands' => ['own' => [2 => $this->template(true)]]]);

        foreach ($before as $url => $count) {
            $this->visit($url);
            $this->assertSame($count, $this->queryCount($url), $url.': a template for ANOTHER page changes nothing');
        }
    }

    // ---- ★ default, own, back to the default ------------------------------------------------------------------------------------------------

    /** ★ the kind's default applies to every page of the kind; an own template replaces it COMPLETELY (no mixing); removing it brings the default back */
    public function test_the_default_applies_an_own_template_replaces_it_whole_and_removing_it_restores_the_default(): void
    {
        $topA = $this->block('TOPDEFAULT');
        $bottomA = $this->block('BOTTOMDEFAULT');
        $own = $this->block('TOPOWN');
        $this->publish(['categories' => ['default' => $this->template(true, $topA, $this->bottomBlock($bottomA))]]);

        foreach (['/product-category/clothing/dresses', '/product-category/clothing/coats', self::CLOTHING] as $url) {
            $html = $this->visit($url)->assertOk()->getContent();
            $this->assertStringContainsString('TOPDEFAULT', $html, $url);
            $this->assertStringContainsString('BOTTOMDEFAULT', $html, $url);
        }

        // the brands are another kind: untouched
        $this->assertStringNotContainsString('TOPDEFAULT', $this->visit('/brand/acme')->getContent());

        // Coats gets its OWN template: only a top block of its own. The default's bottom block and its cloud are GONE from that page, not inherited
        $this->publish(['categories' => ['default' => $this->template(true, $topA, $this->bottomBlock($bottomA)), 'own' => [3 => $this->template(false, $own)]]]);

        $coats = $this->visit('/product-category/clothing/coats')->assertOk()->getContent();
        $this->assertStringContainsString('TOPOWN', $coats);
        $this->assertStringNotContainsString('TOPDEFAULT', $coats);
        $this->assertStringNotContainsString('BOTTOMDEFAULT', $coats, 'no mixing: the own template has no bottom block');
        $this->assertSame([], $this->cloud($coats), 'and no cloud');

        $dresses = $this->visit('/product-category/clothing/dresses')->getContent();
        $this->assertStringContainsString('TOPDEFAULT', $dresses, 'the other page still has the default');

        // an own template WITHOUT a top block shows no top block even though the default has one
        $this->publish(['categories' => ['default' => $this->template(true, $topA, $this->bottomBlock($bottomA)), 'own' => [3 => $this->template()]]]);
        $coats = $this->visit('/product-category/clothing/coats')->getContent();
        $this->assertStringNotContainsString('TOPDEFAULT', $coats);
        $this->assertStringNotContainsString('BOTTOMDEFAULT', $coats);

        // «Върни към общия»: the page's own template is removed (published without it)
        $this->publish(['categories' => ['default' => $this->template(true, $topA, $this->bottomBlock($bottomA))]]);
        $coats = $this->visit('/product-category/clothing/coats')->getContent();
        $this->assertStringContainsString('TOPDEFAULT', $coats);
        $this->assertStringContainsString('BOTTOMDEFAULT', $coats);
    }

    // ---- ★ order and page 1 ----------------------------------------------------------------------------------------------------------------

    /** ★ title + cloud → top block → listing → bottom block; the blocks on page 1 only, the title and the cloud on every page */
    public function test_the_order_and_the_blocks_on_page_one_only(): void
    {
        foreach (range(10, 23) as $id) {
            $this->makeProduct($id, ['name' => "Z{$id}", 'slug' => "z{$id}", 'brand' => 3, 'price' => '10.00', 'stock' => 3, 'categories' => [2], 'timeline' => sprintf('2026-02-%02d 10:00:00', $id)]);
        }

        $top = $this->block('TOPBLOCKTEXT');
        $bottom = $this->block('BOTTOMBLOCKTEXT');
        $this->publish(['brands' => ['default' => $this->template(true, $top, $this->bottomBlock($bottom))]]);

        $one = $this->visit('/brand/zed?per_page=12')->assertOk()->getContent();
        $heading = strpos($one, '<h1 class="sf-hp-heading-text">');
        $cloud = strpos($one, 'sf-cloud');
        $topAt = strpos($one, 'TOPBLOCKTEXT');
        $firstCard = strpos($one, 'data-product-id');
        $bottomAt = strpos($one, 'BOTTOMBLOCKTEXT');

        $this->assertTrue($heading < $cloud && $cloud < $topAt && $topAt < $firstCard && $firstCard < $bottomAt, 'title + cloud, the top block, the listing, the bottom block');
        $this->assertSame(['Dresses'], $this->cloud($one));

        $two = $this->visit('/brand/zed?per_page=12&page=2')->assertOk()->getContent();
        $this->assertSame(['Dresses'], $this->cloud($two), 'the cloud is on every page');
        $this->assertStringNotContainsString('TOPBLOCKTEXT', $two);
        $this->assertStringNotContainsString('BOTTOMBLOCKTEXT', $two);
        $this->assertStringContainsString('<h1 class="sf-hp-heading-text">Zed</h1>', $two);

        // sort and a filter do not hide the blocks on page 1
        $this->assertStringContainsString('TOPBLOCKTEXT', $this->visit('/brand/zed?per_page=12&sort=name_asc')->getContent());
    }

    // ---- ★ the cloud -----------------------------------------------------------------------------------------------------------------------

    /** ★ a category's cloud is its sub-categories, the most products first, then by name */
    public function test_a_category_cloud_is_its_sub_categories_by_product_count(): void
    {
        $this->publish(['categories' => ['default' => $this->template(true)]]);

        $html = $this->visit(self::CLOTHING)->assertOk()->getContent();

        $this->assertSame(['Dresses', 'Coats'], $this->cloud($html), 'Dresses 3 products, Coats 2');
        $this->assertStringContainsString('aria-label="Subcategories"', $html);
        $this->assertStringNotContainsString('<nav class="sf-subcategories" ', $html, 'the cloud takes the place of the plain list');
        $this->assertSame([], $this->cloud($this->visit('/product-category/clothing/coats')->getContent()), 'Coats has no sub-category: no markup at all');
        $this->assertStringNotContainsString('sf-subcategories', $this->visit('/product-category/clothing/coats')->getContent());
    }

    /** ★ a brand's cloud: only categories that hold VISIBLE products of the brand (a hidden or draft product brings its category in no more); the cloud of a season and of a tag the same */
    public function test_a_brand_cloud_holds_only_categories_with_visible_products_of_the_brand(): void
    {
        $this->publish(['brands' => ['default' => $this->template(true)], 'seasons' => ['default' => $this->template(true)], 'tags' => ['default' => $this->template(true)]]);

        $this->assertSame(['Dresses', 'Coats'], $this->cloud($this->visit('/brand/acme')->assertOk()->getContent()), 'Acme: 2 in Dresses, 1 in Coats; Shoes holds only a hidden and a draft Acme product');
        $this->assertSame(['Coats', 'Dresses'], $this->cloud($this->visit('/brand/bolt')->getContent()), 'Bolt: 1 in Coats and 1 in Dresses: a tie is by name');
        $this->assertSame([], $this->cloud($this->visit('/brand/zed')->getContent()), 'a brand with no product: no cloud markup');
        $this->assertSame(['Coats'], $this->cloud($this->visit('/season/autumn')->getContent()));
        $this->assertSame(['Coats', 'Dresses'], $this->cloud($this->visit('/product-tag/alpha')->getContent()));
        $this->assertStringContainsString('aria-label="Categories"', $this->visit('/brand/acme')->getContent());
    }

    /** ★ at most 12 links, the most products first */
    public function test_the_cloud_has_at_most_twelve_links_by_count_then_name(): void
    {
        foreach (range(1, 13) as $n) {
            $id = 100 + $n;
            $this->makeCategory($id, sprintf('Cat %02d', $n), "cat-{$n}");
            $this->makeProduct($id, ['name' => "X{$n}", 'slug' => "x{$n}", 'brand' => 3, 'categories' => [$id], 'price' => '10.00', 'stock' => 3]);
        }

        // Cat 13 gets a second product: it must be first
        $this->makeProduct(200, ['name' => 'X200', 'slug' => 'x200', 'brand' => 3, 'categories' => [113], 'price' => '10.00', 'stock' => 3]);
        $this->publish(['brands' => ['default' => $this->template(true)]]);

        $cloud = $this->cloud($this->visit('/brand/zed')->assertOk()->getContent());

        $this->assertCount(12, $cloud);
        $this->assertSame('Cat 13', $cloud[0]);
        $this->assertSame(['Cat 13', 'Cat 01', 'Cat 02', 'Cat 03'], array_slice($cloud, 0, 4));
        $this->assertNotContains('Cat 12', $cloud, 'the 13th by order is cut');
    }

    /** ★ the names are escaped */
    public function test_a_name_with_markup_is_escaped_in_the_cloud(): void
    {
        $name = '<script>alert(1)</script> "Q" & \'s\'';
        $this->makeCategory(5, $name, 'evil', 1);
        $this->makeProduct(50, ['name' => 'P50', 'slug' => 'p50', 'brand' => 1, 'categories' => [5], 'price' => '10.00', 'stock' => 3]);
        $this->publish(['categories' => ['default' => $this->template(true)], 'brands' => ['default' => $this->template(true)]]);

        foreach ([self::CLOTHING, '/brand/acme'] as $url) {
            $html = $this->visit($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('<script>alert(1)', $html, $url);
            $this->assertContains($name, $this->cloud($html), $url);
        }
    }

    /** ★ the cost: a category's cloud is free (the category index is already loaded), a brand's is ONE query; and the whole page with the full template is pinned */
    public function test_the_cloud_costs_nothing_or_one_query_and_the_full_template_is_pinned(): void
    {
        $top = $this->block('T');
        $bottom = $this->block('B');

        $this->visit(self::CLOTHING);
        $plainCategory = $this->queryCount(self::CLOTHING);
        $plainBrand = $this->queryCount('/brand/acme');

        $this->publish(['categories' => ['default' => $this->template(true)], 'brands' => ['default' => $this->template(true)]]);
        $this->visit(self::CLOTHING);
        $this->assertSame($plainCategory, $this->queryCount(self::CLOTHING), 'a category cloud: no query of its own');
        $this->assertSame($plainBrand + 1, $this->queryCount('/brand/acme'), 'a brand cloud: ONE grouped query');

        $this->publish(['brands' => ['default' => $this->template(true, $top, $this->bottomCarousel())]]);
        $this->visit('/brand/acme');
        $full = $this->queryCount('/brand/acme');

        fwrite(STDERR, "\n[query-count] brand page: plain {$plainBrand}, with cloud {$plainBrand}+1, with the full template (cloud + top block + carousel «Тази страница») {$full} (pinned ceiling 43)\n");

        // the cloud is 1; the carousel «Тази страница» is ONE product-card read (the cards, their photos, variations, stock and prices, batched), the same read a home product carousel makes: about as much as the list itself.
        // A block costs none (its HTML is in the compiled copy).
        $this->assertLessThanOrEqual(43, $full);
        $this->assertGreaterThanOrEqual($plainBrand + 1, $full);
    }

    // ---- ★ the carousel «Тази страница» ------------------------------------------------------------------------------------------------------

    /** ★ the products of the page itself, whatever the visitor's filters */
    public function test_the_carousel_current_shows_the_products_of_the_page(): void
    {
        $this->publish(['brands' => ['default' => $this->template(false, null, $this->bottomCarousel())], 'categories' => ['default' => $this->template(false, null, $this->bottomCarousel())], 'seasons' => ['default' => $this->template(false, null, $this->bottomCarousel())]]);

        $this->assertEqualsCanonicalizing([1, 2, 3], $this->carouselIds($this->visit('/brand/acme')->assertOk()->getContent()), 'Acme: P1, P2, P3 (P5 hidden, P6 draft)');
        $this->assertEqualsCanonicalizing([4, 7], $this->carouselIds($this->visit('/brand/bolt')->getContent()));
        $this->assertEqualsCanonicalizing([1, 2, 7], $this->carouselIds($this->visit('/product-category/clothing/dresses')->getContent()));
        $this->assertEqualsCanonicalizing([3, 4], $this->carouselIds($this->visit('/season/autumn')->getContent()));

        // the visitor's filter narrows the LIST, not the carousel
        $filtered = $this->visit('/brand/acme?filter[season]=1')->assertOk()->getContent();
        $this->assertEqualsCanonicalizing([1, 2, 3], $this->carouselIds($filtered));
    }

    /** ★ `current` exists only in this zone: the home page's validator refuses it, and so does a stored home layout */
    public function test_the_source_current_is_refused_on_the_home_page(): void
    {
        $layout = ['spacing' => '32', 'sections' => [['type' => 'carousel', 'settings' => ['kind' => 'products', 'source' => 'current']]]];

        [, $errors] = HomeValidator::validate($layout);
        $this->assertArrayHasKey('sections.0.settings.source', $errors);

        [$clean, $errors] = HomeValidator::validate($layout, true);
        $this->assertSame([], $errors, 'in the «Съдържание» zone it is valid');
        $this->assertSame('current', $clean['sections'][0]['settings']['source']);

        // the zone takes the kinds that need no picture only
        [, $errors] = HomeValidator::validate(['spacing' => '32', 'sections' => [['type' => 'carousel', 'settings' => ['kind' => 'images']]]], true);
        $this->assertNotSame([], $errors);

        // the closed list of the home page's sources never held it
        $this->assertNotContains('current', \App\Storefront\Home\HomeCatalog::PRODUCT_SOURCES);
    }

    // ---- ★ validation ----------------------------------------------------------------------------------------------------------------------

    /** ★ an own template of a page that is gone is dropped on read: the default is used, nothing fails */
    public function test_an_own_template_of_a_deleted_page_is_ignored(): void
    {
        $top = $this->block('TOPDEFAULT');
        $own = $this->block('TOPOWN');
        $this->publish(['categories' => ['default' => $this->template(false, $top), 'own' => [4 => $this->template(false, $own)]]]);

        DB::table('catalog_categories')->where('id', 4)->delete();

        $this->assertSame([], ContentStore::values()['categories']['own'], 'the ghost is not listed');
        $this->assertStringContainsString('TOPDEFAULT', $this->visit('/product-category/clothing/dresses')->assertOk()->getContent());
        $this->visit('/product-category/shoes')->assertNotFound();

        // a published copy that names a subject that is not there is refused on SAVE
        [, $errors] = ContentSet::validate(['categories' => ['default' => null, 'own' => [99 => $this->template()]]], ContentStore::existing());
        $this->assertArrayHasKey('categories.own', $errors);
    }

    /** ★ read never fails: a bad slot is null, a bad key is dropped, an unknown source drops the carousel, a cloud that is not a real boolean is off */
    public function test_a_stored_template_is_cleaned_on_read(): void
    {
        $this->assertSame(ContentTemplate::blank(), ContentTemplate::read('nonsense'));
        $this->assertSame(ContentTemplate::blank(), ContentTemplate::read(['cloud' => 'yes', 'top' => 'x', 'bottom' => ['type' => 'script'], 'junk' => 1]));
        $this->assertSame(['cloud' => true, 'title' => true, 'top' => 5, 'bottom' => null, 'bottom_heading' => ''], ContentTemplate::read(['cloud' => true, 'top' => 5, 'bottom' => ['type' => 'carousel', 'settings' => ['kind' => 'products', 'source' => 'sale']]]), 'an unknown source drops the bottom slot only');
        $this->assertSame(ContentTemplate::blank(), ContentTemplate::read(['bottom' => ['type' => 'carousel', 'settings' => ['kind' => 'brands']]]), 'a carousel of logos is not offered here');

        // strict on save
        $errors = [];
        ContentTemplate::validate(['cloud' => 'yes', 'top' => 'x', 'bottom' => ['type' => 'nope'], 'junk' => 1], 'categories.default', $errors);
        $this->assertArrayHasKey('categories.default', $errors);
        $this->assertArrayHasKey('categories.default.cloud', $errors);
        $this->assertArrayHasKey('categories.default.top', $errors);
        $this->assertArrayHasKey('categories.default.bottom', $errors);

        // a hand-edited compiled copy: only the template a page prints is judged, and a bad value costs that slot
        $compiled = ['blocks' => [], 'kinds' => ['categories' => ['default' => ['cloud' => true, 'top' => 'x', 'bottom' => 7, 'zzz' => 1], 'own' => ['2' => 'garbage']]]];
        $this->assertSame(['cloud' => true, 'title' => true, 'top' => null, 'bottom' => null, 'bottom_heading' => ''], ContentCompiler::template($compiled, 'categories', 3));
        $this->assertSame(ContentTemplate::blank(), ContentCompiler::template($compiled, 'categories', 2), 'garbage as an own template is a blank one, not the default');
        $this->assertNull(ContentCompiler::template($compiled, 'brands', 1));
    }

    // ---- freshness and protection -----------------------------------------------------------------------------------------------------------

    public function test_a_changed_block_reaches_the_pages_and_a_block_in_use_is_not_deleted(): void
    {
        $top = $this->block('BEFORETEXT');
        $this->publish(['categories' => ['default' => $this->template(false, $top)]]);
        $this->assertStringContainsString('BEFORETEXT', $this->visit(self::CLOTHING)->getContent());

        $top->update(['body' => '<p>AFTERTEXT</p>']);
        app(MenuRecompiler::class)->now();

        $html = $this->visit(self::CLOTHING)->getContent();
        $this->assertStringContainsString('AFTERTEXT', $html);
        $this->assertStringNotContainsString('BEFORETEXT', $html);

        $this->assertTrue(\App\Content\HtmlBlocks\HtmlBlockUsage::content((int) $top->id));
        $this->assertNotNull(\App\Content\HtmlBlocks\HtmlBlockUsage::refusal($top));
        $this->assertFalse($top->delete(), 'the block is shown by a template: it is not deleted');
    }

    /** the category's OWN banner keeps the top place: the template's top block does not join it */
    public function test_a_category_banner_keeps_the_top_place(): void
    {
        $banner = $this->block('CATEGORYBANNER');
        $top = $this->block('TEMPLATETOP');
        DB::table('catalog_categories')->where('id', 2)->update(['header_block_id' => $banner->id]);
        $this->publish(['categories' => ['default' => $this->template(false, $top)]]);

        $dresses = $this->visit('/product-category/clothing/dresses')->getContent();
        $this->assertStringContainsString('CATEGORYBANNER', $dresses);
        $this->assertStringNotContainsString('TEMPLATETOP', $dresses);
        $this->assertStringContainsString('TEMPLATETOP', $this->visit('/product-category/clothing/coats')->getContent());
    }

    public function test_the_page_is_the_same_for_every_visitor_and_sets_no_cookie(): void
    {
        $this->publish(['brands' => ['default' => $this->template(true, $this->block('TOPTEXT'), $this->bottomCarousel())]]);

        $first = $this->visit('/brand/acme')->assertOk();
        $second = $this->visit('/brand/acme')->assertOk();

        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertSame([], $first->headers->getCookies());
        $this->assertStringNotContainsString('style=', substr($first->getContent(), (int) strpos($first->getContent(), 'TOPTEXT') - 200, 400));
    }

    // ---- P3.1 ------------------------------------------------------------------------------------------------------------------------------------

    /** the markup of the title area (the head div), whitespace collapsed */
    private function titleArea(string $html): string
    {
        $this->assertSame(1, preg_match('#<div class="sf-cat-head[^"]*">.*?</div>(?=\s*(?:<div class="sf-cat-banner"|<nav|<div class="sf-hp |<div class="sf-cat-body"))#s', $html, $m), 'the title area');

        return trim((string) preg_replace('/\s+/', ' ', $m[0]));
    }

    /** ★ the cloud is printed ONLY when the template's cloud is on: no template and cloud off print no sub-category markup at all */
    public function test_without_the_cloud_switched_on_no_sub_category_links_are_printed(): void
    {
        foreach ([self::CLOTHING, '/brand/acme', '/season/autumn', '/product-tag/alpha'] as $url) {
            $html = $this->visit($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('sf-subcategor', $html, 'no template: '.$url);
            $this->assertStringNotContainsString('sf-cloud', $html, 'no template: '.$url);
        }

        // a template with the cloud OFF (a top block, say) does not bring the old list back
        $this->publish(['categories' => ['default' => $this->template(false, $this->block('SOMETOP'))]]);
        $html = $this->visit(self::CLOTHING)->getContent();
        $this->assertStringContainsString('SOMETOP', $html);
        $this->assertStringNotContainsString('sf-subcategor', $html);
        $this->assertStringNotContainsString('sf-cloud', $html);

        // switched on: printed
        $this->publish(['categories' => ['default' => $this->template(true)]]);
        $this->assertSame(['Dresses', 'Coats'], $this->cloud($this->visit(self::CLOTHING)->getContent()));
    }

    /** ★ the shape «Като сайта» (default) = the side panel's button chips (the cards' corner radius); «Заоблени» = today's rounded chips; a bad stored value is «Като сайта» */
    public function test_the_cloud_shape_is_the_sidebar_buttons_by_default_and_rounded_on_request(): void
    {
        $this->publish(['categories' => ['default' => $this->template(true)]]);
        $site = $this->visit(self::CLOTHING)->getContent();

        $this->assertStringContainsString('<nav class="sf-subcategories sf-cloud sf-cloud-site"', $site);
        $this->assertStringContainsString('class="sf-subcategory-list sf-bctl-values-buttons"', $site);
        $this->assertStringContainsString('<a class="sf-bv-chip" href="/product-category/clothing/dresses">Dresses</a>', $site);
        $this->assertStringNotContainsString('class="sf-subcategory"', $site);

        // the chip takes its corners from the cards' radius variable (square when the cards have none): the same rule the side panel's buttons use
        $css = (string) file_get_contents(base_path('resources/css/storefront.css'));
        $this->assertMatchesRegularExpression('/\.sf-bv-chip,\s*\.sf-fv-chip \.sf-fv-text \{[^}]*border-radius: var\(--sf-card-radius\);/s', $css);

        $this->publish(['categories' => ['default' => $this->template(true)]], ['cloud_shape' => 'pill', 'title_style' => 'lines']);
        $pill = $this->visit(self::CLOTHING)->getContent();
        $this->assertStringContainsString('<nav class="sf-subcategories sf-cloud sf-cloud-pill"', $pill);
        $this->assertStringContainsString('<li class="sf-subcategory"><a href="/product-category/clothing/dresses">Dresses</a></li>', $pill);
        $this->assertStringNotContainsString('sf-bv-chip', $pill);

        // a bad stored value: read as «Като сайта»; refused on save
        $this->assertSame('site', ContentSet::read(['style' => ['cloud_shape' => 'triangle']])['style']['cloud_shape']);
        [, $errors] = ContentSet::validate(['style' => ['cloud_shape' => 'triangle']], ContentStore::existing());
        $this->assertArrayHasKey('style.cloud_shape', $errors);
        $compiled = ['blocks' => [], 'style' => ['cloud_shape' => '<x>', 'title_style' => 'neon'], 'kinds' => []];
        $this->assertSame(['cloud_shape' => 'site', 'title_style' => 'lines'], ContentCompiler::published((string) json_encode(['zones' => ['content' => $compiled]]))['style']);
    }

    /** ★ the cloud's wrapper carries the class that hides it on phones, and the CSS rule sits under the filter drawer's breakpoint */
    public function test_the_cloud_is_hidden_below_the_drawer_breakpoint(): void
    {
        $this->publish(['categories' => ['default' => $this->template(true)]]);
        $this->assertStringContainsString('class="sf-subcategories sf-cloud ', $this->visit(self::CLOTHING)->getContent());

        $css = (string) file_get_contents(base_path('resources/css/storefront.css'));
        $this->assertMatchesRegularExpression('/@media \(max-width: 63\.999rem\) \{\s*\.sf-cloud \{\s*display: none;\s*\}\s*\}/', $css);
        // 63.999rem is the breakpoint of the filter drawer (its trigger appears, the sort drop-down goes)
        $this->assertMatchesRegularExpression('/@media \(max-width: 63\.999rem\) \{\s*\.sf-ftrigger \{/', $css);
    }

    /** ★ «Покажи заглавието» off: the <h1> stays (visually hidden, no lines) and nothing else prints the name in the title area; on = as today */
    public function test_the_title_can_be_hidden_for_the_eye_but_stays_for_search_engines(): void
    {
        $this->publish(['categories' => ['default' => $this->template(false, $this->block('MYTOP'), null, false)]]);
        $off = $this->visit('/product-category/clothing/dresses')->assertOk()->getContent();

        $this->assertSame('<div class="sf-cat-head"> <h1 class="sf-title sf-sr">Dresses</h1> </div>', $this->titleArea($off));
        $this->assertSame(1, substr_count($off, '<h1'));
        $this->assertStringNotContainsString('sf-hp-lines', $off, 'no lines around a hidden title');
        $this->assertStringContainsString('MYTOP', $off);

        $this->publish(['categories' => ['default' => $this->template(false, $this->block('MYTOP'))]]);
        $on = $this->visit('/product-category/clothing/dresses')->getContent();
        $this->assertStringContainsString('<h1 class="sf-hp-heading-text">Dresses</h1>', $on);
        $this->assertStringNotContainsString('sf-sr', $this->titleArea($on));

        // an own template replaces the default completely, the title switch too
        $this->publish(['categories' => ['default' => $this->template(false, null, null, false), 'own' => [3 => $this->template()]]]);
        $this->assertStringContainsString('sf-hp-heading-text">Coats', $this->visit('/product-category/clothing/coats')->getContent());
        $this->assertStringContainsString('sf-title sf-sr">Dresses', $this->visit('/product-category/clothing/dresses')->getContent());
    }

    /** ★ the title style: «С линии» (default) = the home heading's own markup on the one <h1>; «Обикновено» = today's plain title; a bad value = «С линии» */
    public function test_the_title_style_is_the_home_heading_by_default_and_plain_on_request(): void
    {
        $lines = $this->visit('/brand/acme')->assertOk()->getContent();
        $this->assertStringContainsString('<div class="sf-hp-heading-row sf-hp-h2 sf-hp-lines sf-hp-l1">', $lines);
        $this->assertStringContainsString('<h1 class="sf-hp-heading-text">Acme</h1>', $lines);
        $this->assertSame(1, substr_count($lines, '<h1'));
        $this->assertSame(2, substr_count($lines, 'class="sf-hp-line"'));

        $this->publish([], ['cloud_shape' => 'site', 'title_style' => 'plain']);

        foreach ([self::CLOTHING, '/brand/acme', '/season/autumn', '/product-tag/alpha'] as $url) {
            $plain = $this->visit($url)->assertOk()->getContent();
            $this->assertStringContainsString('<h1 class="sf-title">', $plain, $url);
            $this->assertStringNotContainsString('sf-hp-lines', $plain, $url);
        }

        $this->assertSame('lines', ContentSet::read(['style' => ['title_style' => 'fancy']])['style']['title_style']);
        [, $errors] = ContentSet::validate(['style' => ['title_style' => 'fancy']], ContentStore::existing());
        $this->assertArrayHasKey('style.title_style', $errors);

        // the category's own banner still hides the heading (the banner says it), whatever the style
        DB::table('catalog_categories')->where('id', 2)->update(['header_block_id' => $this->block('BANNER')->id]);
        $this->publish([], ['cloud_shape' => 'site', 'title_style' => 'lines']);
        $this->assertStringContainsString('<h1 class="sf-title sf-sr">Dresses</h1>', $this->visit('/product-category/clothing/dresses')->getContent());
    }

    /** ★ the heading above the bottom slot: the HOME heading partial's markup, only when set AND the bottom slot is printed (page 1), escaped */
    public function test_the_heading_above_the_bottom_slot(): void
    {
        foreach (range(10, 23) as $id) {
            $this->makeProduct($id, ['name' => "Z{$id}", 'slug' => "z{$id}", 'brand' => 3, 'price' => '10.00', 'stock' => 3, 'categories' => [2], 'timeline' => sprintf('2026-02-%02d 10:00:00', $id)]);
        }

        $bottom = $this->block('BOTTOMTEXT');
        $heading = '<i>New</i> & "more"';
        $this->publish(['brands' => ['default' => $this->template(false, null, $this->bottomBlock($bottom), true, $heading)]]);

        $one = $this->visit('/brand/zed?per_page=12')->assertOk()->getContent();
        $expected = trim(view('storefront.partials.home.heading', ['item' => ['settings' => ['text' => $heading, 'level' => 'h2', 'lines' => 'lines', 'thickness' => '1']]])->render());

        $this->assertStringContainsString($expected, $one, 'the home heading partial, the text escaped by it');
        $this->assertStringContainsString('&lt;i&gt;New&lt;/i&gt; &amp; &quot;more&quot;', $one);
        $this->assertStringNotContainsString('<i>New', $one);
        $this->assertTrue(strpos($one, '<h2 class="sf-hp-heading-text">') < strpos($one, 'BOTTOMTEXT'), 'above the bottom block');
        $this->assertTrue(strpos($one, 'data-product-id') < strpos($one, '<h2 class="sf-hp-heading-text">'), 'below the list');

        $two = $this->visit('/brand/zed?per_page=12&page=2')->getContent();
        $this->assertStringNotContainsString('<h2 class="sf-hp-heading-text">', $two, 'page 2 has no bottom slot, so no heading');

        // a heading with no bottom slot prints nothing
        $this->publish(['brands' => ['default' => $this->template(false, null, null, true, 'ORPHAN HEADING')]]);
        $this->assertStringNotContainsString('ORPHAN HEADING', $this->visit('/brand/zed?per_page=12')->getContent());

        // saved strictly: at most 80 characters, no control characters
        $errors = [];
        ContentTemplate::validate($this->template(false, null, null, true, str_repeat('я', 81)), 'brands.default', $errors);
        $this->assertArrayHasKey('brands.default.bottom_heading', $errors);
        $errors = [];
        $clean = ContentTemplate::validate($this->template(false, null, null, true, str_repeat('я', 80)), 'brands.default', $errors);
        $this->assertSame([], $errors);
        $this->assertSame(80, mb_strlen($clean['bottom_heading']));
        $errors = [];
        ContentTemplate::validate(['bottom_heading' => "a\u{0007}b"] + $this->template(), 'brands.default', $errors);
        $this->assertArrayHasKey('brands.default.bottom_heading', $errors);
        $this->assertSame('', ContentTemplate::read(['bottom_heading' => str_repeat('x', 81)])['bottom_heading'], 'read: too long = none');
    }

    /** ★ «Брой продукти: N» is on the sort row, after the sort control, at the right; not in the title area any more; the same text, on all four kinds of page */
    public function test_the_product_count_is_on_the_sort_row(): void
    {
        foreach ([self::CLOTHING => 5, '/brand/acme' => 3, '/season/autumn' => 2, '/product-tag/alpha' => 2] as $url => $total) {
            $html = $this->visit($url)->assertOk()->getContent();
            $line = '<p class="sf-count sf-count-%s">Products: '.$total.'</p>';

            $head = (string) substr($html, (int) strpos($html, 'sf-cat-head'), (int) strpos($html, 'sf-cat-body') - (int) strpos($html, 'sf-cat-head'));
            $this->assertStringNotContainsString('sf-count', $head, 'not beside the title: '.$url);

            // the bar (a computer in «filters on top»): the sort control, then the count
            $bar = (string) substr($html, (int) strpos($html, '<div class="sf-cbar">'));
            $bar = substr($bar, 0, (int) strpos($bar, 'sf-fdrawer'));
            $this->assertStringContainsString(sprintf($line, 'bar'), $bar, $url);
            $this->assertTrue(strpos($bar, 'sf-bctl-sort') < strpos($bar, sprintf($line, 'bar')), 'the bar: the count after the sort: '.$url);

            // the row above the list (a phone; the side-panel layout): the trigger, then the count with the right-alignment class
            $row = (string) substr($html, (int) strpos($html, '<div class="sf-toolbar'));
            $row = substr($row, 0, (int) strpos($row, 'sf-grid'));
            $this->assertTrue(strpos($row, 'sf-ftrigger') < strpos($row, sprintf($line, 'right')), 'the toolbar: the count after the trigger: '.$url);
        }

        $css = (string) file_get_contents(base_path('resources/css/storefront.css'));
        $this->assertMatchesRegularExpression('/\.sf-count-right \{\s*margin-left: auto;\s*text-align: right;/', $css);
        $this->assertMatchesRegularExpression('/\.sf-count-bar \{\s*grid-column: -2 \/ -1;\s*grid-row: 1;\s*justify-self: end;/', $css);
    }
}
