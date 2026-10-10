<?php

namespace Tests\Feature\Storefront;

use App\Storefront\Reader\CatalogReader;
use App\Storefront\ReadModels\ListingQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The query budgets of storefront-design.md §2.5, pinned with real numbers (printed to STDERR like the sandbox's
 * SandboxProductListQueryCountTest): the count must be <= the budget AND identical for 5 and for 24 products.
 *
 * Between two measurements the scoped instances are forgotten (the reader, the CategoryIndex and the price-range
 * provider memoise per request; a real second request starts fresh).
 */
class StorefrontQueryCountTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    /**
     * The DESIGN budgets (storefront-design.md §2.5) are 14 / 16 / 3. The listing and the product page DO NOT meet
     * theirs, and cannot without changing code outside App\Storefront: price resolution through the mandated services
     * (ProductPriceRangeProvider -> CatalogScopeResolver -> PriceRangeResolver) costs 13 queries on a listing and 13
     * on a product page by itself (CatalogScopeResolver 6 + PriceRangeResolver 7; since S1b cards are priced from the shown variations through the same
     * two services, which removed the provider's own variation read: listing 20 -> 19), whatever the page holds. The numbers below are the MEASURED ceilings, pinned so
     * that the count can never grow; DESIGN_* keep the unmet targets visible (see the skipped test at the bottom).
     */
    public const DESIGN_LISTING_BUDGET = 14;

    public const DESIGN_PRODUCT_BUDGET = 16;

    public const LISTING_BUDGET = 19;

    public const CATEGORY_LISTING_BUDGET = 21;

    public const PRODUCT_BUDGET = 22;

    public const TREE_BUDGET = 3;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.public.url' => 'https://cdn.test/storage']);
        $this->seedPricing();
    }

    /** @return list<string> the SQL of every query run by $work */
    private function queries(callable $work): array
    {
        $this->app->forgetScopedInstances();

        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        $work();

        return $sql;
    }

    /** 24 listed products, each with a brand, an image, categories, tags, a price and stock; every 3rd has variations. */
    private function catalogOf24(): void
    {
        $this->makeAttribute(1, 'Size', ['S', 'M', 'L']);
        $categories = [$this->makeCategory(1, 'Clothing', 'clothing'), $this->makeCategory(2, 'Dresses', 'dresses', 1)];
        $tag = $this->makeTag(1, 'Summer', 'summer');

        for ($i = 1; $i <= 24; $i++) {
            $brand = $this->makeBrand($i, "Brand {$i}", "brand-{$i}");
            $variable = $i % 3 === 0;

            $this->makeProduct($i, [
                'type' => $variable ? 'variable' : 'simple',
                'slug' => "product-{$i}",
                'brand' => $brand,
                'categories' => $categories,
                'tags' => [$tag],
                'timeline' => sprintf('2026-01-%02d 10:00:00', $i),
                'price' => $variable ? null : '10.00',
                'sale' => $i % 4 === 0 && ! $variable ? '8.00' : null,
                'stock' => $i % 5 === 0 ? 0 : 3,
                'variations' => $variable ? [
                    ['attrs' => [1 => 'S'], 'price' => '10.00', 'stock' => 1],
                    ['attrs' => [1 => 'M'], 'price' => '12.00', 'stock' => 0],
                ] : null,
            ]);
            $this->makeImage($i, $i);
        }
    }

    public function test_the_listing_query_count_is_within_budget_and_the_same_for_5_and_24_products(): void
    {
        $this->catalogOf24();
        $query = ListingQuery::fromRequestArray(['per_page' => '24']);

        $countFor24 = $this->queries(fn () => app(CatalogReader::class)->listing($query));

        DB::table('catalog_products')->where('id', '<=', 19)->update(['catalog_visibility' => 'hidden']);

        $countFor5 = $this->queries(function () use ($query, &$page): void {
            $page = app(CatalogReader::class)->listing($query);
        });

        $this->assertSame(5, $page->total);

        fwrite(STDERR, "\n[query-count] storefront listing: 24 products = ".count($countFor24).' queries, 5 products = '.count($countFor5)." queries (pinned ceiling ".self::LISTING_BUDGET.", design budget ".self::DESIGN_LISTING_BUDGET.")\n");

        $this->assertSame(count($countFor24), count($countFor5), 'the listing query count must not grow with the number of products');
        $this->assertLessThanOrEqual(self::LISTING_BUDGET, count($countFor24));
    }

    public function test_a_category_listing_stays_within_budget_and_adds_only_the_category_structure(): void
    {
        $this->catalogOf24();
        $plain = ListingQuery::fromRequestArray([]);
        $scoped = ListingQuery::fromRequestArray(['category' => '1']);

        $plainCount = count($this->queries(fn () => app(CatalogReader::class)->listing($plain)));
        $scopedCount = count($this->queries(fn () => app(CatalogReader::class)->listing($scoped)));

        fwrite(STDERR, "\n[query-count] storefront category listing: {$scopedCount} queries (unscoped {$plainCount}; pinned ceiling ".self::CATEGORY_LISTING_BUDGET.")\n");

        $this->assertLessThanOrEqual(self::CATEGORY_LISTING_BUDGET, $scopedCount);
        $this->assertSame($plainCount + 2, $scopedCount, 'the category structure is exactly 2 queries (categories + visible pairs)');
    }

    public function test_the_product_page_query_count_is_within_budget_and_the_same_for_2_and_12_variations(): void
    {
        $this->catalogOf24();
        $this->makeAttribute(2, 'Colour', ['Red', 'Blue']);

        $small = [['attrs' => [1 => 'S', 2 => 'Red'], 'price' => '10.00', 'stock' => 1], ['attrs' => [1 => 'M', 2 => 'Red'], 'price' => '11.00', 'stock' => 0]];
        $large = [];

        foreach (['S', 'M', 'L'] as $size) {
            foreach (['Red', 'Blue'] as $colour) {
                $large[] = ['attrs' => [1 => $size, 2 => $colour], 'price' => '20.00', 'sale' => '15.00', 'stock' => 2];
                $large[] = ['attrs' => [1 => $size, 2 => $colour], 'price' => '21.00', 'stock' => 0, 'status' => 'draft'];
            }
        }

        $this->makeProduct(101, ['type' => 'variable', 'slug' => 'small', 'brand' => 1, 'categories' => [1, 2], 'variations' => $small]);
        $this->makeProduct(102, ['type' => 'variable', 'slug' => 'large', 'brand' => 2, 'categories' => [1, 2], 'variations' => $large]);

        foreach (range(1, 8) as $n) {
            $this->makeImage(200 + $n, 102, $n);
        }

        $this->makeImage(300, 101, 0);

        $smallPage = null;
        $largePage = null;
        $countSmall = $this->queries(function () use (&$smallPage): void {
            $smallPage = app(CatalogReader::class)->product('small');
        });
        $countLarge = $this->queries(function () use (&$largePage): void {
            $largePage = app(CatalogReader::class)->product('large');
        });

        $this->assertCount(2, $smallPage->variations);
        $this->assertCount(6, $largePage->variations, 'the 6 drafts are not shown');
        $this->assertCount(8, $largePage->images);

        fwrite(STDERR, "\n[query-count] storefront product page: 2 variations/1 image = ".count($countSmall).' queries, 6 variations/8 images = '.count($countLarge)." queries (pinned ceiling ".self::PRODUCT_BUDGET.", design budget ".self::DESIGN_PRODUCT_BUDGET.")\n");

        $this->assertSame(count($countSmall), count($countLarge), 'the product page query count must not grow with variations or images');
        $this->assertLessThanOrEqual(self::PRODUCT_BUDGET, count($countSmall));
    }

    public function test_a_simple_product_page_is_within_budget(): void
    {
        $this->catalogOf24();

        $count = count($this->queries(fn () => app(CatalogReader::class)->product('product-1')));

        fwrite(STDERR, "\n[query-count] storefront product page (simple product): {$count} queries (pinned ceiling ".self::PRODUCT_BUDGET.", design budget ".self::DESIGN_PRODUCT_BUDGET.")\n");

        $this->assertLessThanOrEqual(self::PRODUCT_BUDGET, $count);
    }

    public function test_the_category_tree_and_a_category_page_are_within_budget_and_independent_of_the_number_of_categories(): void
    {
        $this->catalogOf24();
        $treeWith2 = count($this->queries(fn () => app(CatalogReader::class)->categoryTree()));

        foreach (range(10, 40) as $id) {
            $this->makeCategory($id, "Extra {$id}", "extra-{$id}", $id % 2 === 0 ? 1 : null);
            DB::table('catalog_product_categories')->insert(['product_id' => ($id % 24) + 1, 'category_id' => $id]);
        }

        $treeWith33 = count($this->queries(fn () => app(CatalogReader::class)->categoryTree()));
        $pageQueries = count($this->queries(fn () => app(CatalogReader::class)->category('clothing/dresses')));

        fwrite(STDERR, "\n[query-count] storefront category tree: 2 categories = {$treeWith2} queries, 33 categories = {$treeWith33} queries; category page = {$pageQueries} (budget ".self::TREE_BUDGET.")\n");

        $this->assertSame($treeWith2, $treeWith33);
        $this->assertLessThanOrEqual(self::TREE_BUDGET, $treeWith33);
        $this->assertLessThanOrEqual(self::TREE_BUDGET, $pageQueries);
    }

    public function test_a_refused_path_or_slug_runs_no_query_at_all(): void
    {
        $reader = fn () => app(CatalogReader::class);

        $queries = $this->queries(function () use ($reader): void {
            $this->assertNull($reader()->category('a/b/c/d/e/f/g/h/i'));
            $this->assertNull($reader()->category(str_repeat('a', 201)));
            $this->assertNull($reader()->category('a b'));
            $this->assertNull($reader()->product(str_repeat('a', 201)));
            $this->assertNull($reader()->product('a/b'));
            $this->assertNull($reader()->product(''));
        });

        $this->assertSame([], $queries);
    }

    public function test_the_design_budgets_for_the_listing_and_the_product_page_are_met(): void
    {
        $this->markTestSkipped(
            'NOT MET, reported in the S1 report: the design budgets (listing 14, product page 16) are below the cost of price '
            .'resolution alone through ProductPriceRangeProvider/CatalogScopeResolver/PriceRangeResolver (13 queries each, CatalogScopeResolver + PriceRangeResolver). '
            .'Pinned ceilings (19 / 22) hold until the owner decides how to close the gap.'
        );
    }
}
