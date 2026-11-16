<?php

namespace Tests\Feature\Storefront\Cache;

use App\Services\CatalogDeletion;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\Persistence\Eloquent\EloquentSiteSettingsRepository;
use App\Storefront\Badges\BadgeMatchMemo;
use App\Storefront\Cache\CachePurgeCollector;
use App\Storefront\Cache\CachePurger;
use App\Storefront\Cache\RecordingPurger;
use EasyCo\Catalog\Persistence\Eloquent\BrandModel;
use EasyCo\Catalog\Persistence\Eloquent\CategoryModel;
use EasyCo\Catalog\Persistence\Eloquent\CollectionModel;
use EasyCo\Catalog\Persistence\Eloquent\SeasonModel;
use EasyCo\Catalog\Persistence\Eloquent\TagModel;
use EasyCo\Extensibility\Hook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** P8b review fixes: subject deletes purge `all`, measures and templates purge `all`, a product delete keeps its subjects, one terminating hook per process, the settings exclusion list. */
class ReviewFixesTest extends TestCase
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

    /** @return list<string> */
    private function purged(callable $change): array
    {
        app(CachePurgeCollector::class)->reset();
        $this->purger->batches = [];
        $change();
        app(CachePurgeCollector::class)->flush();

        return $this->purger->keys();
    }

    /** the names of subjects are in the sidebar, the menus and the tiles of every page: a DELETE purges `all` as well as a save */
    public function test_deleting_a_subject_purges_all(): void
    {
        DB::table('catalog_product_categories')->where('category_id', 2)->delete();
        DB::table('catalog_categories')->where('parent_id', 2)->delete();

        foreach ([
            'category' => fn () => CategoryModel::query()->findOrFail(2)->delete(),
            'tag' => fn () => TagModel::query()->findOrFail(1)->delete(),
            'collection' => fn () => CollectionModel::query()->findOrFail(1)->delete(),
        ] as $name => $delete) {
            DB::table('catalog_product_tags')->when($name === 'tag', fn ($q) => $q->delete());
            DB::table('catalog_product_collections')->when($name === 'collection', fn ($q) => $q->delete());
            $this->assertContains('all', $this->purged($delete), "{$name} delete");
        }

        DB::table('catalog_products')->update(['brand_id' => null, 'season_id' => null]);
        $this->assertContains('all', $this->purged(fn () => BrandModel::query()->findOrFail(1)->delete()), 'brand delete');
        $this->assertContains('all', $this->purged(fn () => SeasonModel::query()->findOrFail(1)->delete()), 'season delete');
    }

    /** ★ a measure or a measurement template: their names are printed in the size tables */
    public function test_measures_and_measurement_templates_purge_all(): void
    {
        foreach (['catalog.measure.saved', 'catalog.measure.archived', 'catalog.measure.deleted', 'catalog.measurement_template.saved', 'catalog.measurement_template.archived', 'catalog.measurement_template.deleted'] as $hook) {
            $this->assertSame(['all'], $this->purged(fn () => Hook::fire($hook)), $hook);
        }
    }

    /** ★ the subjects of a DELETED product are read before its pivot rows go: its category and the ancestor, tag, collection, brand, season */
    public function test_a_product_delete_keeps_its_subjects(): void
    {
        DB::table('catalog_products')->where('id', 1)->update(['status' => 'archived']);
        DB::table('stock_levels')->where('variation_id', 101)->update(['quantity' => 0]);

        $keys = $this->purged(fn () => app(CatalogDeletion::class)->deleteProduct('1'));

        foreach (['p1', 'home', 'sitemap', 'c2', 'c1', 't1', 'col1', 'b1', 's1'] as $key) {
            $this->assertContains($key, $keys, "{$key} is missing from [".implode(' ', $keys).']');
        }

        $this->assertSame(0, DB::table('catalog_product_categories')->where('product_id', 1)->count(), 'the pivot rows really were gone at flush time');
    }

    public function test_a_product_delete_reads_its_subjects_with_three_statements(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(CachePurgeCollector::class)->addSubjectsOf([1, 2, 3]);
        $statements = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(3, $statements);
    }

    /** ★ ONE terminating callback per process: fresh scoped collectors and memos do not register their own */
    public function test_scoped_collectors_do_not_grow_the_terminating_callbacks(): void
    {
        $count = fn (): int => count((new \ReflectionProperty(\Illuminate\Foundation\Application::class, 'terminatingCallbacks'))->getValue($this->app));

        $before = $count();

        for ($i = 0; $i < 50; $i++) {
            $this->fresh();
            app(CachePurgeCollector::class)->add('p'.$i);
            app(BadgeMatchMemo::class)->begin();
        }

        $this->assertSame($before, $count(), 'the number of terminating callbacks must not grow with the number of scoped instances');
        $this->assertGreaterThan(0, $before, 'the ONE callback is registered at boot');
    }

    /** the terminating callback flushes the CURRENT collector and ends the CURRENT memo */
    public function test_the_one_terminating_callback_flushes_the_current_collector(): void
    {
        $this->fresh();
        app(CachePurgeCollector::class)->add('home');
        app(BadgeMatchMemo::class)->begin();

        $this->app->terminate();

        $this->assertContains('home', $this->purger->keys());
        $this->assertFalse(app(BadgeMatchMemo::class)->active);
    }

    /** ★ keys a visitor never sees collect nothing; every other key purges `all` */
    public function test_invisible_settings_keys_collect_nothing_and_visible_ones_collect_all(): void
    {
        $settings = app(SiteSettingsRepository::class);

        foreach (['mail.from_name', 'header.layout.draft', 'media.html_block_import', 'content.drafts_seeded', 'invoice.enabled', 'privacy.consent_mode', 'admin.activity_log_enabled', 'payment.bank_transfer.iban'] as $key) {
            $this->assertSame([], $this->purged(fn () => $settings->set($key, '1')), "{$key} must not purge");
            $this->assertSame([], $this->purged(fn () => $settings->forget($key)), "{$key} (forget) must not purge");
        }

        foreach (['catalog.hide_sold_out', 'site.locale', 'design.header.compiled'] as $key) {
            $this->assertSame(['all'], $this->purged(fn () => $settings->set($key, '1')), "{$key} purges all");
        }

        $this->assertNotSame([], EloquentSiteSettingsRepository::INVISIBLE);
    }
}
