<?php

namespace Tests\Feature\Storefront\Seo;

use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/** SEO-1.1 A and B: the index pages /brand and /collection — which rows, the order and the letters, the pictures, the 404s, the escaping, the SEO tags, the statement count. */
class SubjectIndexPagesTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    private const COLLECTION_TABLE = 'catalog_collections';

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        config(['app.url' => 'https://shop.test', 'app.name' => 'Raf Shop', 'easyco.noindex' => false]);
        app()->setLocale('bg');
        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();
        $this->makeCategory(1, 'Clothing', 'clothing');
    }

    /** A product of the given brand / collections (visible, active, in stock unless told otherwise). */
    private function product(int $id, array $o = []): int
    {
        return $this->makeProduct($id, $o + ['name' => "Product {$id}", 'slug' => "p-{$id}", 'categories' => [1], 'price' => '10.00', 'stock' => 3]);
    }

    private function brand(int $id, string $name, string $slug, ?int $logoMedia = null): int
    {
        $this->makeBrand($id, $name, $slug);

        if ($logoMedia !== null) {
            DB::table('catalog_brands')->where('id', $id)->update(['logo_media_asset_id' => $logoMedia]);
        }

        return $id;
    }

    private function collection(int $id, string $name, string $slug, ?int $pictureMedia = null): int
    {
        DB::table(self::COLLECTION_TABLE)->insert(['id' => $id, 'name' => $name, 'slug' => $slug, 'picture_media_asset_id' => $pictureMedia, 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);

        return $id;
    }

    private function inCollection(int $productId, int $collectionId): void
    {
        DB::table('catalog_product_collections')->insert(['product_id' => $productId, 'collection_id' => $collectionId]);
    }

    private function switchCollections(bool $on): void
    {
        app(SiteSettingsRepository::class)->set('catalog.collections_enabled', $on ? '1' : '0');
    }

    /** The names of the tiles on a page, in print order. @return list<string> */
    private function tileNames(string $html): array
    {
        preg_match_all('#<span class="sf-subject-name">(.*?)</span>#s', $html, $m);

        return array_map(static fn (string $name): string => html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $m[1]);
    }

    // ---- /brand: the rows ----------------------------------------------------------------------------------------------------------

    public function test_a_brand_is_listed_only_with_a_visible_product_and_the_others_are_left_out(): void
    {
        $this->brand(1, 'Zara', 'zara');
        $this->product(1, ['brand' => 1]);
        $this->brand(2, 'Empty', 'empty');                              // no product at all
        $this->brand(3, 'Hidden only', 'hidden-only');
        $this->product(2, ['brand' => 3, 'visibility' => 'hidden']);     // its only product is not shown
        $this->brand(4, 'Draft only', 'draft-only');
        $this->product(3, ['brand' => 4, 'status' => 'draft']);

        $html = $this->get('/brand')->assertOk()->getContent();

        $this->assertSame(['Zara'], $this->tileNames($html));
        // the brand's own page is still reachable (it answers 200 whatever its products): the index is stricter, the page is not changed
        $this->get('/brand/empty')->assertOk();
    }

    public function test_with_no_listed_brand_the_page_is_a_404(): void
    {
        $this->brand(1, 'Empty', 'empty');

        $this->get('/brand')->assertNotFound();
    }

    public function test_the_brands_are_sorted_in_bulgarian_and_grouped_by_letter_with_a_letter_bar(): void
    {
        foreach ([[1, 'Яка', 'yaka'], [2, 'Бета', 'beta'], [3, 'Ana', 'ana'], [4, 'Zara', 'zara'], [5, 'Ана', 'ana-bg'], [6, '1st Class', 'first']] as [$id, $name, $slug]) {
            $this->brand($id, $name, $slug);
            $this->product($id, ['brand' => $id]);
        }

        $html = $this->get('/brand')->assertOk()->getContent();

        // Bulgarian collation inside the Cyrillic names (Бета before Яка), Latin among themselves; a name that starts with a digit is the last group
        $names = $this->tileNames($html);
        $this->assertLessThan(array_search('Яка', $names, true), array_search('Бета', $names, true), 'Бета before Яка');
        $this->assertLessThan(array_search('Zara', $names, true), array_search('Ana', $names, true), 'Ana before Zara');
        $this->assertSame('1st Class', end($names), 'the digit group last');

        // the letter bar is plain anchors into the groups
        $this->assertStringContainsString('<nav class="sf-letters" aria-label="Азбучен указател">', $html);
        $this->assertSame(1, preg_match_all('#<a href="\#a">A</a>#', $html));
        $this->assertStringContainsString('<section class="sf-subject-group" id="a">', $html);
        $this->assertStringContainsString('<section class="sf-subject-group" id="other">', $html);
        $this->assertStringContainsString('>Други</a>', $html, 'the digit group is named «Други» in the bar');
    }

    public function test_a_brand_with_a_logo_is_a_picture_tile_and_one_without_is_a_name_only_tile(): void
    {
        $this->product(1, ['brand' => $this->brand(1, 'Logo brand', 'logo-brand')]);
        $this->product(2, ['brand' => $this->brand(2, 'Plain brand', 'plain-brand')]);
        $this->makeImage(7, 1);
        DB::table('catalog_brands')->where('id', 1)->update(['logo_media_asset_id' => 7]);

        $html = $this->get('/brand')->assertOk()->getContent();

        $logo = substr($html, strpos($html, 'Logo brand') - 900, 900);
        $plain = substr($html, strpos($html, 'Plain brand') - 300, 300);
        $this->assertStringContainsString('<span class="sf-subject-pic">', $logo);
        $this->assertStringNotContainsString('sf-image-placeholder', $html, 'a brand never gets the placeholder');
        $this->assertStringNotContainsString('sf-subject-pic', $plain, 'no logo: name only');
        $grid = substr($html, strpos($html, 'class="sf-subject-grid"'));
        $this->assertSame(0, preg_match('#<img[^>]*alt="[^"]+"#', $grid), 'the picture alt is empty: the caption names the link');
    }

    public function test_the_names_are_escaped_and_no_script_or_style_or_handler_reaches_the_page(): void
    {
        $evil = '<script>alert(1)</script> "Q" & \'x\'';
        $this->product(1, ['brand' => $this->brand(1, $evil, 'evil-brand')]);

        $html = $this->get('/brand')->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertSame([$evil], $this->tileNames($html), 'the real name after parsing');
        $this->assertStringNotContainsString(' style=', $html);
        $this->assertSame(0, preg_match('#<(?!script type="application/ld\+json")script#i', $html));
        // the letter of a name that starts with `<` is the «other» group
        $this->assertStringContainsString('id="other"', $html);
    }

    // ---- /brand: the URL, the SEO tags ------------------------------------------------------------------------------------------

    public function test_the_canonical_is_the_bare_path_and_the_query_string_never_reaches_it(): void
    {
        $this->product(1, ['brand' => $this->brand(1, 'Zara', 'zara')]);

        $html = $this->get('/brand?sort=newest&page=2&utm=x')->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="https://shop.test/brand">', $html);
        $this->assertStringNotContainsString('utm=x', $html);
        $this->assertStringContainsString('<meta property="og:url" content="https://shop.test/brand">', $html);
        $this->assertStringContainsString('<meta property="og:type" content="website">', $html);
        $this->assertStringContainsString('<meta property="og:locale" content="bg_BG">', $html);
        $this->assertStringContainsString('<title>Марки — Raf Shop</title>', $html);
    }

    public function test_the_breadcrumb_json_ld_is_home_then_the_page_without_a_link(): void
    {
        $this->product(1, ['brand' => $this->brand(1, 'Zara', 'zara')]);

        $html = $this->get('/brand')->assertOk()->getContent();
        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $crumbs = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR)['@graph'][0];

        $this->assertSame('BreadcrumbList', $crumbs['@type']);
        $this->assertSame(['Начало', 'Марки'], array_column($crumbs['itemListElement'], 'name'));
        $this->assertSame('https://shop.test/', $crumbs['itemListElement'][0]['item']);
        $this->assertArrayNotHasKey('item', $crumbs['itemListElement'][1]);
        // the visible trail: the last step is the current page, not a link
        $this->assertStringContainsString('<li class="sf-breadcrumb sf-breadcrumb-current" aria-current="page">Марки</li>', $html);
    }

    public function test_under_the_noindex_switch_there_are_no_share_or_structured_data_tags_and_the_robots_tag_is_noindex(): void
    {
        $this->product(1, ['brand' => $this->brand(1, 'Zara', 'zara')]);
        config(['easyco.noindex' => true]);

        $html = $this->get('/brand')->assertOk()->getContent();

        $this->assertStringContainsString('<meta name="robots" content="noindex">', $html);
        $this->assertStringNotContainsString('rel="canonical"', $html);
        $this->assertStringNotContainsString('og:', $html);
        $this->assertStringNotContainsString('application/ld+json', $html);
    }

    // ---- /brand: the statement count does not depend on the number of rows ---------------------------------------------------------

    public function test_the_index_costs_the_same_statements_with_one_brand_and_with_thirty(): void
    {
        $this->product(1, ['brand' => $this->brand(1, 'Brand 1', 'brand-1')]);
        $this->makeImage(900, 1);
        DB::table('catalog_brands')->where('id', 1)->update(['logo_media_asset_id' => 900]);
        $one = $this->statements('/brand');

        for ($i = 2; $i <= 30; $i++) {
            $this->product($i, ['brand' => $this->brand($i, "Brand {$i}", "brand-{$i}")]);
            $this->makeImage(900 + $i, $i);
            DB::table('catalog_brands')->where('id', $i)->update(['logo_media_asset_id' => 900 + $i]);
        }

        $many = $this->statements('/brand');

        fwrite(STDERR, "\n[query-count] /brand index: 1 brand = {$one}, 30 brands = {$many}\n");
        $this->assertSame($one, $many, 'a fixed number of statements, not N+1');
    }

    // ---- /collection ----------------------------------------------------------------------------------------------------------------------

    public function test_with_the_collections_switch_off_the_index_is_a_404_whatever_the_rows(): void
    {
        $this->switchCollections(false);
        $this->inCollection($this->product(1), $this->collection(1, 'Summer', 'summer'));

        $this->get('/collection')->assertNotFound();
        $this->get('/collection/summer')->assertNotFound();
    }

    public function test_with_the_switch_on_the_collections_with_a_visible_product_are_listed_by_name(): void
    {
        $this->switchCollections(true);
        $this->inCollection($this->product(1), $this->collection(1, 'Зима', 'zima'));
        $this->inCollection($this->product(2), $this->collection(2, 'Лято', 'liato'));
        $this->collection(3, 'Empty collection', 'empty-collection');
        $this->inCollection($this->product(3, ['visibility' => 'hidden']), $this->collection(4, 'Hidden', 'hidden'));

        $html = $this->get('/collection')->assertOk()->getContent();

        $this->assertSame(['Зима', 'Лято'], $this->tileNames($html), 'by name, Bulgarian collation (З before Л in the alphabet, so Зима first); empty and hidden-only left out');
        $this->assertStringContainsString('href="/collection/zima"', $html);
        $this->assertStringNotContainsString('sf-letters', $html, 'no letter bar for collections');
        $this->assertStringContainsString('<link rel="canonical" href="https://shop.test/collection">', $html);
    }

    public function test_with_no_listed_collection_the_page_is_a_404(): void
    {
        $this->switchCollections(true);
        $this->collection(1, 'Empty', 'empty');

        $this->get('/collection')->assertNotFound();
    }

    public function test_a_collection_tile_takes_its_own_picture_else_a_visible_products_picture_else_the_placeholder(): void
    {
        $this->switchCollections(true);
        // own picture (media 500 on product 1), no picture but a product with one (product 2 has media 501), no picture at all (product 3)
        // the media rows first (a picture of a product), then the collections that reference them (a foreign key)
        $this->product(1);
        $this->product(2);
        $this->product(3);
        $this->makeImage(500, 1);
        $this->makeImage(501, 2);
        $this->inCollection(1, $this->collection(1, 'Own', 'own', 500));
        $this->inCollection(2, $this->collection(2, 'From product', 'from-product'));
        $this->inCollection(3, $this->collection(3, 'Nothing', 'nothing'));

        $html = $this->get('/collection')->assertOk()->getContent();

        $this->assertStringContainsString('/p/500-medium.webp', $html, 'its own picture');
        $this->assertStringContainsString('/p/501-medium.webp', $html, 'the picture of a visible product');
        $this->assertSame(1, substr_count($html, 'sf-subject-pic sf-image-placeholder'), 'only the one with nothing gets the placeholder');
        $grid = substr($html, strpos($html, 'class="sf-subject-grid"'));
        $this->assertSame(0, preg_match('#<img[^>]*src="[^"]*"[^>]*alt="[^"]+"#', $grid), 'alt stays empty');
    }

    public function test_a_collection_name_with_markup_is_escaped_on_its_tile(): void
    {
        $this->switchCollections(true);
        $this->inCollection($this->product(1), $this->collection(1, '<b onclick="x()">Гол</b>', 'gol'));

        $html = $this->get('/collection')->assertOk()->getContent();

        $this->assertStringNotContainsString('<b onclick', $html);
        $this->assertStringContainsString('&lt;b onclick=&quot;x()&quot;&gt;Гол&lt;/b&gt;', $html);
    }

    public function test_the_collection_statements_do_not_depend_on_the_number_of_collections(): void
    {
        $this->switchCollections(true);
        $this->inCollection($this->product(1), $this->collection(1, 'One', 'one'));
        $one = $this->statements('/collection');

        for ($i = 2; $i <= 30; $i++) {
            $this->inCollection($this->product($i), $this->collection($i, "Collection {$i}", "collection-{$i}"));
        }

        $many = $this->statements('/collection');

        fwrite(STDERR, "\n[query-count] /collection index: 1 collection = {$one}, 30 collections = {$many}\n");
        $this->assertSame($one, $many);
    }

    /** The statements of ONE request, cold (the cache and the scoped instances cleared first). */
    private function statements(string $url): int
    {
        Cache::flush();
        $this->app->forgetScopedInstances();
        $n = 0;
        DB::listen(function () use (&$n): void {
            $n++;
        });
        $this->get($url)->assertOk();

        return $n;
    }
}
