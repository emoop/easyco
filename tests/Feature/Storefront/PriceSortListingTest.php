<?php

namespace Tests\Feature\Storefront;

use App\Services\PriceSort\ProductSortPriceRefresher;
use App\Storefront\Reader\CatalogReader;
use App\Storefront\ReadModels\ListingPage;
use App\Storefront\ReadModels\ListingQuery;
use App\Storefront\ReadModels\ListingSort;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P6a: the listing sorted by the stored price: both directions, a product with no price last in BOTH, ties and pages stable, no query of its own, and the sort by name neither offered nor accepted.
 */
class PriceSortListingTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.public.url' => 'https://cdn.test/storage']);
        $this->seedPricing();
        $this->makeCategory(1, 'Clothing', 'clothing');
    }

    private function product(int $id, string $slug, ?string $price, string $timeline = '2026-01-01 10:00:00'): void
    {
        $this->makeProduct($id, ['slug' => $slug, 'price' => $price, 'stock' => 1, 'timeline' => $timeline, 'categories' => [1]]);
    }

    private function refreshAll(): void
    {
        app(ProductSortPriceRefresher::class)->refresh(DB::table('catalog_products')->pluck('id')->all());
    }

    private function listing(array $request = []): ListingPage
    {
        $this->app->forgetScopedInstances();

        return app(CatalogReader::class)->listing(ListingQuery::fromRequestArray($request));
    }

    /** @return list<string> */
    private function slugs(ListingPage $page): array
    {
        return array_map(fn ($card) => $card->slug, $page->items);
    }

    /** ★ ascending and descending are right, and a product with NO price is LAST in both directions */
    public function test_both_directions_and_the_unpriced_product_last_in_both(): void
    {
        $this->product(1, 'mid', '20.00');
        $this->product(2, 'cheap', '5.00');
        $this->product(3, 'unpriced', null, '2026-06-01 10:00:00'); // the newest: «newest» would put it first
        $this->product(4, 'dear', '90.00');
        $this->refreshAll();

        $this->assertSame(['cheap', 'mid', 'dear', 'unpriced'], $this->slugs($this->listing(['sort' => 'price_asc'])));
        $this->assertSame(['dear', 'mid', 'cheap', 'unpriced'], $this->slugs($this->listing(['sort' => 'price_desc'])));
        $this->assertSame('unpriced', $this->slugs($this->listing())[0], 'the default is still the newest first');
    }

    /** ★ ties are stable: the same price keeps the newest first, then the id (so a page never reorders) */
    public function test_ties_are_stable(): void
    {
        $this->product(1, 'a-old', '10.00', '2026-01-01 10:00:00');
        $this->product(2, 'b-new', '10.00', '2026-03-01 10:00:00');
        $this->product(3, 'c-new-same-time', '10.00', '2026-03-01 10:00:00');
        $this->product(4, 'd-cheaper', '9.00', '2026-02-01 10:00:00');
        $this->refreshAll();

        $this->assertSame(['d-cheaper', 'c-new-same-time', 'b-new', 'a-old'], $this->slugs($this->listing(['sort' => 'price_asc'])));
        $this->assertSame(['c-new-same-time', 'b-new', 'a-old', 'd-cheaper'], $this->slugs($this->listing(['sort' => 'price_desc'])));
    }

    /** ★ the pages of a price sort do not overlap or skip */
    public function test_the_pages_do_not_overlap_or_skip(): void
    {
        for ($i = 1; $i <= 17; $i++) {
            // many equal prices on purpose: only the tie-break keeps the pages apart
            $this->product($i, 'p'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), $i % 4 === 0 ? null : (10 + $i % 3).'.00', '2026-01-'.str_pad((string) (1 + $i % 5), 2, '0', STR_PAD_LEFT).' 10:00:00');
        }
        $this->refreshAll();

        foreach (['price_asc', 'price_desc'] as $sort) {
            $one = $this->slugs($this->listing(['sort' => $sort, 'per_page' => '12', 'page' => '1']));
            $two = $this->slugs($this->listing(['sort' => $sort, 'per_page' => '12', 'page' => '2']));

            $this->assertCount(12, $one);
            $this->assertCount(5, $two);
            $this->assertSame([], array_values(array_intersect($one, $two)), $sort.': no overlap');
            $this->assertCount(17, array_unique(array_merge($one, $two)), $sort.': nothing skipped');
        }
    }

    /** ★ the price sort adds NO query: the same number as the default sort */
    public function test_the_price_sort_adds_no_query(): void
    {
        $this->product(1, 'a', '10.00');
        $this->product(2, 'b', '20.00');
        $this->refreshAll();

        $count = function (array $request): int {
            $this->listing($request); // warm-up
            $n = 0;
            DB::listen(function () use (&$n): void {
                $n++;
            });
            $this->listing($request);

            return $n;
        };

        $newest = $count([]);
        $asc = $count(['sort' => 'price_asc']);
        $desc = $count(['sort' => 'price_desc']);

        fwrite(STDERR, "\n[query-count] listing: newest {$newest}, price_asc {$asc}, price_desc {$desc}\n");
        $this->assertSame($newest, $asc);
        $this->assertSame($newest, $desc);
    }

    /** ★ the sort by name is neither offered nor accepted; the three options are there, in both languages */
    public function test_the_sort_by_name_is_gone_and_the_three_options_have_labels(): void
    {
        $this->assertSame([ListingSort::NEWEST, ListingSort::PRICE_ASC, ListingSort::PRICE_DESC], ListingSort::storefrontOptions());
        $this->assertNotContains(ListingSort::NAME_ASC, ListingSort::storefrontOptions());

        $this->product(1, 'a', '10.00');
        $this->get('/product-category/clothing?sort=name_asc')->assertNotFound();
        $this->get('/product-category/clothing?sort=price_asc')->assertOk();
        $this->get('/product-category/clothing?sort=price_desc')->assertOk();

        $this->assertSame(['newest' => 'Най-нови', 'price_asc' => 'Цена: от ниска към висока', 'price_desc' => 'Цена: от висока към ниска'], trans('storefront.sort', [], 'bg'));
        $this->assertSame(['newest' => 'Newest', 'price_asc' => 'Price: low to high', 'price_desc' => 'Price: high to low'], trans('storefront.sort', [], 'en'));
    }

    /** the price sorts behave like «Най-нови» for the canonical and the robots rules: the canonical has no sort parameter */
    public function test_a_price_sorted_page_has_the_canonical_of_the_plain_page(): void
    {
        $this->product(1, 'a', '10.00');

        $plain = $this->get('/product-category/clothing')->assertOk()->getContent();
        preg_match('#<link rel="canonical" href="([^"]+)"#', $plain, $plainCanonical);

        foreach (['price_asc', 'price_desc'] as $sort) {
            $html = $this->get('/product-category/clothing?sort='.$sort)->assertOk()->getContent();
            preg_match('#<link rel="canonical" href="([^"]+)"#', $html, $canonical);

            $this->assertSame($plainCanonical[1] ?? null, $canonical[1] ?? null, $sort);
            $this->assertStringNotContainsString('sort=', $canonical[1] ?? '');
        }
    }

    /** the home page's product grid keeps its own «name» setting (NAME_ASC stays an internal case for it) */
    public function test_the_home_grid_keeps_the_name_sort_internally(): void
    {
        $this->assertSame([ListingSort::NEWEST, ListingSort::NAME_ASC], ListingSort::homeGridOptions());
    }
}
