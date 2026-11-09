<?php

namespace Tests\Feature\Storefront;

use App\Console\Commands\RefreshPriceBoundaries;
use App\Jobs\RefreshProductSortPrices;
use Carbon\CarbonImmutable;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Contracts\PriceListScopeRepository;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Enums\PriceListScopeType;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P6a, the TIME BOUNDARIES: `prices:refresh-boundaries` (every minute) makes ONE query when no price list starts or ends in the window since the last run, refreshes only the products a crossing list can touch,
 * processes a boundary exactly once, never involves a list without dates, and runs the full pass when its stored «last moment» is missing or older than a day.
 */
class PriceSortBoundariesTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    private CarbonImmutable $now;

    private int $listCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPricing();
        $this->now = CarbonImmutable::parse('2026-12-01 12:00:00');
        $this->travelTo($this->now);

        $this->makeBrand(1, 'Acme', 'acme');
        $this->makeProduct(1, ['slug' => 'acme-one', 'brand' => 1, 'price' => '100.00', 'stock' => 1]);
        $this->makeProduct(2, ['slug' => 'acme-two', 'brand' => 1, 'price' => '50.00', 'stock' => 1]);
        $this->makeProduct(3, ['slug' => 'other', 'price' => '80.00', 'stock' => 1]);
    }

    private function last(CarbonImmutable $moment): void
    {
        Cache::forever(RefreshPriceBoundaries::LAST_KEY, $moment->toIso8601String());
    }

    /** A percentage list scoped to the brand (or unscoped), with the given dates; made inactive when asked. */
    private function list(string $name, ?CarbonImmutable $from, ?CarbonImmutable $until, bool $brandScope = true, bool $active = true): PriceList
    {
        // one priority per list: (priority, scope) is unique
        $list = PriceList::create($name, PriceListMode::PERCENTAGE_OFF_REGULAR, priority: 2000 + ++$this->listCounter, validFrom: $from?->toDateTimeImmutable(), validUntil: $until?->toDateTimeImmutable(), percentageBasisPoints: 2000);
        $active || $list->deactivate();
        app(PriceListRepository::class)->save($list);
        $brandScope && app(PriceListScopeRepository::class)->attach(new PriceListScope(null, $list->id(), PriceListScopeType::BRAND, '1'));

        return $list;
    }

    private function runCommand(): string
    {
        Artisan::call('prices:refresh-boundaries');

        return Artisan::output();
    }

    /** @return list<list<string>> the product sets of the queued jobs */
    private function pushedSets(): array
    {
        return Queue::pushed(RefreshProductSortPrices::class)->map(fn ($job) => $job->productIds)->values()->all();
    }

    /** ★ with no list boundary in the window the command runs ONE query and dispatches nothing */
    public function test_with_no_boundary_in_the_window_it_runs_one_query_and_dispatches_nothing(): void
    {
        $this->list('Far future', $this->now->addDays(10), $this->now->addDays(20));
        $this->list('Undated', null, null);
        $this->list('Long over', $this->now->subDays(5), $this->now->subDays(3));
        $this->last($this->now->subSeconds(30));
        Queue::fake();

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->runCommand();

        $this->assertCount(1, $queries, implode(' || ', $queries));
        $this->assertStringContainsString('pricing_price_lists', $queries[0]);
        Queue::assertNothingPushed();
    }

    /** ★ a list that STARTS in the window refreshes only the products in its scope */
    public function test_a_list_that_starts_in_the_window_refreshes_only_its_scope(): void
    {
        $this->list('Starts now', $this->now->subSeconds(20), null);
        $this->last($this->now->subSeconds(60));
        Queue::fake();

        $this->runCommand();

        $this->assertSame([['1', '2']], $this->pushedSets(), 'the brand products only, not «other»');
    }

    /** ★ a list that ENDS in the window refreshes only its scope too */
    public function test_a_list_that_ends_in_the_window_refreshes_only_its_scope(): void
    {
        $this->list('Ends now', $this->now->subDays(2), $this->now->subSeconds(20));
        $this->last($this->now->subSeconds(60));
        Queue::fake();

        $this->runCommand();

        $this->assertSame([['1', '2']], $this->pushedSets());
    }

    /** a list with NO scope that crosses a boundary can touch every product */
    public function test_an_unscoped_list_crossing_a_boundary_queues_every_product(): void
    {
        $this->list('Everything', $this->now->subSeconds(20), null, brandScope: false);
        $this->last($this->now->subSeconds(60));
        Queue::fake();

        $this->runCommand();

        $this->assertSame([['1', '2', '3']], $this->pushedSets());
    }

    /** ★ a boundary is processed exactly once: the second run dispatches nothing */
    public function test_a_boundary_is_processed_exactly_once(): void
    {
        $this->list('Starts now', $this->now->subSeconds(20), null);
        $this->last($this->now->subSeconds(60));
        Queue::fake();

        $this->runCommand();
        $this->assertCount(1, $this->pushedSets());

        $this->travelTo($this->now->addMinute());
        $this->runCommand();

        $this->assertCount(1, $this->pushedSets(), 'the second run found nothing new');
    }

    /** the window is closed at the moment BEFORE the dispatch: the stored moment is the start of this run */
    public function test_the_window_is_closed_at_the_moment_before_the_dispatch(): void
    {
        $this->list('Starts now', $this->now->subSeconds(20), null);
        $this->last($this->now->subSeconds(60));
        Queue::fake();

        $this->runCommand();

        $this->assertSame($this->now->toIso8601String(), Cache::get(RefreshPriceBoundaries::LAST_KEY));
    }

    /** ★ a list WITHOUT dates never takes part (and neither does an inactive one) */
    public function test_a_list_without_dates_and_an_inactive_list_never_take_part(): void
    {
        $this->list('Undated', null, null);
        $this->list('Inactive but starting', $this->now->subSeconds(20), null, active: false);
        $this->last($this->now->subSeconds(60));
        Queue::fake();

        $this->runCommand();

        Queue::assertNothingPushed();
    }

    /** ★ a stored «last moment» that is missing, or older than a day, runs the FULL pass (every product, in chunks) */
    public function test_a_missing_or_stale_last_moment_triggers_the_full_pass(): void
    {
        Queue::fake();

        Cache::forget(RefreshPriceBoundaries::LAST_KEY);
        $this->assertStringContainsString('full pass', $this->runCommand());
        $this->assertSame([['1', '2', '3']], $this->pushedSets());
        $this->assertSame($this->now->toIso8601String(), Cache::get(RefreshPriceBoundaries::LAST_KEY));

        $this->last($this->now->subDays(2));
        Queue::fake();
        $this->assertStringContainsString('full pass', $this->runCommand());
        $this->assertSame([['1', '2', '3']], $this->pushedSets());

        // and a last moment of a minute ago is NOT stale
        $this->last($this->now->subMinute());
        Queue::fake();
        $this->runCommand();
        Queue::assertNothingPushed();
    }

    /** ★ the real effect: a list that crossed its start in the window is applied by the command (the queue is sync) */
    public function test_a_list_that_starts_now_is_applied_when_the_command_runs(): void
    {
        // «now» of the resolver is the real clock; the list started 20 seconds ago, and the values are corrupt: only this command can fix them
        $this->travelBack();
        $real = CarbonImmutable::now();
        $list = PriceList::create('Started', PriceListMode::PERCENTAGE_OFF_REGULAR, priority: 2000, validFrom: $real->subSeconds(20)->toDateTimeImmutable(), percentageBasisPoints: 2000);
        app(PriceListRepository::class)->save($list);
        app(PriceListScopeRepository::class)->attach(new PriceListScope(null, $list->id(), PriceListScopeType::BRAND, '1'));

        DB::table('catalog_products')->update(['price_from_minor' => 999]);
        Cache::forever(RefreshPriceBoundaries::LAST_KEY, CarbonImmutable::now()->subSeconds(60)->toIso8601String());

        $this->runCommand();

        $this->assertSame(8000, (int) DB::table('catalog_products')->where('slug', 'acme-one')->value('price_from_minor'), '100.00 -20%');
        $this->assertSame(4000, (int) DB::table('catalog_products')->where('slug', 'acme-two')->value('price_from_minor'));
        $this->assertSame(999, (int) DB::table('catalog_products')->where('slug', 'other')->value('price_from_minor'), 'outside the scope: not touched');
    }

    /** a list that has ALREADY ended is ignored by the resolver: running the command for its end leaves the regular price */
    public function test_a_list_that_already_ended_is_ignored(): void
    {
        $this->travelBack();
        $real = CarbonImmutable::now();
        $list = PriceList::create('Ended', PriceListMode::PERCENTAGE_OFF_REGULAR, priority: 2000, validFrom: $real->subDays(2)->toDateTimeImmutable(), validUntil: $real->subSeconds(20)->toDateTimeImmutable(), percentageBasisPoints: 5000);
        app(PriceListRepository::class)->save($list);
        app(PriceListScopeRepository::class)->attach(new PriceListScope(null, $list->id(), PriceListScopeType::BRAND, '1'));

        DB::table('catalog_products')->update(['price_from_minor' => 999]);
        Cache::forever(RefreshPriceBoundaries::LAST_KEY, CarbonImmutable::now()->subSeconds(60)->toIso8601String());

        $this->runCommand();

        $this->assertSame(10000, (int) DB::table('catalog_products')->where('slug', 'acme-one')->value('price_from_minor'), 'the ended list no longer applies');
    }
}
