<?php

namespace Tests\Feature\Storefront\Seo;

use App\Settings\Contracts\SiteSettingsRepository;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/** SEO-1.1 D: the sitemap files of brands, seasons, collections and tags, and the index pages in pages.xml. Each lists exactly the rows of its index page. */
class SubjectSitemapTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    private const SM = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        config(['app.url' => 'https://shop.test', 'app.name' => 'Raf Shop', 'easyco.noindex' => false]);
        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();
        $this->makeCategory(1, 'Clothing', 'clothing');
    }

    private function product(int $id, array $o = []): int
    {
        return $this->makeProduct($id, $o + ['name' => "Product {$id}", 'slug' => "p-{$id}", 'categories' => [1], 'price' => '10.00', 'stock' => 3]);
    }

    private function brand(int $id, string $name, string $slug): int
    {
        $this->makeBrand($id, $name, $slug);

        return $id;
    }

    private function season(int $id, string $name, string $slug): int
    {
        DB::table('catalog_seasons')->insert(['id' => $id, 'name' => $name, 'slug' => $slug, 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);

        return $id;
    }

    private function collection(int $id, string $name, string $slug): int
    {
        DB::table('catalog_collections')->insert(['id' => $id, 'name' => $name, 'slug' => $slug, 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);

        return $id;
    }

    private function switchCollections(bool $on): void
    {
        app(SiteSettingsRepository::class)->set('catalog.collections_enabled', $on ? '1' : '0');
    }

    /** @return list<string> the <loc> values of a urlset file (the file must answer 200, be XML and be well-formed) */
    private function locs(string $url): array
    {
        $response = $this->get($url)->assertOk();
        $this->assertSame('application/xml; charset=UTF-8', $response->headers->get('Content-Type'));

        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($response->getContent()), 'well-formed XML');
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('s', self::SM);

        return array_map(static fn ($node): string => $node->textContent, iterator_to_array($xp->query('//s:url/s:loc')));
    }

    /** @return list<string> the <loc> values of the index */
    private function indexLocs(): array
    {
        $xml = new DOMDocument();
        $xml->loadXML($this->get('/sitemap.xml')->assertOk()->getContent());
        $xp = new DOMXPath($xml);
        $xp->registerNamespace('s', self::SM);

        return array_map(static fn ($node): string => $node->textContent, iterator_to_array($xp->query('//s:sitemap/s:loc')));
    }

    // ---- brands, seasons, tags ---------------------------------------------------------------------------------------------------------------

    public function test_the_brands_file_lists_exactly_the_brands_of_the_index(): void
    {
        $this->product(1, ['brand' => $this->brand(1, 'Zara', 'zara')]);
        $this->brand(2, 'Empty', 'empty');
        $this->product(2, ['brand' => $this->brand(3, 'Hidden only', 'hidden-only'), 'visibility' => 'hidden']);

        $this->assertSame(['https://shop.test/brand/zara'], $this->locs('/sitemap/brands.xml'));
        $this->assertContains('https://shop.test/sitemap/brands.xml', $this->indexLocs());
    }

    public function test_a_cyrillic_brand_slug_is_percent_encoded_in_its_loc(): void
    {
        $this->product(1, ['brand' => $this->brand(1, 'Марка', 'марка')]);

        $this->assertSame(['https://shop.test/brand/%D0%BC%D0%B0%D1%80%D0%BA%D0%B0'], $this->locs('/sitemap/brands.xml'));
    }

    public function test_with_no_listed_brand_the_file_is_a_404_and_is_absent_from_the_index(): void
    {
        $this->brand(1, 'Empty', 'empty');

        $this->get('/sitemap/brands.xml')->assertNotFound();
        $this->assertNotContains('https://shop.test/sitemap/brands.xml', $this->indexLocs());
    }

    public function test_the_seasons_file_lists_the_seasons_with_a_visible_product(): void
    {
        DB::table('catalog_seasons')->insert(['id' => 1, 'name' => 'Лято', 'slug' => 'liato', 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);
        DB::table('catalog_seasons')->insert(['id' => 2, 'name' => 'Empty', 'slug' => 'empty-season', 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);
        $this->product(1);
        DB::table('catalog_products')->where('id', 1)->update(['season_id' => 1]);

        $this->assertSame(['https://shop.test/season/liato'], $this->locs('/sitemap/seasons.xml'));
        $this->assertContains('https://shop.test/sitemap/seasons.xml', $this->indexLocs());
    }

    public function test_the_tags_file_lists_the_tags_with_a_visible_product_at_their_product_tag_page(): void
    {
        $this->makeTag(1, 'Sale', 'sale');
        $this->makeTag(2, 'Unused', 'unused');
        $this->product(1, ['tags' => [1]]);

        $this->assertSame(['https://shop.test/product-tag/sale'], $this->locs('/sitemap/tags.xml'));
        $this->get('/product-tag/sale')->assertOk();
        $this->get('/product-tag/unused')->assertOk(); // its own page answers 200 whatever its products; the index is the stricter rule
    }

    // ---- collections: the switch -----------------------------------------------------------------------------------------------------------

    public function test_with_the_collections_switch_off_the_file_is_a_404_and_absent_from_the_index_and_pages(): void
    {
        $this->switchCollections(false);
        $this->collection(1, 'Summer', 'summer');
        $this->product(1);
        DB::table('catalog_product_collections')->insert(['product_id' => 1, 'collection_id' => 1]);

        $this->get('/sitemap/collections.xml')->assertNotFound();
        $this->assertNotContains('https://shop.test/sitemap/collections.xml', $this->indexLocs());
        $this->assertNotContains('https://shop.test/collection', $this->locs('/sitemap/pages.xml'));
    }

    public function test_with_the_switch_on_the_collections_file_lists_the_listed_collections_and_pages_list_the_index(): void
    {
        $this->switchCollections(true);
        $this->collection(1, 'Summer', 'summer');
        $this->collection(2, 'Empty', 'empty');
        $this->product(1);
        DB::table('catalog_product_collections')->insert(['product_id' => 1, 'collection_id' => 1]);

        $this->assertSame(['https://shop.test/collection/summer'], $this->locs('/sitemap/collections.xml'));
        $this->assertContains('https://shop.test/sitemap/collections.xml', $this->indexLocs());
        $this->assertContains('https://shop.test/collection', $this->locs('/sitemap/pages.xml'));
    }

    public function test_pages_lists_the_brand_index_only_when_there_is_a_brand_to_list(): void
    {
        $this->brand(1, 'Empty', 'empty');
        $this->assertNotContains('https://shop.test/brand', $this->locs('/sitemap/pages.xml'));

        $this->product(1, ['brand' => 1]);
        Cache::flush(); // P8b: the sitemap cache keys carry a version, so the test empties the cache instead of naming one key
        $this->assertContains('https://shop.test/brand', $this->locs('/sitemap/pages.xml'));
    }

    // ---- the noindex switch ----------------------------------------------------------------------------------------------------------------

    public function test_with_the_noindex_switch_every_subject_file_is_a_404(): void
    {
        $this->switchCollections(true);
        $this->product(1, ['brand' => $this->brand(1, 'Zara', 'zara')]);
        DB::table('catalog_product_collections')->insert(['product_id' => 1, 'collection_id' => $this->collection(1, 'Summer', 'summer')]);
        config(['easyco.noindex' => true]);

        foreach (['/sitemap/brands.xml', '/sitemap/seasons.xml', '/sitemap/collections.xml', '/sitemap/tags.xml'] as $url) {
            $this->get($url)->assertNotFound();
        }
    }

    // ---- the statement count ------------------------------------------------------------------------------------------------------------------

    public function test_the_brands_file_costs_the_same_statements_with_one_brand_and_with_thirty(): void
    {
        $this->product(1, ['brand' => $this->brand(1, 'Brand 1', 'brand-1')]);
        $one = $this->statements('/sitemap/brands.xml');

        for ($i = 2; $i <= 30; $i++) {
            $this->product($i, ['brand' => $this->brand($i, "Brand {$i}", "brand-{$i}")]);
        }

        $many = $this->statements('/sitemap/brands.xml');

        fwrite(STDERR, "\n[query-count] sitemap brands.xml: 1 brand = {$one}, 30 brands = {$many}\n");
        $this->assertSame($one, $many, 'the rows in one statement, the same count for any number of rows');
    }

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
