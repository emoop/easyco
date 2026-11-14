<?php

namespace Tests\Feature\Storefront\Search;

use App\Storefront\Search\DatabaseProductSearch;
use App\Storefront\Search\ProductSearch;
use App\Storefront\Search\SearchQuery;
use App\Storefront\Search\SearchResult;
use App\Storefront\Search\SearchSort;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * SRCH-1: the engine (DatabaseProductSearch) and the results page. Matching, visibility (the category listing's own scope), rank, sorts, pagination, cost, escaping, noindex, the rate limit and the interface swap.
 */
class SearchResultsPageTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        config(['app.url' => 'https://shop.test', 'app.name' => 'Raf Shop', 'storefront.search.enabled' => true]);
        app()->setLocale('bg');
        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();
        $this->makeCategory(1, 'Дрехи', 'drehi');
    }

    /** @param  array<string, mixed>  $o */
    private function product(int $id, string $name, array $o = []): int
    {
        $made = $this->makeProduct($id, $o + ['name' => $name, 'slug' => "p-{$id}", 'categories' => [1], 'price' => '10.00', 'stock' => 3]);
        // the stored sort price and availability (what the admin save keeps): the listing and the search read them
        app(\App\Services\PriceSort\ProductSortPriceRefresher::class)->refresh([$id]);

        return $made;
    }

    /**
     * The slugs of the product cards on a page, in order.
     *
     * @return list<string>
     */
    private function slugs(string $url): array
    {
        $html = $this->get($url)->assertOk()->getContent();
        $grid = strstr($html, 'class="sf-grid"') ?: '';
        preg_match_all('#href="/product/([^"/]+)"#u', $grid, $m);

        return array_values(array_unique($m[1]));
    }

    // ---- matching ------------------------------------------------------------------------------------------------------------

    public function test_it_finds_by_name_product_sku_variation_sku_and_brand(): void
    {
        $this->makeBrand(1, 'Zara', 'zara');
        $this->product(1, 'Червена рокля');
        $this->product(2, 'Синя риза', ['variations' => [['sku' => 'VAR-777', 'price' => '10.00', 'stock' => 2]]]);
        $this->product(3, 'Шал', ['brand' => 1]);

        $this->assertSame(['p-1'], $this->slugs('/search?q='.urlencode('рокля')));
        $this->assertSame(['p-1'], $this->slugs('/search?q=BASE-1'), 'the product SKU');
        $this->assertSame(['p-2'], $this->slugs('/search?q=var-777'), 'a variation SKU, any case');
        $this->assertSame(['p-3'], $this->slugs('/search?q=zara'), 'the brand name');
    }

    public function test_the_cyrillic_word_finds_a_latin_slug_through_the_shops_transliteration(): void
    {
        $this->product(1, 'Модел А', ['slug' => 'roklya']);
        $this->product(2, 'Модел Б', ['slug' => 'riza']);

        $this->assertSame(['roklya'], $this->slugs('/search?q='.urlencode('рокля')), 'рокля -> roklya, found by the slug alone');
        $this->assertSame(['roklya'], $this->slugs('/search?q=roklya'), 'and the Latin word finds it too');
        $this->assertSame([], $this->slugs('/search?q=nothing'));
    }

    public function test_every_word_must_match_and_nothing_found_is_a_friendly_200(): void
    {
        $this->product(1, 'Червена рокля');
        $this->product(2, 'Синя рокля');

        $this->assertEqualsCanonicalizing(['p-1', 'p-2'], $this->slugs('/search?q='.urlencode('рокля')));
        $this->assertSame(['p-1'], $this->slugs('/search?q='.urlencode('червена рокля')), 'AND between words');
        $this->assertSame([], $this->slugs('/search?q='.urlencode('червена риза')));

        $html = $this->get('/search?q='.urlencode('червена риза'))->assertOk()->getContent();
        $this->assertStringContainsString('Няма резултати за „червена риза“. Проверете изписването или опитайте с по-обща дума.', $html);
        $this->assertStringContainsString('role="search"', $html, 'the field again');
    }

    public function test_a_percent_or_underscore_is_a_character_not_a_wildcard(): void
    {
        $this->product(1, 'Намаление 50% днес');
        $this->product(2, 'Обикновен продукт');

        $this->assertSame(['p-1'], $this->slugs('/search?q='.urlencode('50%')));
        $this->assertSame([], $this->slugs('/search?q='.urlencode('__')), 'underscores do not match everything');
        $this->assertSame([], $this->slugs('/search?q='.urlencode('%%')));
    }

    // ---- visibility: the category listing's own rules --------------------------------------------------------------------------

    public function test_it_shows_exactly_what_a_category_listing_shows(): void
    {
        $this->product(1, 'Тест видим');
        $this->product(2, 'Тест неактивен', ['status' => 'draft']);
        $this->product(3, 'Тест скрит', ['visibility' => 'hidden']);
        $this->product(4, 'Тест изтрит', ['deleted' => true]);
        $this->product(5, 'Тест изчерпан', ['stock' => 0]);

        $this->assertSame(['p-1'], $this->slugs('/search?q='.urlencode('тест')), 'inactive, hidden, deleted and (the shop hides sold-out products by default) sold-out are not found');
        $this->assertSame(['p-1'], $this->slugs('/product-category/drehi'), 'the category page lists the same');

        $this->showSoldOutProducts();
        $this->assertEqualsCanonicalizing(['p-1', 'p-5'], $this->slugs('/search?q='.urlencode('тест')), 'with the setting off the sold-out product is found, as it is listed');
        $this->assertEqualsCanonicalizing(['p-1', 'p-5'], $this->slugs('/product-category/drehi'));
    }

    // ---- rank, sorts, pagination -------------------------------------------------------------------------------------------------

    public function test_rank_is_exact_sku_then_name_starts_with_then_contains_then_the_rest(): void
    {
        $this->product(1, 'Дълга рокля лято', ['timeline' => '2026-03-01 10:00:00']);   // name contains
        $this->product(2, 'Рокля лятна', ['timeline' => '2026-01-01 10:00:00']);          // name starts with
        $this->product(3, 'Лек шал', ['timeline' => '2026-04-01 10:00:00', 'variations' => [['sku' => 'рокля', 'price' => '10.00', 'stock' => 1]]]); // exact SKU
        $this->product(4, 'Нещо друго', ['slug' => 'roklya-drugo', 'timeline' => '2026-05-01 10:00:00']);   // slug only (rank 3: the rest)

        $this->assertSame(['p-3', 'p-2', 'p-1', 'roklya-drugo'], $this->slugs('/search?q='.urlencode('рокля')), 'exact SKU, starts-with, contains, then the rest');
    }

    public function test_the_other_sorts_work_and_pagination_keeps_the_query(): void
    {
        $this->product(1, 'Пудра първа', ['price' => '30.00', 'timeline' => '2026-01-01 10:00:00']);
        $this->product(2, 'Пудра втора', ['price' => '10.00', 'timeline' => '2026-02-01 10:00:00']);
        $this->product(3, 'Пудра трета', ['price' => '20.00', 'timeline' => '2026-03-01 10:00:00']);

        $q = urlencode('пудра');
        $this->assertSame(['p-3', 'p-2', 'p-1'], $this->slugs("/search?q={$q}&sort=newest"));
        $this->assertSame(['p-2', 'p-3', 'p-1'], $this->slugs("/search?q={$q}&sort=price_asc"));
        $this->assertSame(['p-1', 'p-3', 'p-2'], $this->slugs("/search?q={$q}&sort=price_desc"));
        $this->get("/search?q={$q}&sort=name_asc")->assertNotFound();
        $this->get("/search?q={$q}&sort[]=x")->assertNotFound();

        for ($i = 4; $i <= 30; $i++) {
            $this->product($i, "Пудра номер {$i}", ['timeline' => sprintf('2026-01-01 11:%02d:00', $i)]);
        }

        $html = $this->get("/search?q={$q}")->assertOk()->getContent();
        $this->assertStringContainsString('href="/search?q=%D0%BF%D1%83%D0%B4%D1%80%D0%B0&amp;page=2"', $html, 'the next page keeps q');
        $this->assertStringContainsString('Брой продукти: 30', $html);

        $sorted = $this->get("/search?q={$q}&sort=price_asc")->assertOk()->getContent();
        $this->assertStringContainsString('href="/search?q=%D0%BF%D1%83%D0%B4%D1%80%D0%B0&amp;sort=price_asc&amp;page=2"', $sorted, 'and the sort');
        $this->assertStringContainsString('name="q" value="пудра"', $sorted, 'the sort form keeps q too');

        $this->get("/search?q={$q}&page=2")->assertOk();
        $this->get("/search?q={$q}&page=3")->assertNotFound();
        $this->get("/search?q={$q}&page=abc")->assertNotFound();
    }

    // ---- cost -------------------------------------------------------------------------------------------------------------------

    public function test_the_number_of_statements_does_not_depend_on_results_or_words(): void
    {
        $this->product(1, 'Алфа бета гама делта');
        $four = urlencode('алфа бета гама делта');
        $one = $this->statements('/search?q=алфа');
        $oneWord = $this->statements('/search?q='.urlencode('алфа бета'));
        $fourWords = $this->statements("/search?q={$four}");

        for ($i = 2; $i <= 40; $i++) {
            $this->product($i, "Алфа бета гама делта {$i}");
        }

        $many = $this->statements('/search?q=алфа');
        $manyFour = $this->statements("/search?q={$four}");

        fwrite(STDERR, "\n[query-count] search results page: 1 result = {$one}, 40 results = {$many}, 1 word = {$one}, 2 words = {$oneWord}, 4 words = {$fourWords}\n");
        $this->assertSame($one, $oneWord);
        $this->assertSame($one, $fourWords, '1 vs 4 words: the same number of statements');
        $this->assertSame($one, $many, '1 vs 40 results: the same number of statements');
        $this->assertSame($one, $manyFour);
        $this->assertLessThanOrEqual(24, $many, 'the pin of the results page: 24 statements, one more than the category page (23): the count and the page of ids, then the same card read');
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

    // ---- the page itself ---------------------------------------------------------------------------------------------------------

    public function test_the_query_is_escaped_everywhere_and_the_page_is_noindex(): void
    {
        $this->product(1, 'Обикновен');
        $evil = '<script>alert("x")</script> "quoted" \'single\'';

        $response = $this->get('/search?q='.urlencode($evil));
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $html);
        $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', $html);
        $this->assertStringNotContainsString('rel="canonical"', $html);
        $this->assertSame('noindex, follow', $response->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('<title>Резултати за „&lt;script&gt;', $html);
    }

    public function test_an_empty_or_too_short_query_is_a_friendly_200_and_a_single_character_does_not_search(): void
    {
        $this->product(1, 'Яко');

        $empty = $this->get('/search')->assertOk()->getContent();
        $this->assertStringContainsString('Въведете дума за търсене.', $empty);
        $this->assertStringContainsString('role="search"', $empty);

        $short = $this->get('/search?q='.urlencode('я'))->assertOk()->getContent();
        $this->assertStringContainsString('Въведете поне 2 символа.', $short);
        $this->assertStringNotContainsString('/product/p-1', $short, 'one character searches nothing');

        $this->get('/search?q[]=x')->assertOk();
        $this->get('/search?q=')->assertOk();
    }

    public function test_the_page_looks_like_a_category_page(): void
    {
        $this->product(1, 'Рокля');
        $html = $this->get('/search?q='.urlencode('рокля'))->assertOk()->getContent();

        foreach (['sf-listing sf-category', 'class="sf-grid"', 'sf-toolbar', 'Най-подходящи', 'Цена: от ниска към висока', 'Резултати за „рокля“'] as $part) {
            $this->assertStringContainsString($part, $html);
        }
    }

    public function test_english_texts(): void
    {
        app()->setLocale('en');
        $this->product(1, 'Dress');

        $this->assertStringContainsString('Results for “dress”', $this->get('/search?q=dress')->getContent());
        $this->assertStringContainsString('No results for “zzz”. Check the spelling', $this->get('/search?q=zzz')->getContent());
    }

    // ---- switch, rate limit, swap ------------------------------------------------------------------------------------------------

    public function test_while_search_is_off_the_route_is_the_storefronts_404(): void
    {
        config(['storefront.search.enabled' => false]);

        $this->get('/search?q=test')->assertNotFound();
    }

    public function test_the_rate_limit_answers_429_after_the_limit_and_a_request_without_q_is_free(): void
    {
        config(['storefront.search.limit' => 3, 'storefront.search.decay_seconds' => 60]);

        for ($i = 0; $i < 3; $i++) {
            $this->get('/search?q=abc')->assertOk();
        }

        $limited = $this->get('/search?q=abc');
        $limited->assertStatus(429);
        $this->assertNotNull($limited->headers->get('Retry-After'));
        $this->assertSame('noindex', $limited->headers->get('X-Robots-Tag'));
        $this->get('/search')->assertOk();
    }

    public function test_the_storefront_uses_the_interface_so_another_engine_can_replace_it(): void
    {
        $this->product(1, 'Първи');
        $this->product(2, 'Втори');

        $fake = new class implements ProductSearch
        {
            public ?SearchQuery $seen = null;

            public function search(SearchQuery $query, SearchSort $sort, int $page, int $perPage): SearchResult
            {
                $this->seen = $query;

                return new SearchResult([2], 1, 1, $perPage, 1);
            }
        };
        $this->app->instance(ProductSearch::class, $fake);

        $this->assertSame(['p-2'], $this->slugs('/search?q=whatever'), 'the page shows what the bound engine returned');
        $this->assertSame('whatever', $fake->seen?->text);
    }

    public function test_an_engine_that_returns_a_hidden_id_cannot_leak_it(): void
    {
        $this->product(1, 'Видим');
        $this->product(2, 'Скрит', ['visibility' => 'hidden']);

        $this->app->instance(ProductSearch::class, new class implements ProductSearch
        {
            public function search(SearchQuery $query, SearchSort $sort, int $page, int $perPage): SearchResult
            {
                return new SearchResult([2, 1], 2, 1, $perPage, 1);
            }
        });

        $this->assertSame(['p-1'], $this->slugs('/search?q=whatever'), 'the stale id is dropped when the cards are read');
    }

    public function test_the_default_binding_is_the_database_engine(): void
    {
        $this->assertInstanceOf(DatabaseProductSearch::class, app(ProductSearch::class));
    }
}
