<?php

namespace Tests\Feature\Storefront;

use App\Http\Controllers\Storefront\DesignPreviewController;
use App\Storefront\Home\HomeCatalog;
use App\Storefront\Home\HomeDrafts;
use App\Storefront\Home\HomeValidator;
use App\Storefront\Reader\ImageSetBuilder;
use EasyCo\Catalog\Persistence\Eloquent\SeasonModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * HP-4: the «Продуктова решетка» section of the home page: its closed lists, the entity and the limit of two (validated on save, publish and read), the sources (newest, category, season, tag, brand: only the
 * products the shop shows), the markup (the SAME card as the category listing, closed classes, the `sizes` of the component's columns and gap), an empty grid that prints nothing, the pinned query counts
 * (0, 1 and 2 grids, independent of the number of products), the draft and the signed preview.
 */
class HomeProductGridTest extends TestCase
{
    use BuildsShippingZones;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->showSoldOutProducts();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->withoutVite();
        app()->setLocale('en');
        config(['app.url' => 'https://shop.test', 'app.name' => 'Raf Shop']);
        $this->seedPricing();
    }

    /** @param  array<string, mixed>  $settings */
    private function grid(array $settings = []): array
    {
        return ['type' => 'product_grid', 'settings' => $settings];
    }

    /** @param  list<array<string, mixed>>  $sections */
    private function publish(array $sections): void
    {
        [$clean, $errors] = HomeValidator::validate(['spacing' => '32', 'sections' => $sections]);
        $this->assertSame([], $errors, json_encode($errors));
        HomeDrafts::publish($clean);
    }

    private function home(): string
    {
        $this->app->forgetScopedInstances();

        return $this->get('/')->assertOk()->getContent();
    }

    private function queries(): int
    {
        $this->home();
        $n = 0;
        DB::listen(function () use (&$n): void {
            $n++;
        });
        $this->home();

        return $n;
    }

    /** @return list<string> the names of the cards, in the order printed */
    private function names(string $html): array
    {
        preg_match_all('/<h3 class="sf-card-name">([^<]*)<\/h3>/', $html, $m);

        return array_map('html_entity_decode', $m[1]);
    }

    /** @param  list<string>  $names */
    private function products(array $names, array $o = []): void
    {
        foreach ($names as $i => $name) {
            $this->makeProduct($i + 1, ['name' => $name, 'slug' => 'p'.($i + 1), 'price' => '10.00', 'stock' => 3, 'timeline' => '2026-01-'.sprintf('%02d', $i + 1).' 10:00:00'] + $o);
        }
    }

    // ---- ★ validation ---------------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: list<array<string, mixed>>, 1: string}> */
    public static function refused(): array
    {
        $g = static fn (array $settings): array => ['type' => 'product_grid', 'settings' => $settings];

        return [
            'source' => [[$g(['source' => 'sale'])], 'sections.0.settings.source'],
            'source array' => [[$g(['source' => ['newest']])], 'sections.0.settings.source'],
            'category without entity' => [[$g(['source' => 'category'])], 'sections.0.settings.entity'],
            'season without entity' => [[$g(['source' => 'season', 'entity' => null])], 'sections.0.settings.entity'],
            'entity zero' => [[$g(['source' => 'tag', 'entity' => 0])], 'sections.0.settings.entity'],
            'entity negative' => [[$g(['source' => 'brand', 'entity' => -3])], 'sections.0.settings.entity'],
            'entity text' => [[$g(['source' => 'brand', 'entity' => '-3'])], 'sections.0.settings.entity'],
            'entity float' => [[$g(['source' => 'brand', 'entity' => 1.5])], 'sections.0.settings.entity'],
            'entity array' => [[$g(['source' => 'brand', 'entity' => [1]])], 'sections.0.settings.entity'],
            'count 7' => [[$g(['count' => '7'])], 'sections.0.settings.count'],
            'count int' => [[$g(['count' => 12])], 'sections.0.settings.count'],
            'count 28' => [[$g(['count' => '28'])], 'sections.0.settings.count'],
            'sort price' => [[$g(['sort' => 'price_asc'])], 'sections.0.settings.sort'],
            'sort array' => [[$g(['sort' => ['newest']])], 'sections.0.settings.sort'],
            'columns 2' => [[$g(['columns' => '2'])], 'sections.0.settings.columns'],
            'columns 6' => [[$g(['columns' => '6'])], 'sections.0.settings.columns'],
            'columns array' => [[$g(['columns' => ['4']])], 'sections.0.settings.columns'],
            'phone 3' => [[$g(['columns_phone' => '3'])], 'sections.0.settings.columns_phone'],
            'gap 7' => [[$g(['gap' => '7'])], 'sections.0.settings.gap'],
            'gap 12px' => [[$g(['gap' => '12px'])], 'sections.0.settings.gap'],
            'gap int' => [[$g(['gap' => 16])], 'sections.0.settings.gap'],
            'gap array' => [[$g(['gap' => [16]])], 'sections.0.settings.gap'],
            'unknown setting' => [[$g(['featured' => true])], 'sections.0.settings.featured'],
            'third grid' => [[$g([]), $g([]), $g([])], 'sections.2'],
        ];
    }

    /** @param  list<array<string, mixed>>  $sections */
    #[DataProvider('refused')]
    public function test_a_bad_value_is_refused_on_save(array $sections, string $path): void
    {
        [, $errors] = HomeValidator::validate(['spacing' => '32', 'sections' => $sections]);

        $this->assertArrayHasKey($path, $errors, json_encode($errors));
    }

    /** P1: `featured` IS a source now (it was not before; this test was flipped). It needs no item, and a given one is dropped. */
    public function test_featured_is_a_source(): void
    {
        $this->assertContains('featured', HomeCatalog::PRODUCT_SOURCES);
        $this->assertNotContains('featured', HomeCatalog::PRODUCT_SOURCES_WITH_ENTITY);
        [$clean, $errors] = HomeValidator::validate(['spacing' => '32', 'sections' => [$this->grid(['source' => 'featured', 'entity' => 5])]]);

        $this->assertSame([], $errors);
        $this->assertSame('featured', $clean['sections'][0]['settings']['source']);
        $this->assertNull($clean['sections'][0]['settings']['entity']);
    }

    public function test_a_bad_source_still_falls_back_to_the_default_on_read(): void
    {
        [, $errors] = HomeValidator::validate(['spacing' => '32', 'sections' => [$this->grid(['source' => 'featured2'])]]);

        $this->assertArrayHasKey('sections.0.settings.source', $errors);
    }

    public function test_featured_returns_only_flagged_active_visible_products_newest_first(): void
    {
        $this->makeProduct(1, ['name' => 'Old featured', 'slug' => 'a', 'featured' => true, 'price' => '1.00', 'stock' => 1, 'timeline' => '2026-01-01 10:00:00']);
        $this->makeProduct(2, ['name' => 'New featured', 'slug' => 'b', 'featured' => true, 'price' => '1.00', 'stock' => 1, 'timeline' => '2026-01-05 10:00:00']);
        $this->makeProduct(3, ['name' => 'Plain', 'slug' => 'c', 'price' => '1.00', 'stock' => 1, 'timeline' => '2026-01-09 10:00:00']);
        $this->makeProduct(4, ['name' => 'Draft featured', 'slug' => 'd', 'featured' => true, 'status' => 'draft', 'price' => '1.00', 'stock' => 1, 'timeline' => '2026-01-10 10:00:00']);
        $this->makeProduct(5, ['name' => 'Hidden featured', 'slug' => 'e', 'featured' => true, 'visibility' => 'hidden', 'price' => '1.00', 'stock' => 1, 'timeline' => '2026-01-11 10:00:00']);
        $this->makeProduct(6, ['name' => 'Deleted featured', 'slug' => 'f', 'featured' => true, 'deleted' => true, 'price' => '1.00', 'stock' => 1, 'timeline' => '2026-01-12 10:00:00']);
        $this->publish([$this->grid(['source' => 'featured'])]);

        $this->assertSame(['New featured', 'Old featured'], $this->names($this->home()));

        $this->publish([$this->grid(['source' => 'featured', 'sort' => 'name_asc', 'count' => '4'])]);
        $this->assertSame(['New featured', 'Old featured'], $this->names($this->home()));
    }

    public function test_a_featured_grid_costs_the_same_queries_as_a_newest_one(): void
    {
        $this->products(['A', 'B', 'C'], ['featured' => true]);
        $this->publish([$this->grid(['source' => 'newest'])]);
        $newest = $this->queries();
        $this->publish([$this->grid(['source' => 'featured'])]);

        $this->assertSame($newest, $this->queries(), 'a featured grid is pinned to the cost of a newest one');
    }

    public function test_defaults_and_good_values_are_accepted_and_two_grids_are_the_limit(): void
    {
        [$clean, $errors] = HomeValidator::validate(['spacing' => '32', 'sections' => [$this->grid(), $this->grid(['source' => 'season', 'entity' => 4, 'count' => '24', 'sort' => 'name_asc', 'columns' => '5', 'columns_phone' => '1', 'gap' => '0'])]]);

        $this->assertSame([], $errors);
        $this->assertSame(['newest', null, '12', 'newest', '4', '2', '16'], array_map(fn (string $k): mixed => $clean['sections'][0]['settings'][$k], ['source', 'entity', 'count', 'sort', 'columns', 'columns_phone', 'gap']));
        $this->assertSame([4, '24', '5', '1', '0'], array_map(fn (string $k): mixed => $clean['sections'][1]['settings'][$k], ['entity', 'count', 'columns', 'columns_phone', 'gap']));
        $this->assertSame(2, HomeCatalog::MAX_PRODUCT_GRIDS);
    }

    public function test_a_newest_grid_forgets_an_item_it_was_given(): void
    {
        [$clean, $errors] = HomeValidator::validate(['spacing' => '32', 'sections' => [$this->grid(['source' => 'newest', 'entity' => 5])]]);

        $this->assertSame([], $errors);
        $this->assertNull($clean['sections'][0]['settings']['entity']);
    }

    public function test_on_read_each_bad_value_is_its_default_the_third_grid_is_dropped_and_an_unknown_source_drops_the_section(): void
    {
        $bad = ['source' => 'category', 'entity' => 3, 'count' => '7', 'sort' => 'price_desc', 'columns' => '9', 'columns_phone' => '4', 'gap' => '12px'];
        [$clean, $errors] = HomeValidator::validateForRead(['spacing' => '32', 'sections' => [
            $this->grid($bad), $this->grid(), $this->grid(), ['type' => 'heading', 'settings' => ['text' => 'Kept']], $this->grid(['source' => 'sale']),
        ]]);

        $this->assertSame([], $errors, 'nothing makes the layout unusable');
        $this->assertSame(['product_grid', 'product_grid', 'heading'], array_column($clean['sections'], 'type'), 'the third grid and the unknown source are gone');
        $s = $clean['sections'][0]['settings'];
        $this->assertSame(['category', 3, '12', 'newest', '4', '2', '16'], [$s['source'], $s['entity'], $s['count'], $s['sort'], $s['columns'], $s['columns_phone'], $s['gap']]);
    }

    public function test_on_read_a_grid_with_an_unknown_source_is_dropped_and_the_rest_stays(): void
    {
        [$clean, $errors] = HomeValidator::validateForRead(['spacing' => '32', 'sections' => [
            ['type' => 'heading', 'settings' => ['text' => 'Kept']], $this->grid(['source' => 'sale']), $this->grid(['count' => '4']),
        ]]);

        $this->assertSame([], $errors);
        $this->assertSame(['heading', 'product_grid'], array_column($clean['sections'], 'type'));
        $this->assertSame('4', $clean['sections'][1]['settings']['count'], 'the grid that is kept is the second one');
    }

    public function test_a_category_grid_with_a_bad_entity_is_kept_on_read_and_prints_nothing(): void
    {
        [$clean] = HomeValidator::validateForRead(['spacing' => '32', 'sections' => [$this->grid(['source' => 'category', 'entity' => -1])]]);

        $this->assertNull($clean['sections'][0]['settings']['entity']);
    }

    // ---- ★ sources ------------------------------------------------------------------------------------------------------------------------

    public function test_newest_returns_the_newest_visible_products_in_order_and_respects_the_count(): void
    {
        $this->products(['P1', 'P2', 'P3', 'P4', 'P5', 'P6']);
        $this->makeProduct(7, ['name' => 'Inactive', 'slug' => 'inactive', 'status' => 'draft', 'price' => '1.00', 'stock' => 1, 'timeline' => '2026-02-01 10:00:00']);
        $this->makeProduct(8, ['name' => 'Hidden', 'slug' => 'hidden', 'visibility' => 'hidden', 'price' => '1.00', 'stock' => 1, 'timeline' => '2026-02-02 10:00:00']);
        $this->makeProduct(9, ['name' => 'Deleted', 'slug' => 'deleted', 'deleted' => true, 'price' => '1.00', 'stock' => 1, 'timeline' => '2026-02-03 10:00:00']);
        $this->publish([$this->grid(['count' => '4'])]);

        $this->assertSame(['P6', 'P5', 'P4', 'P3'], $this->names($this->home()));
    }

    public function test_the_name_sort_works(): void
    {
        $this->products(['Pear', 'Apple', 'Mango']);
        $this->publish([$this->grid(['sort' => 'name_asc'])]);

        $this->assertSame(['Apple', 'Mango', 'Pear'], $this->names($this->home()));
    }

    public function test_a_category_includes_what_its_own_page_includes(): void
    {
        $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeCategory(2, 'Dresses', 'dresses', 1);
        $this->makeCategory(3, 'Shoes', 'shoes');
        $this->makeProduct(1, ['name' => 'In parent', 'slug' => 'a', 'categories' => [1], 'price' => '1.00', 'stock' => 1, 'timeline' => '2026-01-03 10:00:00']);
        $this->makeProduct(2, ['name' => 'In child', 'slug' => 'b', 'categories' => [2], 'price' => '1.00', 'stock' => 1, 'timeline' => '2026-01-02 10:00:00']);
        $this->makeProduct(3, ['name' => 'Elsewhere', 'slug' => 'c', 'categories' => [3], 'price' => '1.00', 'stock' => 1, 'timeline' => '2026-01-04 10:00:00']);
        $this->publish([$this->grid(['source' => 'category', 'entity' => 1])]);

        $home = $this->names($this->home());
        $page = $this->names($this->get('/product-category/clothing')->assertOk()->getContent());

        $this->assertSame(['In parent', 'In child'], $home);
        $this->assertSame($page, $home, 'the same products, in the same order, as /product-category/clothing');
    }

    public function test_season_tag_and_brand_return_only_their_products(): void
    {
        $season = SeasonModel::query()->create(['name' => 'Winter', 'slug' => 'winter']);
        $this->makeTag(1, 'Sale', 'sale');
        $this->makeBrand(1, 'Acme', 'acme');
        $this->makeProduct(1, ['name' => 'Seasonal', 'slug' => 'a', 'price' => '1.00', 'stock' => 1]);
        DB::table('catalog_products')->where('id', 1)->update(['season_id' => $season->id]);
        $this->makeProduct(2, ['name' => 'Tagged', 'slug' => 'b', 'tags' => [1], 'price' => '1.00', 'stock' => 1]);
        $this->makeProduct(3, ['name' => 'Branded', 'slug' => 'c', 'brand' => 1, 'price' => '1.00', 'stock' => 1]);
        $this->makeProduct(4, ['name' => 'Plain', 'slug' => 'd', 'price' => '1.00', 'stock' => 1]);
        $this->makeProduct(5, ['name' => 'Hidden branded', 'slug' => 'e', 'brand' => 1, 'visibility' => 'hidden', 'price' => '1.00', 'stock' => 1]);

        foreach ([['season', (int) $season->id, 'Seasonal'], ['tag', 1, 'Tagged'], ['brand', 1, 'Branded']] as [$source, $entity, $expected]) {
            $this->publish([$this->grid(['source' => $source, 'entity' => $entity])]);

            $this->assertSame([$expected], $this->names($this->home()), $source);
        }
    }

    public function test_an_out_of_stock_product_follows_the_listings_rule(): void
    {
        $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeProduct(1, ['name' => 'Gone', 'slug' => 'a', 'categories' => [1], 'price' => '1.00', 'stock' => 0]);
        $this->publish([$this->grid()]);

        $this->assertSame(['Gone'], $this->names($this->get('/product-category/clothing')->assertOk()->getContent()), 'the category listing shows it');
        $this->assertSame(['Gone'], $this->names($this->home()));
        $this->assertStringContainsString('Out of stock', $this->home());
    }

    // ---- ★ markup -------------------------------------------------------------------------------------------------------------------------

    public function test_each_product_is_the_same_card_as_in_the_category_listing(): void
    {
        $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeProduct(1, ['name' => 'Dress', 'slug' => 'dress', 'categories' => [1], 'price' => '10.00', 'sale' => '8.00', 'stock' => 2]);
        $this->makeImage(1, 1, 0);
        $this->makeImage(2, 1, 1);
        $this->publish([$this->grid()]);

        preg_match('/<article class="sf-card" data-product-id="1">.*?<\/article>/s', $this->get('/product-category/clothing')->assertOk()->getContent(), $category);
        preg_match('/<article class="sf-card" data-product-id="1">.*?<\/article>/s', $this->home(), $home);

        $this->assertNotEmpty($category);
        $this->assertSame($category[0], $home[0], 'identical card markup (default columns and gap give the same sizes)');
        $this->assertStringContainsString('sf-card-image-hover', $home[0]);
    }

    public function test_the_classes_and_the_sizes_follow_the_settings_and_nothing_inline_is_printed(): void
    {
        $this->products(['A', 'B']);
        $this->makeImage(1, 1, 0);
        $this->publish([$this->grid(['columns' => '5', 'columns_phone' => '1', 'gap' => '24'])]);
        $html = $this->home();

        $this->assertStringContainsString('class="sf-hp-products sf-hp-prc-5 sf-hp-prp-1 sf-hp-prg-24"', $html);
        $this->assertStringContainsString('sizes="'.e(ImageSetBuilder::cardSizes(5, 1, 24)).'"', $html);
        $section = substr($html, (int) strpos($html, 'sf-hp-products'));
        $this->assertStringNotContainsString('style=', substr($html, (int) strpos($html, '<div class="sf-hp')));
        $this->assertDoesNotMatchRegularExpression('/<script[^>]*>[^<]/', $section);
        $this->assertStringNotContainsString('sf-pagination', $html);
    }

    public function test_the_html_is_the_same_for_every_visitor(): void
    {
        $this->products(['A', 'B']);
        $this->publish([$this->grid()]);

        $first = $this->home();
        $this->app->forgetScopedInstances();
        $second = $this->withHeader('Cookie', 'x=1')->get('/')->assertOk()->getContent();

        $this->assertSame($first, $second);
        $this->assertSame([], $this->get('/')->headers->getCookies(), 'no cookie, no session');
    }

    public function test_two_grids_with_a_heading_between_them_keep_the_order_and_the_spacing(): void
    {
        $this->products(['A', 'B', 'C']);
        $this->publish([$this->grid(['count' => '4']), ['type' => 'heading', 'settings' => ['text' => 'Middle']], $this->grid(['sort' => 'name_asc', 'spacing' => '8'])]);
        $html = $this->home();

        $this->assertSame(2, substr_count($html, 'class="sf-hp-products '));
        $this->assertLessThan(strpos($html, 'Middle'), strpos($html, 'sf-hp-products'));
        $this->assertGreaterThan(strpos($html, 'Middle'), strrpos($html, 'sf-hp-products'));
        $this->assertStringContainsString('sf-hp-section sf-hp-product-grid sf-hp-mt-8', $html);
    }

    // ---- ★ an empty grid prints nothing ---------------------------------------------------------------------------------------------------

    public function test_a_grid_with_no_product_prints_nothing_and_the_next_section_is_the_first(): void
    {
        $this->makeCategory(1, 'Empty', 'empty');
        $this->publish([$this->grid(['source' => 'category', 'entity' => 1]), ['type' => 'heading', 'settings' => ['text' => 'After', 'spacing' => '48']]]);
        $html = $this->home();

        $this->assertStringNotContainsString('sf-hp-products', $html);
        $this->assertStringNotContainsString('sf-hp-product-grid', $html);
        $this->assertStringContainsString('After', $html);
        $this->assertStringNotContainsString('sf-hp-mt-48', $html, 'the first section that is printed has no top spacing');
    }

    public function test_a_deleted_entity_and_a_missing_entity_print_nothing(): void
    {
        $this->products(['A']);
        $this->makeBrand(1, 'Acme', 'acme');
        DB::table('catalog_products')->where('id', 1)->update(['brand_id' => 1]);
        $this->publish([$this->grid(['source' => 'brand', 'entity' => 1])]);
        $this->assertSame(['A'], $this->names($this->home()));

        DB::table('catalog_products')->update(['brand_id' => null]);
        DB::table('catalog_brands')->delete();
        $this->assertStringNotContainsString('sf-hp-products', $this->home(), 'a deleted brand');

        foreach (['category', 'season', 'tag', 'brand'] as $source) {
            $this->publish([$this->grid(['source' => $source, 'entity' => 987654])]);
            $this->assertStringNotContainsString('sf-hp-products', $this->home(), $source.' that does not exist');
        }
    }

    public function test_a_category_that_is_not_shown_prints_nothing(): void
    {
        $this->makeCategory(1, 'Hidden cat', 'hidden-cat');
        $this->makeProduct(1, ['name' => 'A', 'slug' => 'a', 'categories' => [1], 'price' => '1.00', 'stock' => 1]);
        $this->publish([$this->grid(['source' => 'category', 'entity' => 1])]);
        $this->assertSame(['A'], $this->names($this->home()));

        // a category with no product the shop shows is not shown (its own page is a 404)
        DB::table('catalog_products')->where('id', 1)->update(['catalog_visibility' => 'hidden']);
        $this->app->forgetScopedInstances();
        $this->get('/product-category/hidden-cat')->assertNotFound();
        $this->assertStringNotContainsString('sf-hp-products', $this->home());
    }

    // ---- ★ queries, draft, preview --------------------------------------------------------------------------------------------------------

    /** The queries of a warm home page with 0, 1 and 2 product grids (the 2 of the layout pipeline, then what ONE card read costs, twice). */
    private const WITHOUT_GRID = 2;

    private const WITH_ONE_GRID = 20;

    private const WITH_TWO_GRIDS = 39;

    public function test_the_home_page_costs_the_pinned_queries_with_0_1_and_2_grids_whatever_the_number_of_products(): void
    {
        $this->makeCategory(1, 'Clothing', 'clothing');
        $counts = [];

        foreach ([5, 24] as $n) {
            DB::table('catalog_product_categories')->delete();
            DB::table('stock_levels')->delete();
            DB::table('catalog_variations')->delete();
            DB::table('catalog_products')->delete();

            for ($i = 1; $i <= $n; $i++) {
                $this->makeProduct(1000 * $n + $i, ['name' => "N{$n}-{$i}", 'slug' => "n{$n}-{$i}", 'categories' => [1], 'price' => '10.00', 'stock' => 3, 'timeline' => '2026-01-01 10:00:'.sprintf('%02d', $i)]);
            }

            $this->makeImage($n * 10, 1000 * $n + 1, 0);
            $this->publish([['type' => 'heading', 'settings' => ['text' => 'Hi']]]);
            $counts[$n]['0'] = $this->queries();
            $this->publish([['type' => 'heading', 'settings' => ['text' => 'Hi']], $this->grid(['count' => '24'])]);
            $counts[$n]['1'] = $this->queries();
            $this->publish([$this->grid(['count' => '24']), $this->grid(['source' => 'category', 'entity' => 1, 'count' => '24'])]);
            $counts[$n]['2'] = $this->queries();
        }

        $this->assertSame($counts[5], $counts[24], 'no query per product');
        $this->assertSame([self::WITHOUT_GRID, self::WITH_ONE_GRID, self::WITH_TWO_GRIDS], array_values($counts[24]), json_encode($counts));
    }

    public function test_the_cards_are_read_without_a_count_query(): void
    {
        $this->products(['A', 'B']);
        $this->publish([$this->grid()]);
        $this->home();
        $sql = [];
        DB::listen(function ($q) use (&$sql): void {
            $sql[] = $q->sql;
        });
        $this->home();

        $this->assertEmpty(array_filter($sql, fn (string $q): bool => str_contains($q, 'count(*)')), 'no count query on the home page');
    }

    public function test_a_draft_never_reaches_the_shop_and_the_signed_preview_shows_it(): void
    {
        $this->products(['Only in the draft']);
        [$draft] = HomeValidator::validate(['spacing' => '16', 'sections' => [$this->grid(['columns' => '5'])]]);
        HomeDrafts::saveDraft($draft);

        $this->assertStringNotContainsString('sf-hp-products', $this->home(), 'the shop still prints the old home page');

        $this->app->forgetScopedInstances();
        $preview = $this->get(DesignPreviewController::previewUrl(HomeCatalog::ZONE))->assertOk();

        $this->assertStringContainsString('Only in the draft', $preview->getContent());
        $this->assertStringContainsString('sf-hp-prc-5', $preview->getContent());
        $preview->assertHeader('X-Robots-Tag', 'noindex');
    }

    public function test_a_price_or_stock_change_shows_at_once_because_the_cards_are_not_compiled(): void
    {
        $this->products(['A']);
        $this->publish([$this->grid()]);
        $this->assertStringNotContainsString('Out of stock', $this->home());

        DB::table('stock_levels')->delete();
        $this->assertStringContainsString('Out of stock', $this->home());
    }
}
