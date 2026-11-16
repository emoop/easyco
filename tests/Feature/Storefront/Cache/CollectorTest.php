<?php

namespace Tests\Feature\Storefront\Cache;

use App\Content\Models\PageModel;
use App\Jobs\PurgeCacheKeys;
use App\Storefront\Cache\CachePurgeCollector;
use App\Storefront\Cache\CachePurger;
use App\Storefront\Cache\NullPurger;
use App\Storefront\Cache\RecordingPurger;
use App\Storefront\Cache\VarnishPurger;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** P8b: the collector (once per request, de-duplicated, batches), the purgers, the retrying job and the version keys of the Laravel-side caches. */
class CollectorTest extends TestCase
{
    use BuildsCacheShop;
    use RefreshDatabase;

    private RecordingPurger $purger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop();
        $this->purger = new RecordingPurger;
        $this->app->instance(CachePurger::class, $this->purger);
        app(CachePurgeCollector::class)->reset();
    }

    public function test_keys_are_de_duplicated_and_sent_in_one_batch(): void
    {
        $collector = app(CachePurgeCollector::class);
        $collector->add('all', 'home', 'all');
        $collector->add('home', 'p5');
        $collector->addProducts([5, '5']);
        $collector->flush();

        $this->assertCount(1, $this->purger->batches);
        $this->assertSame(['all', 'home', 'p5', 'sitemap'], $this->purger->keys());
    }

    public function test_batches_hold_at_most_100_keys(): void
    {
        $collector = app(CachePurgeCollector::class);
        $collector->addProducts(range(1, 250)); // 250 products + home + sitemap = 252 keys
        $collector->flush();

        $this->assertCount(3, $this->purger->batches);
        $sizes = array_map('count', $this->purger->batches);
        $this->assertSame([100, 100], array_slice($sizes, 0, 2));
        $this->assertLessThanOrEqual(100, $sizes[2]);
        $this->assertGreaterThanOrEqual(252, array_sum($sizes), '250 products + home + sitemap, plus the subjects of the products');
    }

    /** ★ ONE flush per request, however many writes: many saves, one batch; a second flush has nothing to send */
    public function test_many_writes_make_one_flush(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            ProductModel::query()->findOrFail(2)->update(['name' => "Beta {$i}"]);
        }

        $this->assertSame([], $this->purger->batches, 'nothing is sent before the flush');

        app(CachePurgeCollector::class)->flush();
        app(CachePurgeCollector::class)->flush();

        $this->assertCount(1, $this->purger->batches);
    }

    public function test_the_null_purger_dispatches_no_job_and_resolves_no_subject(): void
    {
        Queue::fake();
        $this->app->instance(CachePurger::class, new NullPurger);

        DB::enableQueryLog();
        $collector = app(CachePurgeCollector::class);
        $collector->addProducts([1, 2, 3]);
        $collector->add('all', 'sitemap');
        $collector->flush();
        $queries = array_filter(DB::getQueryLog(), static fn (array $q): bool => str_contains($q['query'], 'catalog_'));
        DB::disableQueryLog();

        Queue::assertNothingPushed();
        $this->assertSame([], array_values($queries), 'no subject is read from the database for a purger that does nothing');
    }

    public function test_a_throwing_purger_is_logged_and_the_write_stands(): void
    {
        Log::spy();
        $this->purger->fail = true;

        ProductModel::query()->findOrFail(2)->update(['name' => 'Beta survives']);
        app(CachePurgeCollector::class)->flush(); // the sync queue runs the job, whose purger throws: the collector contains it

        $this->assertSame('Beta survives', DB::table('catalog_products')->where('id', 2)->value('name'));
        Log::shouldHaveReceived('error')->atLeast()->once();
    }

    public function test_the_job_retries_three_times_with_a_backoff_and_logs_its_failure(): void
    {
        $job = new PurgeCacheKeys(['p1']);

        $this->assertSame(3, $job->tries);
        $this->assertSame([5, 30], $job->backoff());

        $this->purger->fail = true;
        try {
            $job->handle($this->purger);
            $this->fail('a failing purge throws, so the queue retries');
        } catch (\RuntimeException) {
            $this->assertTrue(true);
        }

        Log::spy();
        $job->failed(new \RuntimeException('down'));
        Log::shouldHaveReceived('error')->once();
    }

    public function test_the_varnish_purger_sends_a_purge_with_the_xkey_softpurge_header_to_the_configured_internal_url(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        config(['cache_purge.purger' => 'varnish', 'cache_purge.url' => 'http://varnish-internal', 'cache_purge.timeout' => 3]);
        $this->app->forgetInstance(CachePurger::class);

        $purger = app(CachePurger::class);
        $this->assertInstanceOf(VarnishPurger::class, $purger);
        $purger->purge(['p1', 'home']);

        Http::assertSent(fn ($request): bool => $request->method() === 'PURGE'
            && $request->url() === 'http://varnish-internal'
            && $request->header('xkey-softpurge') === ['p1 home']);

        $timeout = (new \ReflectionProperty(VarnishPurger::class, 'timeout'))->getValue($purger);
        $this->assertSame(3, $timeout);
    }

    public function test_the_varnish_purger_throws_on_a_failed_answer(): void
    {
        Http::fake(['*' => Http::response('', 503)]);

        $this->expectException(\RuntimeException::class);
        (new VarnishPurger('http://varnish', 3))->purge(['all']);
    }

    public function test_the_default_purger_is_the_null_purger(): void
    {
        config(['cache_purge.purger' => 'null']);
        $this->app->forgetInstance(CachePurger::class);

        $this->assertInstanceOf(NullPurger::class, app(CachePurger::class));
        $this->assertSame('null', config('cache_purge.purger'));
    }

    // ---- the version keys of the Laravel-side caches ---------------------------------------------------------------------------------------

    public function test_a_sitemap_purge_changes_the_sitemap_cache_without_waiting_for_the_ttl(): void
    {
        $this->fresh();
        $this->assertStringNotContainsString('/info/second', $this->get('/sitemap/pages.xml')->getContent());

        PageModel::query()->create(['slug' => 'second', 'title' => 'Second', 'body' => '<p>2</p>', 'status' => 'published', 'revision' => 1]);
        app(CachePurgeCollector::class)->flush();

        $this->fresh();
        $this->assertStringContainsString('/info/second', $this->get('/sitemap/pages.xml')->getContent(), 'the next sitemap reflects the page at once');
    }

    public function test_a_product_save_makes_the_next_products_sitemap_reflect_it(): void
    {
        $this->fresh();
        $this->assertStringContainsString('/product/beta', $this->get('/sitemap/products-1.xml')->getContent());

        ProductModel::query()->findOrFail(2)->update(['status' => 'draft']);
        app(CachePurgeCollector::class)->flush();

        $this->fresh();
        $this->assertStringNotContainsString('/product/beta', $this->get('/sitemap/products-1.xml')->getContent());
    }

    public function test_a_page_view_collects_nothing(): void
    {
        foreach (['/', '/product/alpha', '/product-category/clothing', '/brand/acme', '/info/about', '/robots.txt', '/sitemap.xml'] as $url) {
            $this->fresh();
            $this->get($url)->assertOk();
            $this->assertSame([], app(CachePurgeCollector::class)->pendingKeys(), "reading {$url} must not collect a purge");
        }
    }
}
